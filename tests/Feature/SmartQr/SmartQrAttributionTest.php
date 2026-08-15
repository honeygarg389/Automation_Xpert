<?php

namespace Tests\Feature\SmartQr;

use App\Events\MessageReceived;
use App\Models\Workspace;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ContactService;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrAttributionSession;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Models\SmartQrConversionEvent;
use App\Modules\SmartQr\Services\SmartQrAttribution;
use App\Modules\SmartQr\Support\SmartQrStatus;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Slice 5 — §9's attribution loop.
 *
 * ─── ⚠️ THE FOUR TESTS THIS FILE EXISTS FOR ─────────────────────────────────
 *
 *   tenant mismatch     a token from tenant A arriving in tenant B's inbox
 *                       attributes NOTHING
 *   token stripped      no reference means no attribution, and no guessing
 *   token reused        the same token in three messages is ONE attribution
 *   failed session write the redirect still 302s when attribution cannot be
 *                       written — an implementation that lets the write break
 *                       the redirect passes every other test here
 *
 * Everything else is ordinary coverage of the counting rules.
 */
class SmartQrAttributionTest extends TestCase
{
    use RefreshDatabase;

    private function attribution(): SmartQrAttribution
    {
        return app(SmartQrAttribution::class);
    }

    /**
     * A workspace holding one live QR, with a dialable number.
     *
     * @return array{workspace: Workspace, code: SmartQrCode, assignment: SmartQrAssignment, channel: ChannelAccount}
     */
    private function tenantWithQr(): array
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $phoneNumberId = 'PN-'.uniqid();

        $channel = ChannelAccount::withoutWorkspaceScope('reason: test fixture')->create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'display_name' => 'Line',
            'phone_number_id' => $phoneNumberId,
            'status' => 'active',
        ]);

        $waba = WhatsappBusinessAccount::create([
            'workspace_id' => $workspace->id,
            'waba_id' => 'WABA-'.uniqid(),
            'name' => 'Test WABA',
        ]);

        WhatsappPhoneNumber::create([
            'waba_id_fk' => $waba->id,
            'phone_number_id' => $phoneNumberId,
            'display_phone' => '+15550100001',
        ]);

        $code = SmartQrCode::factory()->create();

        $assignment = SmartQrAssignment::factory()->create([
            'smart_qr_code_id' => $code->id,
            'workspace_id' => $workspace->id,
            'channel_account_id' => $channel->id,
            'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
            'default_message' => 'Hello from the counter',
        ]);

        return compact('workspace', 'code', 'assignment', 'channel');
    }

    /**
     * Simulate the inbound side the way the driver does: contact, conversation,
     * message, then MessageReceived — INSIDE the workspace context, exactly as
     * WhatsappDriver::persistInboundMessage() wraps it.
     */
    private function inbound(array $t, string $body, ?Contact $contact = null, ?Conversation $conversation = null): Message
    {
        $workspaceId = $t['workspace']->id;

        return WorkspaceContext::for($workspaceId, function () use ($t, $body, $workspaceId, $contact, $conversation) {
            $contact ??= app(ContactService::class)
                ->upsert($workspaceId, ['phone_e164' => '+15557654321', 'source' => 'whatsapp_inbound']);

            $conversation ??= Conversation::firstOrCreate(
                ['workspace_id' => $workspaceId, 'contact_id' => $contact->id, 'channel_account_id' => $t['channel']->id],
                ['status' => 'open', 'external_thread_id' => '15557654321']
            );

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'in',
                'channel' => 'whatsapp',
                'type' => 'text',
                'payload' => [],
                'body' => $body,
                'status' => 'delivered',
                'provider_message_id' => 'wamid.'.uniqid(),
                'sent_by' => 'human',
                'sent_at' => now(),
            ]);

            MessageReceived::dispatch($message);

            return $message;
        });
    }

    private function conversions(int $assignmentId, ?string $type = null): int
    {
        return (int) DB::table('smart_qr_conversion_events')
            ->where('smart_qr_assignment_id', $assignmentId)
            ->when($type, fn ($q) => $q->where('type', $type))
            ->count();
    }

    // ══ The loop, end to end ═══════════════════════════════════════════════

    /** The redirect appends a reference the customer can send back. */
    #[Test]
    public function the_redirect_issues_a_session_and_appends_its_reference(): void
    {
        $t = $this->tenantWithQr();

        $location = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone)'])
            ->get('/q/'.$t['code']->public_token)
            ->headers->get('Location');

        $session = SmartQrAttributionSession::firstOrFail();

        $this->assertSame($t['assignment']->id, $session->smart_qr_assignment_id);
        $this->assertStringContainsString(rawurlencode($session->token), $location,
            'The reference token is not in the wa.me link, so the customer has nothing to send '
            .'back and §9 cannot close.');
        $this->assertStringContainsString(rawurlencode('Hello from the counter'), $location,
            'The default message was lost when the reference was appended.');
    }

    /** …and the reply carrying it records a customer_messaged conversion. */
    #[Test]
    public function a_reply_carrying_the_reference_records_the_conversion(): void
    {
        $t = $this->tenantWithQr();
        $session = $this->attribution()->issue($t['assignment']->id);

        $message = $this->inbound($t, 'Hi there '.$this->attribution()->reference($session->token));

        $this->assertSame(1, $this->conversions($t['assignment']->id, SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED));

        $session->refresh();
        $this->assertNotNull($session->consumed_at, 'The session was not consumed.');
        $this->assertSame($message->id, $session->message_id);
    }

    // ══ ⚠️ STASH-CHECK TARGET 1 — the cross-tenant refusal ═════════════════

    /**
     * ⚠️ A token issued for tenant A, arriving in tenant B's inbox, attributes
     * NOTHING.
     *
     * This is the copied-sticker case, and the one where a mistake mixes real
     * customer data: attributing here would link tenant B's contact and
     * conversation to tenant A's QR, and tenant A would read another company's
     * customers as their own conversions.
     *
     * The discriminator is ZERO ROWS — not an exception, not a log line.
     */
    #[Test]
    public function a_token_from_another_tenant_attributes_nothing(): void
    {
        $a = $this->tenantWithQr();
        $b = $this->tenantWithQr();

        $session = $this->attribution()->issue($a['assignment']->id);

        // The message arrives in B's inbox carrying A's reference.
        $this->inbound($b, 'Hello '.$this->attribution()->reference($session->token));

        $this->assertSame(0, $this->conversions($a['assignment']->id),
            "Tenant A was credited with a conversion for a message that arrived in tenant B's "
            .'inbox. The workspace must come from the MESSAGE, never from the token — a token '
            .'must not be able to choose a tenant.');
        $this->assertSame(0, $this->conversions($b['assignment']->id));

        $this->assertNull($session->fresh()->consumed_at,
            "A's session was consumed by B's message, so the real customer's later reply would "
            .'find it already used and silently lose its attribution.');
    }

    /** POSITIVE CONTROL: the same token in its OWN tenant does attribute. */
    #[Test]
    public function the_same_token_in_its_own_tenant_does_attribute(): void
    {
        $a = $this->tenantWithQr();
        $session = $this->attribution()->issue($a['assignment']->id);

        $this->inbound($a, 'Hello '.$this->attribution()->reference($session->token));

        $this->assertSame(1, $this->conversions($a['assignment']->id, SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED),
            'Attribution refuses everyone, so the cross-tenant test above proves nothing.');
    }

    // ══ ⚠️ STASH-CHECK TARGET 2 — the token stripped ═══════════════════════

    /**
     * ⚠️ NO REFERENCE MEANS NO ATTRIBUTION, AND NO GUESSING.
     *
     * §9: when the customer removes the reference, exact attribution is
     * unavailable and **must not be faked**. This is the test guarding the
     * honesty of every number §10 reports.
     *
     * The tempting wrong implementation attributes by "this workspace had one
     * scan four minutes ago" — which credits the QR for a customer who scanned,
     * ignored it, and messaged an hour later from a business card.
     */
    #[Test]
    public function a_message_with_the_reference_stripped_attributes_nothing(): void
    {
        $t = $this->tenantWithQr();
        $session = $this->attribution()->issue($t['assignment']->id);

        // A scan happened moments ago; the customer deleted the reference.
        $this->inbound($t, 'Hi, do you have this in blue?');

        $this->assertSame(0, $this->conversions($t['assignment']->id),
            'A message with no reference was attributed anyway. That is the faked attribution '
            .'§9 forbids: nothing links this message to the scan except proximity in time.');
        $this->assertNull($session->fresh()->consumed_at);
    }

    /** …and the message itself is completely unaffected. */
    #[Test]
    public function an_unattributed_message_is_still_processed_normally(): void
    {
        $t = $this->tenantWithQr();

        $message = $this->inbound($t, 'Just a normal question');

        $this->assertDatabaseHas('messages', ['id' => $message->id, 'body' => 'Just a normal question']);
        $this->assertNotNull($message->conversation_id, 'The conversation was lost.');
    }

    // ══ ⚠️ STASH-CHECK TARGET 3 — the token reused ═════════════════════════

    /**
     * ⚠️ THREE messages, ONE token, ONE attribution.
     *
     * The first message is an attribution; the rest are the same customer
     * continuing to talk. A naive implementation counts three customers messaged
     * from one scan, and the tenant's conversion rate reads as 300%.
     */
    #[Test]
    public function the_same_token_in_three_messages_is_one_attribution(): void
    {
        $t = $this->tenantWithQr();
        $session = $this->attribution()->issue($t['assignment']->id);
        $ref = $this->attribution()->reference($session->token);

        $this->inbound($t, 'Hello '.$ref);
        $this->inbound($t, 'Are you there? '.$ref);
        $this->inbound($t, 'Still waiting '.$ref);

        $this->assertSame(1, $this->conversions($t['assignment']->id, SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED),
            'The same reference in three messages produced more than one attribution. The first '
            .'is a conversion; the rest are repeats.');
        $this->assertSame(3, (int) DB::table('messages')->count(), 'All three messages must still be stored.');
    }

    /** An expired token attributes nothing — the window is real. */
    #[Test]
    public function an_expired_token_attributes_nothing(): void
    {
        $t = $this->tenantWithQr();
        $session = $this->attribution()->issue($t['assignment']->id);

        $session->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->inbound($t, 'Hello '.$this->attribution()->reference($session->token));

        $this->assertSame(0, $this->conversions($t['assignment']->id));
    }

    /** The TTL comes from config, so it is tunable without a deploy. */
    #[Test]
    public function the_session_lifetime_comes_from_config(): void
    {
        config(['smartqr.attribution_ttl_minutes' => 90]);
        $t = $this->tenantWithQr();

        $session = $this->attribution()->issue($t['assignment']->id);

        $this->assertSame(90, (int) $session->issued_at->diffInMinutes($session->expires_at),
            'The lifetime ignored config. It must be tunable without a deploy.');
    }

    // ══ ⚠️ STASH-CHECK TARGET 4 — the redirect survives a failed write ═════

    /**
     * ⚠️ THE FAILURE RULE FROM SLICE 4, RE-PROVEN.
     *
     * Slice 5 puts a SYNCHRONOUS write on the redirect path — the token must be
     * durable before the customer can quote it. But slice 4's rule still holds:
     * a customer standing in a shop must reach WhatsApp even when attribution is
     * broken.
     *
     * An implementation that lets a failed session write break the redirect
     * passes every other test in this file.
     */
    #[Test]
    public function the_redirect_still_works_when_the_attribution_session_cannot_be_written(): void
    {
        $t = $this->tenantWithQr();

        // Make the write impossible in the most realistic way available: remove
        // the table the insert targets.
        Schema::drop('smart_qr_conversion_events');
        Schema::drop('smart_qr_attribution_sessions');

        $response = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone)'])
            ->get('/q/'.$t['code']->public_token);

        $response->assertStatus(302);
        $this->assertStringStartsWith('https://wa.me/15550100001', $response->headers->get('Location'),
            'A failed attribution write broke the redirect. The customer is standing in a shop; '
            .'a lost analytics row is acceptable and a dead sticker is not.');
    }

    // ══ §10 — what counts, and what does not ═══════════════════════════════

    /** ⚠️ §9: a redirect is NOT a customer message. */
    #[Test]
    public function a_scan_without_a_reply_counts_no_customer_messaged(): void
    {
        $t = $this->tenantWithQr();

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone)'])->get('/q/'.$t['code']->public_token);

        $this->assertSame(1, (int) DB::table('smart_qr_attribution_sessions')->count(), 'The scan issued a session.');
        $this->assertSame(0, $this->conversions($t['assignment']->id),
            'A redirect was counted as a customer message. §9 is explicit: it is not one.');
    }

    /**
     * ⚠️ NEW CONTACT — a contact that existed BEFORE the scan is not new.
     *
     * The wrong implementation counts every attributed contact as an
     * acquisition, and a tenant's existing customer base is reported as won by
     * the QR.
     */
    #[Test]
    public function an_existing_contact_is_not_counted_as_a_new_contact(): void
    {
        $t = $this->tenantWithQr();

        // The contact and conversation exist well before the scan.
        $existing = WorkspaceContext::for($t['workspace']->id, fn () => app(ContactService::class)
            ->upsert($t['workspace']->id, ['phone_e164' => '+15557654321', 'source' => 'import']));
        $existing->forceFill(['created_at' => now()->subMonth()])->save();

        $conversation = Conversation::firstOrCreate(
            ['workspace_id' => $t['workspace']->id, 'contact_id' => $existing->id, 'channel_account_id' => $t['channel']->id],
            ['status' => 'open', 'external_thread_id' => '15557654321']
        );
        $conversation->forceFill(['created_at' => now()->subMonth()])->save();

        $session = $this->attribution()->issue($t['assignment']->id);
        $this->inbound($t, 'Hello '.$this->attribution()->reference($session->token), $existing->fresh(), $conversation->fresh());

        $this->assertSame(1, $this->conversions($t['assignment']->id, SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED));
        $this->assertSame(0, $this->conversions($t['assignment']->id, SmartQrConversionEvent::TYPE_NEW_CONTACT),
            "An existing contact was counted as newly acquired. The tenant's own customer base "
            .'would be reported as won by the QR.');
        $this->assertSame(0, $this->conversions($t['assignment']->id, SmartQrConversionEvent::TYPE_CONVERSATION_STARTED),
            'A pre-existing conversation was counted as started by the scan.');
    }

    /** POSITIVE CONTROL: a genuinely new contact IS counted. */
    #[Test]
    public function a_contact_created_after_the_scan_is_counted_as_new(): void
    {
        $t = $this->tenantWithQr();
        $session = $this->attribution()->issue($t['assignment']->id);
        $session->forceFill(['issued_at' => now()->subMinutes(5)])->save();

        $this->inbound($t, 'Hello '.$this->attribution()->reference($session->token));

        $this->assertSame(1, $this->conversions($t['assignment']->id, SmartQrConversionEvent::TYPE_NEW_CONTACT),
            'A contact created after the scan was not counted as new — the metric would read '
            .'zero acquisitions forever.');
        $this->assertSame(1, $this->conversions($t['assignment']->id, SmartQrConversionEvent::TYPE_CONVERSATION_STARTED));
    }

    // ══ The extractor ══════════════════════════════════════════════════════

    /**
     * ⚠️ Anchored on the prefix. An unanchored 8-character match would collide
     * with ordinary text — product codes, postcodes, booking references — and
     * attribute a message to whichever session happened to match.
     */
    #[Test]
    public function ordinary_text_is_not_mistaken_for_a_reference(): void
    {
        $a = $this->attribution();

        $this->assertNull($a->extractToken('My booking is XYZ12345 for tomorrow'));
        $this->assertNull($a->extractToken('Order 23456789 please'));
        $this->assertNull($a->extractToken(null));
        $this->assertNull($a->extractToken(''));

        // Positive control: a real reference IS found, and case-insensitively,
        // because phone keyboards capitalise unpredictably.
        $this->assertSame('BCDFGHJK', $a->extractToken('hello ref: BCDFGHJK'));
        $this->assertSame('BCDFGHJK', $a->extractToken('Ref: bcdfghjk'));
    }

    /** The alphabet excludes characters a human would mistype. */
    #[Test]
    public function the_token_alphabet_excludes_ambiguous_characters(): void
    {
        $a = $this->attribution();

        for ($i = 0; $i < 50; $i++) {
            $token = $a->newToken();
            $this->assertSame(8, strlen($token));
            $this->assertDoesNotMatchRegularExpression('/[AEIOU01ILU]/', $token,
                "Token {$token} contains a vowel or an ambiguous character. Vowels let real "
                .'words form by accident, and 0/O and 1/I are mistyped when a customer retypes '
                .'what they see.');
        }
    }
}

<?php

namespace Tests\Feature\SmartQr;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\SmartQr\Jobs\RecordQrScanJob;
use App\Modules\SmartQr\Models\SmartQrAssignment;
use App\Modules\SmartQr\Models\SmartQrBatch;
use App\Modules\SmartQr\Models\SmartQrCode;
use App\Modules\SmartQr\Support\SmartQrStatus;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Slice 4 — GET /q/{token}. §8.
 *
 * ─── ⚠️ THE TEST THIS FILE EXISTS FOR ───────────────────────────────────────
 *
 * `a_printed_serial_number_is_not_accepted_as_a_token`.
 *
 * §8's six distinct responses are only safe because an attacker cannot obtain a
 * hit: `public_token` is 128 bits from `random_bytes`, so there is nothing to
 * enumerate. **That argument collapses the moment the route resolves anything
 * else** — and `serial_number` is printed on every sticker, sequential, and
 * guessable in order. If this route ever accepts one, every response
 * distinction below becomes an inventory oracle.
 *
 * That test guards the design decision, not a line of code.
 */
class PublicQrRedirectTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{code: SmartQrCode, assignment: SmartQrAssignment} */
    private function liveQr(array $assignmentAttrs = [], bool $withPhone = true): array
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $phoneNumberId = 'PN-'.uniqid();

        ChannelAccount::withoutWorkspaceScope('reason: test fixture')->create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'display_name' => 'Main Line',
            'phone_number_id' => $phoneNumberId,
            'status' => 'active',
        ]);

        $channelId = (int) DB::table('channel_accounts')->where('phone_number_id', $phoneNumberId)->value('id');

        if ($withPhone) {
            $waba = WhatsappBusinessAccount::create([
                'workspace_id' => $workspace->id,
                'waba_id' => 'WABA-'.uniqid(),
                'name' => 'Test WABA',
            ]);

            WhatsappPhoneNumber::create([
                'waba_id_fk' => $waba->id,
                'phone_number_id' => $phoneNumberId,
                'display_phone' => '+1 (555) 010-9988',
            ]);
        }

        $batch = SmartQrBatch::factory()->create(['default_message' => 'Batch level hello']);
        $code = SmartQrCode::factory()->create(['batch_id' => $batch->id]);

        $assignment = SmartQrAssignment::factory()->create(array_merge([
            'smart_qr_code_id' => $code->id,
            'workspace_id' => $workspace->id,
            'channel_account_id' => $channelId,
            'status' => SmartQrStatus::ASSIGNMENT_ACTIVE,
        ], $assignmentAttrs));

        return ['code' => $code, 'assignment' => $assignment];
    }

    private function scan(SmartQrCode $code, array $headers = [])
    {
        return $this->withHeaders(array_merge(['User-Agent' => 'Mozilla/5.0 (iPhone)'], $headers))
            ->get('/q/'.$code->public_token);
    }

    // ══ ⚠️ THE CONTROL THE WHOLE §8 DESIGN RESTS ON ════════════════════════

    /**
     * ⚠️ A printed serial must never resolve.
     *
     * Serials are sequential (`AX-000001`, `AX-000002`) and printed on the
     * artwork, so accepting one would let anybody walk the inventory and read
     * off which codes exist, which are unassigned, and which are retired — from
     * the distinct pages §8 asks for.
     *
     * The route's `[A-Za-z0-9]{16,64}` constraint means a hyphenated serial
     * cannot even match, so this returns 404 without touching the database.
     */
    #[Test]
    public function a_printed_serial_number_is_not_accepted_as_a_token(): void
    {
        ['code' => $code] = $this->liveQr();

        $this->assertNotSame($code->serial_number, $code->public_token, 'Precondition: two different values.');

        $this->get('/q/'.$code->serial_number)->assertNotFound();

        // Positive control: the SAME code redirects when addressed by its token,
        // so the 404 above is about the identifier and not a broken fixture.
        $this->scan($code)->assertRedirect();
    }

    /** …and a sequential probe of neighbouring serials finds nothing either. */
    #[Test]
    public function walking_sequential_serials_reveals_no_inventory(): void
    {
        $batch = SmartQrBatch::factory()->create(['prefix' => 'AX']);

        foreach (['AX-000001', 'AX-000002', 'AX-000003'] as $serial) {
            SmartQrCode::factory()->create(['batch_id' => $batch->id, 'serial_number' => $serial]);
        }

        foreach (['AX-000001', 'AX-000002', 'AX-000003', 'AX-000004'] as $serial) {
            $this->get('/q/'.$serial)->assertNotFound();
        }
    }

    // ══ The happy path ═════════════════════════════════════════════════════

    #[Test]
    public function an_active_configured_code_redirects_to_wa_me_with_the_message(): void
    {
        ['code' => $code] = $this->liveQr(['default_message' => 'Hi from the counter']);

        $response = $this->scan($code);

        $response->assertStatus(302);
        $location = $response->headers->get('Location');

        // Digits only — wa.me rejects punctuation and the leading plus.
        $this->assertStringStartsWith('https://wa.me/15550109988', $location,
            'The number was not normalised to digits. wa.me will not dial "+1 (555) 010-9988".');
        $this->assertStringContainsString(rawurlencode('Hi from the counter'), $location);
    }

    /** §7 tier 2: the batch message is used when the assignment has none. */
    #[Test]
    public function the_batch_message_is_used_when_the_assignment_has_none(): void
    {
        ['code' => $code] = $this->liveQr(['default_message' => null]);

        $location = $this->scan($code)->headers->get('Location');

        $this->assertStringContainsString(rawurlencode('Batch level hello'), $location,
            'The batch-level default message was not applied. §7 resolves assignment then batch.');
    }

    /** …and the assignment overrides it. */
    #[Test]
    public function the_assignment_message_overrides_the_batch(): void
    {
        ['code' => $code] = $this->liveQr(['default_message' => 'Assignment wins']);

        $location = $this->scan($code)->headers->get('Location');

        $this->assertStringContainsString(rawurlencode('Assignment wins'), $location);
        $this->assertStringNotContainsString(rawurlencode('Batch level hello'), $location);
    }

    // ══ ⚠️ EVERY REFUSAL STATE (§8) ════════════════════════════════════════

    #[Test]
    public function an_unknown_token_is_a_plain_404(): void
    {
        $this->get('/q/'.bin2hex(random_bytes(16)))->assertNotFound();
    }

    #[Test]
    public function an_unassigned_code_shows_the_not_set_up_page(): void
    {
        $code = SmartQrCode::factory()->create();

        $this->scan($code)
            ->assertOk()
            ->assertSee('not set up yet', false);
    }

    #[Test]
    public function an_inactive_assignment_shows_the_inactive_page(): void
    {
        ['code' => $code] = $this->liveQr(['status' => SmartQrStatus::ASSIGNMENT_INACTIVE]);

        $this->scan($code)
            ->assertOk()
            ->assertSee('currently inactive', false);
    }

    #[Test]
    public function an_expired_assignment_shows_the_expired_page(): void
    {
        ['code' => $code] = $this->liveQr(['expires_at' => now()->subDay()]);

        $this->scan($code)
            ->assertOk()
            ->assertSee('has expired', false);
    }

    /** A not-yet-started assignment reads as expired — both mean "not now". */
    #[Test]
    public function an_assignment_that_has_not_started_does_not_redirect(): void
    {
        ['code' => $code] = $this->liveQr(['starts_at' => now()->addWeek()]);

        $this->scan($code)->assertOk()->assertSee('has expired', false);
    }

    #[Test]
    public function a_retired_code_shows_the_permanently_disabled_page(): void
    {
        ['code' => $code] = $this->liveQr();
        $code->forceFill(['status' => SmartQrStatus::CODE_RETIRED])->save();

        $this->scan($code)
            ->assertOk()
            ->assertSee('no longer active', false);
    }

    /**
     * ⚠️ THE SEVENTH STATE — assigned, active, in date, no dialable number.
     *
     * `ChannelAccount.phone_number_id` is a Meta identifier, not a phone number,
     * so this is reachable on entirely correct assignment data.
     */
    #[Test]
    public function an_assignment_whose_channel_has_no_number_shows_the_inactive_page(): void
    {
        ['code' => $code] = $this->liveQr(withPhone: false);

        $this->scan($code)
            ->assertOk()
            ->assertSee('currently inactive', false);
    }

    // ══ ⚠️ §8 — "do not reveal customer or internal configuration" ═════════

    /**
     * ⚠️ No refusal page names anything internal.
     *
     * Asserted across EVERY non-redirect state at once, because the leak that
     * matters is the one added later to a single page by someone being helpful.
     */
    #[Test]
    public function no_refusal_page_reveals_tenant_or_internal_detail(): void
    {
        ['code' => $live, 'assignment' => $assignment] = $this->liveQr(['name' => 'Front counter QR']);
        $workspace = $assignment->workspace;
        $client = $workspace->client;

        $cases = [
            'unassigned' => SmartQrCode::factory()->create(),
            'inactive' => tap($live, fn ($c) => $assignment->forceFill(['status' => SmartQrStatus::ASSIGNMENT_INACTIVE])->saveQuietly()),
        ];

        foreach ($cases as $label => $code) {
            $body = $this->scan($code)->getContent();

            foreach (array_filter([
                $workspace->name,
                $client?->name,
                $code->serial_number,
                $code->public_token,
                $assignment->name,
                // ⚠️ workspace_id is deliberately NOT checked. It is a small
                // integer, and "10" appears in CSS, colours and markup on any
                // page — asserting on it tests the stylesheet, not the leak.
            ]) as $secret) {
                $this->assertStringNotContainsString((string) $secret, $body,
                    "The {$label} page leaked '{$secret}'. §8 forbids revealing customer or "
                    .'internal configuration, and an inventory detail on a public page is exactly '
                    .'what an attacker holding one token would harvest.');
            }
        }
    }

    /**
     * ⚠️ Every "exists" state returns the SAME status.
     *
     * The copy differs because it serves a person holding a sticker. The status
     * must not, because that is the part a script can read — differentiating by
     * 410-vs-200 would hand back the machine-readable signal the pages avoid.
     */
    #[Test]
    public function every_exists_state_returns_the_same_http_status(): void
    {
        ['code' => $inactive] = $this->liveQr(['status' => SmartQrStatus::ASSIGNMENT_INACTIVE]);
        ['code' => $expired] = $this->liveQr(['expires_at' => now()->subDay()]);
        ['code' => $retiredQr] = $this->liveQr();
        $retiredQr->forceFill(['status' => SmartQrStatus::CODE_RETIRED])->save();
        ['code' => $unconfigured] = $this->liveQr(withPhone: false);
        $unassigned = SmartQrCode::factory()->create();

        foreach ([$inactive, $expired, $retiredQr, $unconfigured, $unassigned] as $code) {
            $this->scan($code)->assertStatus(200);
        }

        // …and only a genuinely unknown token differs.
        $this->get('/q/'.bin2hex(random_bytes(16)))->assertStatus(404);
    }

    // ══ Scan recording (§8 performance, §10 privacy) ═══════════════════════

    #[Test]
    public function a_successful_redirect_queues_a_scan_and_writes_nothing_synchronously(): void
    {
        Queue::fake();
        ['code' => $code, 'assignment' => $assignment] = $this->liveQr();

        $this->scan($code)->assertRedirect();

        Queue::assertPushed(RecordQrScanJob::class, fn ($job) => $job->assignmentId === $assignment->id);

        $this->assertSame(0, (int) DB::table('smart_qr_scan_events')->count(),
            'The scan was written during the request. §8 requires minimal synchronous work; '
            .'recording belongs on the queue.');
    }

    /** ⚠️ No raw IP or user agent may reach the job payload — the jobs table is durable. */
    #[Test]
    public function the_queued_job_carries_hashes_never_raw_identifiers(): void
    {
        Queue::fake();
        ['code' => $code] = $this->liveQr();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone)'])
            ->get('/q/'.$code->public_token);

        Queue::assertPushed(RecordQrScanJob::class, function ($job) {
            $this->assertNotSame('203.0.113.77', $job->ipHash, 'A raw IP reached the job payload.');
            $this->assertStringNotContainsString('203.0.113.77', (string) $job->ipHash);
            $this->assertStringNotContainsString('iPhone', (string) $job->uaHash);
            $this->assertSame(64, strlen((string) $job->ipHash), 'Expected a sha256 HMAC.');

            return true;
        });
    }

    /** A refusal records nothing — there is no assignment to attribute it to. */
    #[Test]
    public function a_refused_scan_queues_no_job(): void
    {
        Queue::fake();
        $this->scan(SmartQrCode::factory()->create());

        Queue::assertNothingPushed();
    }

    /**
     * ⚠️ THE FAILURE MODE, ASSERTED: the redirect survives a dead queue.
     *
     * A broken redirect is a customer in a shop looking at a dead sticker; a
     * lost scan is one missing row. The direction is not symmetric and this
     * pins it.
     */
    #[Test]
    public function the_redirect_still_works_when_queueing_the_scan_fails(): void
    {
        ['code' => $code] = $this->liveQr();

        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('queue is down'));

        $this->scan($code)->assertStatus(302);
    }

    // ══ Bot flagging and uniqueness (§10) ══════════════════════════════════

    #[Test]
    public function a_crawler_is_flagged_but_still_recorded(): void
    {
        ['code' => $code, 'assignment' => $assignment] = $this->liveQr();

        $this->scan($code, ['User-Agent' => 'facebookexternalhit/1.1'])->assertRedirect();

        $row = DB::table('smart_qr_scan_events')->where('smart_qr_assignment_id', $assignment->id)->first();

        $this->assertNotNull($row, 'The crawler hit was DROPPED. It must be flagged and kept — a '
            .'preview crawler is evidence the link was shared, and deleting is irreversible.');
        $this->assertSame(1, (int) $row->is_bot);
    }

    #[Test]
    public function a_real_browser_is_not_flagged_as_a_bot(): void
    {
        ['code' => $code, 'assignment' => $assignment] = $this->liveQr();

        $this->scan($code, ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari/605.1'])
            ->assertRedirect();

        $row = DB::table('smart_qr_scan_events')->where('smart_qr_assignment_id', $assignment->id)->first();

        $this->assertSame(0, (int) $row->is_bot,
            'A normal phone browser was flagged as a bot — every real scan would be excluded '
            .'from the tenant\'s analytics.');
    }

    /** ⚠️ The 24h window: a repeat from the same fingerprint is not unique. */
    #[Test]
    public function a_repeat_scan_within_the_window_is_not_unique(): void
    {
        ['code' => $code, 'assignment' => $assignment] = $this->liveQr();

        $headers = ['User-Agent' => 'Mozilla/5.0 (iPhone)'];
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->withHeaders($headers)->get('/q/'.$code->public_token);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->withHeaders($headers)->get('/q/'.$code->public_token);

        $rows = DB::table('smart_qr_scan_events')
            ->where('smart_qr_assignment_id', $assignment->id)
            ->orderBy('id')->get();

        $this->assertCount(2, $rows, 'Both hits must be recorded; only the uniqueness flag differs.');
        $this->assertSame(1, (int) $rows[0]->is_unique);
        $this->assertSame(0, (int) $rows[1]->is_unique,
            'The second hit from the same fingerprint counted as unique. Every returning customer '
            .'would inflate the unique-scan figure.');
    }

    /** …and a different visitor within the window IS unique. */
    #[Test]
    public function a_different_visitor_within_the_window_is_unique(): void
    {
        ['code' => $code, 'assignment' => $assignment] = $this->liveQr();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->withHeaders(['User-Agent' => 'Mozilla/5.0 (iPhone)'])->get('/q/'.$code->public_token);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.44'])->withHeaders(['User-Agent' => 'Mozilla/5.0 (Android)'])->get('/q/'.$code->public_token);

        $unique = DB::table('smart_qr_scan_events')
            ->where('smart_qr_assignment_id', $assignment->id)
            ->where('is_unique', true)->count();

        $this->assertSame(2, $unique,
            'A second, different visitor was not counted as unique — the fingerprint is not '
            .'discriminating and every venue would report one unique scan forever.');
    }

    /** Referer is stored as a HOST, never a full URL with its query string. */
    #[Test]
    public function only_the_referer_host_is_stored(): void
    {
        ['code' => $code, 'assignment' => $assignment] = $this->liveQr();

        $this->scan($code, ['referer' => 'https://partner.example.com/landing?utm=abc&email=x@y.z'])
            ->assertRedirect();

        $row = DB::table('smart_qr_scan_events')->where('smart_qr_assignment_id', $assignment->id)->first();

        $this->assertSame('partner.example.com', $row->referer_host);
        $this->assertStringNotContainsString('email=', (string) $row->referer_host,
            'The full referer was stored. Query strings carry tokens and personal data.');
    }

    // ══ The workspace scope, on an unauthenticated path ════════════════════

    /**
     * ⚠️ Resolution works with NO workspace context — the discovery-bypass shape.
     *
     * `SmartQrAssignment` is workspace-scoped and fails closed. Without the
     * bypass in the resolver, every code on earth would report as UNASSIGNED to
     * the public, and the module would look like it had never been configured.
     */
    #[Test]
    public function resolution_works_with_no_ambient_workspace_context(): void
    {
        ['code' => $code] = $this->liveQr();

        WorkspaceContext::flush();
        $this->assertNull(WorkspaceContext::id(), 'Precondition: no context.');

        $this->scan($code)->assertStatus(302);
    }
}

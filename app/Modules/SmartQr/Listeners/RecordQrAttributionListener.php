<?php

namespace App\Modules\SmartQr\Listeners;

use App\Events\MessageReceived;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\SmartQr\Models\SmartQrAttributionSession;
use App\Modules\SmartQr\Models\SmartQrConversionEvent;
use App\Modules\SmartQr\Services\SmartQrAttribution;
use Illuminate\Support\Facades\Log;

/**
 * §9's second half: the token arrives back on an inbound message.
 *
 * ─── ⚠️ AN OBSERVER. NOTHING IN THE INBOUND FLOW CHANGES. ───────────────────
 *
 * CLAUDE.md forbids a parallel inbound flow. This is not one: it is a fifth
 * listener on the existing `MessageReceived` event, registered beside
 * AutomationTriggerListener, AutoReplyListener, DispatchOutboundWebhookListener
 * and SendNewMessageNotification. No driver, controller or route was touched.
 *
 * `WhatsappDriver::persistInboundMessage()` dispatches the event AFTER the
 * contact is upserted, the conversation resolved and the Message row written —
 * so everything this needs already exists and it creates none of it.
 *
 * ─── ⚠️ SYNCHRONOUS, AND THAT IS THE TENANT BOUNDARY ────────────────────────
 *
 * All four existing listeners are synchronous, and this must be too. The driver
 * wraps the whole persist in `WorkspaceContext::for($workspaceId, …)`, so a
 * synchronous listener inherits the CORRECT tenant automatically.
 *
 * A queued listener would run later, outside that context, where every
 * workspace-scoped read fails closed — it would silently attribute to nothing,
 * or worse, would have to re-establish a tenant from data it had to trust.
 *
 * ─── ⚠️ IT MUST NEVER BREAK MESSAGE INGESTION ───────────────────────────────
 *
 * It runs inline on every inbound message on every channel. A thrown exception
 * here would take the customer's message with it, so the whole body is guarded:
 * attribution failing is a lost analytics row, not a lost conversation.
 */
class RecordQrAttributionListener
{
    public function __construct(private readonly SmartQrAttribution $attribution) {}

    public function handle(MessageReceived $event): void
    {
        try {
            $this->attribute($event);
        } catch (\Throwable $e) {
            // Never let analytics break ingestion.
            Log::warning('smart_qr.attribution.failed', [
                'message_id' => $event->message->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function attribute(MessageReceived $event): void
    {
        $message = $event->message;

        // Outbound messages carry our own reference back to us. Only what the
        // CUSTOMER sent is an attribution (§9: a redirect is not a message, and
        // neither is our own prefill echoed).
        if ($message->direction !== 'in') {
            return;
        }

        // ⚠️ The cheap exit, and it is the overwhelming majority of messages.
        // No token in the body means no query at all.
        $token = $this->attribution->extractToken($message->body);

        if ($token === null) {
            return;
        }

        $session = $this->attribution->resolve($token);

        // Expired, already consumed, or simply not ours. All three are silent:
        // a REPEAT message carrying the same reference is one customer
        // continuing to talk, not a second attribution.
        if ($session === null) {
            return;
        }

        /** @var Conversation|null $conversation */
        $conversation = $message->conversation;

        if ($conversation === null) {
            return;
        }

        // ══ ⚠️ THE CROSS-TENANT REFUSAL ════════════════════════════════════
        //
        // The workspace comes from the MESSAGE, never from the token — a token
        // must not be able to choose a tenant. If the assignment's workspace and
        // the conversation's workspace disagree, this is a copied sticker or an
        // attack, and NOTHING is written.
        //
        // Reached through the assignment because neither attribution table
        // carries a workspace_id (R-4): a session outlives the certainty of its
        // tenant, since the code can be reassigned inside the session's window.
        //
        // ─── ⚠️ WHICH BARRIER ACTUALLY FIRES, MEASURED ─────────────────────
        //
        // There are TWO, and only the first is currently observable:
        //
        //   1. `SmartQrAssignment` is workspace-scoped, and this listener runs
        //      inside WorkspaceContext::for($conversationWorkspace). So
        //      `$session->assignment` returns NULL for another tenant's
        //      assignment — the scope fails closed and blocks it first.
        //   2. The explicit comparison below.
        //
        // Measured by mutation: removing the comparison alone leaves
        // `a_token_from_another_tenant_attributes_nothing` GREEN, because
        // barrier 1 already stopped it. Removing BOTH — dropping the scope on
        // this lookup and the comparison — makes that test fail.
        //
        // The comparison is therefore defence in depth that cannot presently be
        // shown to fire on its own. It is kept deliberately: it is the only
        // thing that still holds if this lookup is ever changed to bypass the
        // scope (which every other admin path in this module does), and that
        // change would otherwise silently open cross-tenant attribution.
        $assignment = $session->assignment;

        if ($assignment === null || (int) $assignment->workspace_id !== (int) $conversation->workspace_id) {
            Log::warning('smart_qr.attribution.tenant_mismatch', [
                'session_id' => $session->id,
                'assignment_workspace_id' => $assignment?->workspace_id,
                'conversation_workspace_id' => $conversation->workspace_id,
            ]);

            return;
        }

        // ⚠️ Claim FIRST. The conditional update is what makes two concurrent
        // messages with one token produce one attribution; if we lose the race,
        // the other message already recorded everything.
        if (! $this->attribution->claim($session, (int) $conversation->contact_id, (int) $conversation->id, (int) $message->id)) {
            return;
        }

        $this->recordConversions($session, $message, $conversation);
    }

    private function recordConversions(SmartQrAttributionSession $session, Message $message, Conversation $conversation): void
    {
        /** @var Contact|null $contact */
        $contact = $conversation->contact;
        $issuedAt = $session->issued_at;

        $write = function (string $type) use ($session, $message, $conversation, $contact) {
            SmartQrConversionEvent::create([
                'smart_qr_assignment_id' => $session->smart_qr_assignment_id,
                'attribution_session_id' => $session->id,
                'type' => $type,
                'contact_id' => $contact?->id,
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'occurred_at' => now(),
            ]);
        };

        // §9: the customer actually messaged. One per session, guaranteed by the
        // unique index on (attribution_session_id, type).
        $write(SmartQrConversionEvent::TYPE_CUSTOMER_MESSAGED);

        // ⚠️ NEW CONTACT — decided by timestamp, and this has a KNOWN LIMIT.
        //
        // A contact counts as acquired by this QR if it did not exist when the
        // session was issued. **This can misclassify a contact created within
        // the same second as the session**, because both timestamps have
        // second resolution — a customer who scans and replies instantly may be
        // recorded either way.
        //
        // Accepted deliberately: the alternative is correlating ContactCreated
        // events, which is real machinery for a rare race whose misclassification
        // costs one row in a count. If a discrepancy is ever investigated, this
        // is the known limit — not a bug to hunt.
        if ($contact !== null && $contact->created_at !== null && $contact->created_at->gt($issuedAt)) {
            $write(SmartQrConversionEvent::TYPE_NEW_CONTACT);
        }

        // ⚠️ "Conversations started" can only mean one that did not exist before
        // the scan. The inbound flow creates a conversation for EVERY message
        // from a new thread, so counting "has a conversation" would count every
        // attributed message — §9's wording is broader than what is measurable.
        if ($conversation->created_at !== null && $conversation->created_at->gt($issuedAt)) {
            $write(SmartQrConversionEvent::TYPE_CONVERSATION_STARTED);
        }
    }
}

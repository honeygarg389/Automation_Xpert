<?php

namespace App\Modules\Restaurant\Services;

use App\Modules\Restaurant\Exceptions\UnprocessablePosWebhookEventException;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\PosWebhookEvent;
use App\Modules\Restaurant\Models\RestaurantBill;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Shared\Models\Contact;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns one `pending` `PosWebhookEvent` (Petpooja `orderdetails`) into a
 * durable, idempotent `RestaurantBill` row.
 *
 * ⚠️ Reads ONLY the documented Petpooja Global API paths this slice was
 * scoped to: `properties.Customer.name/phone`, `properties.Order.orderID/
 * total/core_total/discount_total/tax_total/created_on`,
 * `properties.OrderItem`, `properties.Tax`, `properties.Discount`, plus
 * `properties.Order.status` as `source_order_status`. Nothing else is promoted
 * to a column — the full raw payload already lives on
 * `pos_webhook_events.raw_payload`/`raw_body`, linked via
 * `restaurant_bills.webhook_event_id`, for anything not listed here.
 *
 * ⚠️ ORDER TIME IS NEVER GUESSED. Petpooja's documented `created_on` samples
 * carry no timezone. The raw string is stored verbatim in
 * `source_created_on_raw`; `placed_at` is filled ONLY when the outlet has a
 * valid IANA timezone AND the value matches the documented
 * `Y-m-d H:i:s` shape exactly — it is then converted to UTC. Otherwise it is
 * NULL (never "assume UTC", never "use now"). Operational ordering uses
 * `received_at`, the webhook's own receive time.
 *
 * ⚠️ FAILURE SEMANTICS. A structurally invalid payload throws
 * {@see UnprocessablePosWebhookEventException}, which the job treats as a
 * PERMANENT failure (no retries): retrying an identical payload cannot change
 * the outcome. Any other exception (database down, deadlock) is transient.
 *
 * ⚠️ CRITICAL TRUST BOUNDARY, same as the ingress controller:
 * `workspace_id` and `outlet_id` come ONLY from the already-authenticated
 * `PosWebhookEvent`/`PosConnection` rows, never from the payload.
 *
 * ⚠️ Slice 2 is bill ingestion ONLY. This service never sends a WhatsApp
 * message, digital bill, feedback request, or outbound webhook, and never
 * makes an external HTTP call.
 *
 * ⚠️ CONTACTS: a POS bill LINKS to a contact, it never REWRITES one.
 * {@see self::resolveContact()} finds an existing contact by (workspace,
 * E.164 phone) and links it untouched — `ContactService::upsert()` is
 * deliberately NOT used because it is an `updateOrCreate`, which would let a
 * name typed at a till overwrite the `first_name`/`source` of a contact an
 * import or a customer created. A soft-deleted contact is linked but never
 * restored (a deliberately-deleted person must not reappear because they
 * bought lunch again). A brand-new contact is created with NO marketing
 * opt-in and no WhatsApp consent, and `ContactCreated` is never dispatched
 * (that would fire `contact.created` automations and outbound webhooks).
 */
class PetpoojaOrderIngestionService
{
    /**
     * @return int the `restaurant_bills.id` the event was ingested into
     */
    public function ingest(PosWebhookEvent $event): int
    {
        if ($event->event_type !== 'orderdetails') {
            throw new UnprocessablePosWebhookEventException(
                "Cannot ingest event_type '{$event->event_type}' — only 'orderdetails' is supported."
            );
        }

        if ($event->connection_id === null || $event->workspace_id === null) {
            throw new UnprocessablePosWebhookEventException(
                'Event has no resolved connection_id/workspace_id — cannot ingest without a trusted tenant.'
            );
        }

        // reason: the ingestion job establishes its own workspace context via
        // EstablishesWorkspaceContext before calling this service, so the
        // connection lookup below is already inside that context. It still
        // must not depend on PosConnection being scoped — it never is (see
        // the model docblock) — so this plain query is correct either way.
        $connection = PosConnection::query()->find($event->connection_id);

        if ($connection === null) {
            throw new UnprocessablePosWebhookEventException(
                "PosConnection #{$event->connection_id} referenced by event #{$event->id} no longer exists."
            );
        }

        // PosWebhookEvent::$raw_payload is cast 'array' and the migration's
        // column is NOT NULL, so this is guaranteed an array once the row
        // exists — no defensive is_array() needed here.
        $payload = $event->raw_payload;

        $properties = data_get($payload, 'properties');
        $order = data_get($properties, 'Order');

        if (! is_array($order)) {
            throw new UnprocessablePosWebhookEventException('Payload is missing properties.Order.');
        }

        $externalOrderId = $this->requireExternalOrderId(data_get($order, 'orderID'));

        $customer = data_get($properties, 'Customer');
        $customer = is_array($customer) ? $customer : [];

        $contactId = $this->resolveContact($event->workspace_id, $connection, $customer);

        $rawCreatedOn = $this->rawScalarString(data_get($order, 'created_on'));
        $placedAt = $this->placedAtUtc($rawCreatedOn, $this->outletTimezone($connection));

        $row = [
            'workspace_id' => $event->workspace_id,
            'connection_id' => $event->connection_id,
            'webhook_event_id' => $event->id,
            'outlet_id' => $connection->outlet_id,
            'contact_id' => $contactId,
            'provider' => $event->provider,
            'external_order_id' => $externalOrderId,
            // The upsert bypasses Eloquent's creating hook. Supply a token for
            // the INSERT branch only; it is deliberately excluded from the
            // UPDATE list so corrected POS payloads never invalidate a shared
            // public link.
            'public_token' => RestaurantBill::generatePublicToken(),
            'source_order_status' => $this->sourceOrderStatus(data_get($order, 'status')),
            'source_order_type' => $this->sourceOrderType(data_get($order, 'order_type')),
            'source_created_on_raw' => $rawCreatedOn,
            'customer_name' => $this->stringOrNull(data_get($customer, 'name')),
            'customer_phone_raw' => $this->stringOrNull(data_get($customer, 'phone')),
            'total' => $this->numericOrNull(data_get($order, 'total')),
            'core_total' => $this->numericOrNull(data_get($order, 'core_total')),
            'discount_total' => $this->numericOrNull(data_get($order, 'discount_total')),
            'tax_total' => $this->numericOrNull(data_get($order, 'tax_total')),
            'order_items' => json_encode($this->arrayOrEmpty(data_get($properties, 'OrderItem'))),
            'taxes' => json_encode($this->arrayOrEmpty(data_get($properties, 'Tax'))),
            'discounts' => json_encode($this->arrayOrEmpty(data_get($properties, 'Discount'))),
            'placed_at' => $placedAt,
            'received_at' => $event->received_at,
            'updated_at' => now(),
        ];

        // Race-safe MySQL upsert: compiles to INSERT ... ON DUPLICATE KEY
        // UPDATE against restaurant_bills_connection_order_unique, so two
        // concurrent deliveries/retries for the same (connection_id,
        // external_order_id) can never create two rows — the second one
        // always updates the first, atomically, at the database layer.
        // created_at and received_at are intentionally excluded from the
        // update list: both record the bill's FIRST arrival, so a correction
        // (or a lease-reclaimed re-run of the same event) never moves it in
        // operational ordering. Everything else is refreshed from the newest
        // payload.
        DB::table('restaurant_bills')->upsert(
            [$row + ['created_at' => now()]],
            ['connection_id', 'external_order_id'],
            array_values(array_diff(array_keys($row), ['received_at', 'public_token'])),
        );

        /** @var int $billId */
        $billId = DB::table('restaurant_bills')
            ->where('connection_id', $event->connection_id)
            ->where('external_order_id', $externalOrderId)
            ->value('id');

        return $billId;
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function resolveContact(int $workspaceId, PosConnection $connection, array $customer): ?int
    {
        $rawPhone = data_get($customer, 'phone');

        if (! is_string($rawPhone) || trim($rawPhone) === '') {
            // No phone in the payload at all (the documented "Part Payment"
            // and aggregator samples both show this). Never a failure —
            // the bill still persists with contact_id = null.
            return null;
        }

        $phone = $this->normalizeLocalPhone($rawPhone, $connection->default_phone_country);

        if ($phone === null) {
            return null;
        }

        $existingId = $this->findContactId($workspaceId, $phone);

        if ($existingId !== null) {
            return $existingId;
        }

        try {
            $contact = Contact::create([
                'workspace_id' => $workspaceId,
                'phone_e164' => $phone,
                // Petpooja sends one "name" field; this codebase has no
                // established convention for splitting a single display
                // name into first/last, so the whole name goes in
                // first_name rather than inventing a heuristic that could
                // silently mangle it.
                'first_name' => $this->stringOrNull(data_get($customer, 'name')),
                'source' => 'petpooja',
                // Explicit, not left to column defaults: contacts.opt_in_email
                // DEFAULTs to true, and a customer who handed over a phone
                // number at a till has not opted in to any marketing channel.
                'opt_in_whatsapp' => false,
                'opt_in_sms' => false,
                'opt_in_email' => false,
            ]);

            return $contact->id;
        } catch (UniqueConstraintViolationException) {
            // Lost a race: another worker created this exact (workspace,
            // phone) contact between the lookup above and this INSERT. The
            // winner's row is the right one to link — never fail the bill.
            return $this->findContactId($workspaceId, $phone);
        }
    }

    /**
     * Petpooja's documented customer phone values are LOCAL DIGITS (no '+'
     * in any documented sample), so they are normalized ONLY through the
     * connection's own `default_phone_country`. A value that arrives with
     * its own '+' country code is an undocumented shape and is deliberately
     * NOT trusted to bypass that setting — the bill keeps the raw value in
     * `customer_phone_raw` and simply stays unlinked. Any failure (no
     * default country, wrong digit count, garbage) returns null; none of
     * them may ever fail bill persistence.
     */
    private function normalizeLocalPhone(string $rawPhone, ?string $defaultCountry): ?string
    {
        $cleaned = preg_replace('/[\s\-().]/', '', trim($rawPhone)) ?? '';

        if ($cleaned === '' || str_starts_with($cleaned, '+')) {
            return null;
        }

        return PhoneNumber::normalizeForImport($cleaned, $defaultCountry)['phone'];
    }

    /**
     * `withTrashed()`: UNIQUE(workspace_id, phone_e164) covers soft-deleted
     * rows too, so a deleted contact still occupies its phone and must be
     * found here rather than re-created (which would violate the index).
     * The explicit workspace_id predicate is belt-and-braces alongside the
     * BelongsToWorkspace scope the job's context middleware applies.
     */
    private function findContactId(int $workspaceId, string $phone): ?int
    {
        $id = Contact::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('phone_e164', $phone)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * `orderID` is the idempotency key, so it must be a usable one: a
     * non-blank string or an integer, at most 64 characters (the column
     * width). Anything else — missing, null, blank, an array/object, a bool, a
     * float, an oversized value — is a structural defect in the payload and
     * fails the event permanently with a reason naming the exact problem.
     */
    private function requireExternalOrderId(mixed $value): string
    {
        if ($value === null) {
            throw new UnprocessablePosWebhookEventException('Payload is missing properties.Order.orderID.');
        }

        if (! is_string($value) && ! is_int($value)) {
            throw new UnprocessablePosWebhookEventException(
                'properties.Order.orderID must be a string or integer, got '.get_debug_type($value).'.'
            );
        }

        $id = trim((string) $value);

        if ($id === '') {
            throw new UnprocessablePosWebhookEventException('Payload has a blank properties.Order.orderID.');
        }

        if (mb_strlen($id) > 64) {
            throw new UnprocessablePosWebhookEventException('properties.Order.orderID is longer than 64 characters.');
        }

        return $id;
    }

    /**
     * The value exactly as Petpooja sent it — no trimming, no reformatting.
     * Only a string or a number can be an honest raw timestamp; anything
     * else (null, bool, array) is recorded as absent.
     */
    private function rawScalarString(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }

    /**
     * `Order.status` as sent (documented: Success, Cancelled). Trimmed and
     * capped to the column width; never normalised or mapped — this is the
     * SOURCE system's word, and an unknown value must survive verbatim.
     */
    private function sourceOrderStatus(mixed $value): ?string
    {
        $status = $this->stringOrNull($value);

        return $status === null ? null : Str::limit($status, 32, '');
    }

    private function sourceOrderType(mixed $value): ?string
    {
        $type = $this->stringOrNull($value);

        return $type === null ? null : Str::limit($type, 64, '');
    }

    /**
     * The outlet's timezone, but ONLY if it is a real IANA identifier.
     * `restaurant_outlets.timezone` is validated as a free string of up to 64
     * characters, so it can hold "IST", "+05:30" or plain typos; PHP would
     * accept some of those as abbreviations/offsets, which is exactly the
     * ambiguity this must not paper over. Anything that is not in
     * `DateTimeZone::listIdentifiers()` counts as "no timezone".
     *
     * The lookup is a normal workspace-scoped query: the job establishes the
     * event's workspace context before calling this service.
     */
    private function outletTimezone(PosConnection $connection): ?\DateTimeZone
    {
        if ($connection->outlet_id === null) {
            return null;
        }

        $name = RestaurantOutlet::query()->whereKey($connection->outlet_id)->value('timezone');

        if (! is_string($name) || ! in_array($name, \DateTimeZone::listIdentifiers(), true)) {
            return null;
        }

        return new \DateTimeZone($name);
    }

    /**
     * Interprets `created_on` in the OUTLET's timezone and converts to UTC —
     * or returns null. Null when there is no valid outlet timezone, or when
     * the value is not exactly `Y-m-d H:i:s` (the documented shape); an
     * overflowing value such as "2025-13-45 11:45:35" is rejected rather than
     * silently rolled into another month.
     */
    private function placedAtUtc(?string $rawCreatedOn, ?\DateTimeZone $timezone): ?Carbon
    {
        if ($rawCreatedOn === null || $timezone === null) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $rawCreatedOn, $timezone);
        $errors = \DateTimeImmutable::getLastErrors();

        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return Carbon::instance($parsed)->utc();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function numericOrNull(mixed $value): string|int|float|null
    {
        return is_numeric($value) ? $value : null;
    }

    /**
     * @return array<int, mixed>
     */
    private function arrayOrEmpty(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}

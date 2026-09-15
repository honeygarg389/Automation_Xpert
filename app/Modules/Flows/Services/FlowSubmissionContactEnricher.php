<?php

namespace App\Modules\Flows\Services;

use App\Modules\Shared\Models\Contact;
use App\Support\PhoneNumber;

/**
 * Conservative contact enrichment for a live form completion.
 *
 * ContactService::upsert() is intentionally not used: its updateOrCreate
 * semantics are correct for channel sync but would overwrite established CRM
 * data with an answer typed into a form. Here, present data always wins.
 */
class FlowSubmissionContactEnricher
{
    /** @param array<string, mixed> $answers */
    public function enrich(int $workspaceId, array $answers, ?int $knownContactId = null): ?Contact
    {
        $data = $this->contactData($answers);
        $contact = null;

        // Submission identity uses the same phone-before-email priority as upsert().
        if ($data['phone_e164'] !== null) {
            $contact = Contact::where('workspace_id', $workspaceId)
                ->where('phone_e164', $data['phone_e164'])
                ->first();
        }
        if ($contact === null && $data['email'] !== null) {
            $contact = Contact::where('workspace_id', $workspaceId)
                ->where('email', $data['email'])
                ->first();
        }

        // WhatsApp's sender identity is a safe fallback when a form omits identity fields.
        if ($contact === null && $knownContactId !== null) {
            $contact = Contact::where('workspace_id', $workspaceId)->find($knownContactId);
        }

        if ($contact === null && ($data['phone_e164'] !== null || $data['email'] !== null)) {
            return Contact::create(array_filter([
                'workspace_id' => $workspaceId,
                'phone_e164' => $data['phone_e164'],
                'email' => $data['email'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'source' => 'whatsapp_flow',
            ], static fn (mixed $value): bool => $value !== null));
        }

        if ($contact === null) {
            return null;
        }

        $fill = [];
        foreach (['phone_e164', 'email', 'first_name', 'last_name'] as $field) {
            if ($data[$field] !== null && $this->isBlank($contact->{$field})) {
                $fill[$field] = $data[$field];
            }
        }
        if ($fill !== []) {
            $contact->update($fill);
        }

        return $contact;
    }

    /** @param array<string, mixed> $answers @return array{phone_e164:?string,email:?string,first_name:?string,last_name:?string} */
    private function contactData(array $answers): array
    {
        $phone = $this->value($answers, ['phone_e164', 'phone', 'phone_number', 'mobile']);
        $email = $this->value($answers, ['email', 'email_address']);
        $first = $this->value($answers, ['first_name', 'firstname', 'first']);
        $last = $this->value($answers, ['last_name', 'lastname', 'last']);
        $name = $this->value($answers, ['name', 'full_name']);

        if ($first === null && $name !== null) {
            $parts = preg_split('/\s+/', $name, 2) ?: [];
            $first = $parts[0] ?? null;
            $last ??= $parts[1] ?? null;
        }

        $normalizedPhone = null;
        if ($phone !== null) {
            ['phone' => $normalizedPhone, 'error' => $error] = PhoneNumber::normalizeForImport($phone, null);
            if ($error !== null) {
                $normalizedPhone = null;
            }
        }

        $normalizedEmail = $email !== null ? mb_strtolower(trim($email)) : null;
        if ($normalizedEmail !== null && filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false) {
            $normalizedEmail = null;
        }

        return [
            'phone_e164' => $normalizedPhone,
            'email' => $normalizedEmail,
            'first_name' => $first,
            'last_name' => $last,
        ];
    }

    /** @param array<string, mixed> $answers @param list<string> $keys */
    private function value(array $answers, array $keys): ?string
    {
        $normalized = [];
        foreach ($answers as $key => $value) {
            $normalized[mb_strtolower((string) $key)] = $value;
        }
        foreach ($keys as $key) {
            $value = $normalized[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}

<?php

namespace Tests\Feature;

use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\ContactTag;
use App\Modules\Shared\Models\Segment;
use App\Modules\Shared\Services\SegmentResolver;
use App\Support\ApiAbilities;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContactProfileEnhancementTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function profile(): array
    {
        return [
            'gender' => 'female',
            'birthday' => '1992-04-16',
            'anniversary_date' => '2018-11-03',
            'city' => 'Mumbai',
            'state' => 'Maharashtra',
            'postal_code' => '400001',
        ];
    }

    public function test_dashboard_can_create_a_contact_with_all_profile_fields(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->post(route('client.contacts.store'), array_merge([
                'phone_e164' => '+919810000001',
                'first_name' => 'Asha',
            ], $this->profile()))
            ->assertRedirect();

        $this->assertDatabaseHas('contacts', array_merge([
            'workspace_id' => $workspace->id,
            'phone_e164' => '+919810000001',
        ], $this->profile()));
    }

    public function test_profile_fields_are_all_optional(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->post(route('client.contacts.store'), ['phone_e164' => '+919810000002'])
            ->assertRedirect();

        $this->assertDatabaseHas('contacts', [
            'workspace_id' => $workspace->id,
            'phone_e164' => '+919810000002',
            'gender' => null,
            'birthday' => null,
            'anniversary_date' => null,
            'city' => null,
            'state' => null,
            'postal_code' => null,
        ]);
    }

    public function test_api_updates_all_profile_fields_and_returns_them(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $token = $user->createToken('contacts', ['*'])->plainTextToken;
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);

        $this->withToken($token)
            ->patchJson("/api/v1/contacts/{$contact->id}", $this->profile())
            ->assertOk()
            ->assertJsonPath('data.gender', 'female')
            ->assertJsonPath('data.birthday', '1992-04-16')
            ->assertJsonPath('data.anniversary_date', '2018-11-03')
            ->assertJsonPath('data.city', 'Mumbai')
            ->assertJsonPath('data.state', 'Maharashtra')
            ->assertJsonPath('data.postal_code', '400001');

        $this->assertDatabaseHas('contacts', array_merge(['id' => $contact->id], $this->profile()));
    }

    public function test_invalid_profile_values_are_rejected_with_field_errors(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();
        $token = $user->createToken('contacts', ['*'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/contacts', [
                'phone_e164' => '+919810000003',
                'gender' => 'inferred',
                'birthday' => '1899-12-31',
                'anniversary_date' => now()->addDay()->toDateString(),
                'city' => str_repeat('a', 129),
                'state' => str_repeat('b', 129),
                'postal_code' => str_repeat('1', 21),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['gender', 'birthday', 'anniversary_date', 'city', 'state', 'postal_code']);

        $this->withToken($token)
            ->postJson('/api/v1/contacts', [
                'phone_e164' => '+919810000004',
                'birthday' => now()->addDay()->toDateString(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['birthday']);
    }

    public function test_profile_only_update_preserves_contact_metadata_and_does_not_dispatch_work(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $tag = ContactTag::create(['workspace_id' => $workspace->id, 'name' => 'VIP']);
        $segment = Segment::create(['workspace_id' => $workspace->id, 'name' => 'Loyal', 'type' => 'static']);
        $contact = Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'email' => 'preserve@example.com',
            'source' => 'lead_form',
            'lead_id' => 123,
            'custom_fields' => ['tier' => 'gold'],
            'opt_in_whatsapp' => true,
            'opt_in_sms' => false,
            'opt_in_email' => true,
            'whatsapp_consent_at' => '2026-01-01 10:00:00',
            'whatsapp_consent_source' => 'signup',
            'whatsapp_consent_purpose' => Contact::CONSENT_PURPOSE_MARKETING,
            'whatsapp_consent_text_version' => 'v1',
            'whatsapp_consent_evidence' => ['form' => 'signup'],
            'whatsapp_opted_out_at' => null,
            'whatsapp_opt_out_source' => null,
            'digital_bill_opted_out_at' => '2026-02-01 10:00:00',
            'digital_bill_opt_out_source' => 'customer_request',
        ]);
        $contact->tags()->attach($tag);
        $contact->segments()->attach($segment);

        Queue::fake();

        $this->actingAs($user)
            ->put(route('client.contacts.update', $contact->uuid), $this->profile())
            ->assertRedirect();

        Queue::assertNothingPushed();
        $contact->refresh();

        $this->assertSame('preserve@example.com', $contact->email);
        $this->assertTrue($contact->opt_in_whatsapp);
        $this->assertFalse($contact->opt_in_sms);
        $this->assertTrue($contact->opt_in_email);
        $this->assertSame('signup', $contact->whatsapp_consent_source);
        $this->assertSame(Contact::CONSENT_PURPOSE_MARKETING, $contact->whatsapp_consent_purpose);
        $this->assertSame(['form' => 'signup'], $contact->whatsapp_consent_evidence);
        $this->assertSame('customer_request', $contact->digital_bill_opt_out_source);
        $this->assertSame('lead_form', $contact->source);
        $this->assertSame(123, $contact->lead_id);
        $this->assertSame(['tier' => 'gold'], $contact->custom_fields);
        $this->assertTrue($contact->tags->contains($tag));
        $this->assertTrue($contact->segments->contains($segment));
        $this->assertSame('female', $contact->gender);
    }

    public function test_profile_props_are_present_for_blank_and_populated_contacts_and_stay_workspace_scoped(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        ['user' => $otherUser, 'workspace' => $otherWorkspace] = $this->createWorkspaceContext();
        $blank = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $populated = Contact::factory()->create(array_merge(['workspace_id' => $workspace->id], $this->profile()));

        $this->actingAs($user)
            ->get(route('client.contacts.show', $blank->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Contacts/Show')
                ->where('contact.gender', null)
                ->where('contact.birthday', null)
                ->where('contact.anniversary_date', null)
                ->where('contact.city', null)
                ->where('contact.state', null)
                ->where('contact.postal_code', null));

        $this->actingAs($user)
            ->get(route('client.contacts.show', $populated->uuid))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('contact.gender', 'female')
                ->where('contact.city', 'Mumbai')
                ->where('contact.state', 'Maharashtra')
                ->where('contact.postal_code', '400001'));

        $this->actingAs($otherUser)
            ->get(route('client.contacts.show', $populated->uuid))
            ->assertNotFound();

        $this->actingAs($otherUser)
            ->put(route('client.contacts.update', $populated->uuid), ['city' => 'Pune'])
            ->assertNotFound();

        $this->assertDatabaseHas('contacts', ['id' => $populated->id, 'workspace_id' => $workspace->id, 'city' => 'Mumbai']);
        $this->assertDatabaseMissing('contacts', ['workspace_id' => $otherWorkspace->id, 'city' => 'Pune']);
    }

    public function test_demo_mode_masks_every_new_profile_attribute(): void
    {
        config(['app.demo_mode' => true]);
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $contact = Contact::factory()->withProfile()->create(['workspace_id' => $workspace->id]);

        $array = $contact->toArray();

        foreach (['gender', 'birthday', 'anniversary_date', 'city', 'state', 'postal_code'] as $field) {
            $this->assertNotSame((string) $contact->getRawOriginal($field), (string) $array[$field], "{$field} leaked in demo mode.");
        }

        $token = $user->createToken('contacts', [ApiAbilities::CONTACTS_READ])->plainTextToken;
        $response = $this->withToken($token)->getJson("/api/v1/contacts/{$contact->id}")->assertOk();

        foreach (['gender', 'birthday', 'anniversary_date', 'city', 'state', 'postal_code'] as $field) {
            $this->assertSame('••••••', $response->json("data.{$field}"), "{$field} leaked through the API resource in demo mode.");
        }
    }

    public function test_dynamic_segments_filter_each_profile_field_with_equality_and_inequality_inside_one_workspace(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        ['workspace' => $otherWorkspace] = $this->createWorkspaceContext();

        $matching = Contact::factory()->create(array_merge(['workspace_id' => $workspace->id], $this->profile()));
        $different = Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'gender' => 'male',
            'city' => 'Pune',
            'state' => 'Goa',
            'postal_code' => '403001',
        ]);
        $foreign = Contact::factory()->create(array_merge(['workspace_id' => $otherWorkspace->id], $this->profile()));

        foreach (['gender', 'city', 'state', 'postal_code'] as $field) {
            $equals = Segment::create([
                'workspace_id' => $workspace->id,
                'name' => "{$field} equals",
                'type' => 'dynamic',
                'rules_json' => ['combinator' => 'AND', 'conditions' => [[
                    'field' => $field,
                    'operator' => '=',
                    'value' => $matching->{$field},
                ]]],
            ]);
            $notEquals = Segment::create([
                'workspace_id' => $workspace->id,
                'name' => "{$field} not equals",
                'type' => 'dynamic',
                'rules_json' => ['combinator' => 'AND', 'conditions' => [[
                    'field' => $field,
                    'operator' => '!=',
                    'value' => $matching->{$field},
                ]]],
            ]);

            $equalIds = WorkspaceContext::for($workspace->id, fn () => app(SegmentResolver::class)->query($equals)->pluck('id')->all());
            $notEqualIds = WorkspaceContext::for($workspace->id, fn () => app(SegmentResolver::class)->query($notEquals)->pluck('id')->all());

            $this->assertSame([$matching->id], $equalIds, "{$field} equality did not remain workspace-scoped.");
            $this->assertSame([$different->id], $notEqualIds, "{$field} inequality did not remain workspace-scoped.");
            $this->assertNotContains($foreign->id, $equalIds);
            $this->assertNotContains($foreign->id, $notEqualIds);
        }
    }
}

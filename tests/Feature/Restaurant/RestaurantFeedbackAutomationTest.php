<?php

namespace Tests\Feature\Restaurant;

use App\Models\Workspace;
use App\Modules\Restaurant\Models\PosConnection;
use App\Modules\Restaurant\Models\RestaurantBill;
use App\Modules\Restaurant\Models\RestaurantFeedbackDeliveryConfig;
use App\Modules\Restaurant\Models\RestaurantFeedbackRequest;
use App\Modules\Restaurant\Models\RestaurantOutlet;
use App\Modules\Restaurant\Services\RestaurantFeedbackDeliveryService;
use App\Modules\Shared\Models\Contact;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappPhoneNumber;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RestaurantFeedbackAutomationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function one_eligible_bill_creates_one_scheduled_feedback_request(): void
    {
        $records = $this->records();

        WorkspaceContext::for($records['workspace']->id, function () use ($records): void {
            app(RestaurantFeedbackDeliveryService::class)->schedule($records['bill']->id);
            app(RestaurantFeedbackDeliveryService::class)->schedule($records['bill']->id);
        });

        $this->assertDatabaseCount('restaurant_feedback_requests', 1);
        $this->assertDatabaseHas('restaurant_feedback_requests', [
            'workspace_id' => $records['workspace']->id,
            'restaurant_bill_id' => $records['bill']->id,
            'purpose' => 'feedback_request',
            'status' => 'scheduled',
        ]);
    }

    #[Test]
    public function a_due_request_sends_only_the_opaque_url_token_and_requires_a_provider_message_id(): void
    {
        $records = $this->records();
        WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantFeedbackDeliveryService::class)->schedule($records['bill']->id));
        $request = WorkspaceContext::for($records['workspace']->id, fn () => RestaurantFeedbackRequest::query()->firstOrFail());
        WorkspaceContext::for($records['workspace']->id, fn () => $request->update(['status' => RestaurantFeedbackRequest::STATUS_PENDING]));
        Http::fake(fn (Request $http) => Http::response(['messages' => [['id' => 'wamid.feedback']]], 200));

        WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantFeedbackDeliveryService::class)->send($request->id));

        $this->assertSame(RestaurantFeedbackRequest::STATUS_SENT, WorkspaceContext::for($records['workspace']->id, fn () => $request->fresh()->status));
        Http::assertSent(function (Request $http) use ($request): bool {
            $payload = json_encode($http->data()) ?: '';

            return str_contains($payload, $request->public_token);
        });
    }

    #[Test]
    public function public_feedback_is_generic_when_unavailable_and_low_rating_is_idempotently_alerted(): void
    {
        $records = $this->records();
        WorkspaceContext::for($records['workspace']->id, fn () => app(RestaurantFeedbackDeliveryService::class)->schedule($records['bill']->id));
        $request = WorkspaceContext::for($records['workspace']->id, fn () => RestaurantFeedbackRequest::query()->firstOrFail());
        WorkspaceContext::for($records['workspace']->id, fn () => $request->update(['status' => RestaurantFeedbackRequest::STATUS_SENT, 'sent_at' => now()]));

        $this->get(route('public.restaurant.feedback.show', $request->public_token))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('+919876543210');
        $this->post(route('public.restaurant.feedback.submit', $request->public_token), ['rating' => 2, 'comment' => 'Private note'])->assertRedirect();
        $this->post(route('public.restaurant.feedback.submit', $request->public_token), ['rating' => 2, 'comment' => 'Duplicate'])->assertRedirect();
        $this->assertDatabaseCount('restaurant_feedback_alerts', 1);
        $this->assertSame('Private note', WorkspaceContext::for($records['workspace']->id, fn () => $request->fresh()->customer_comment));
        $this->get('/f/'.str_repeat('a', 64))->assertNotFound();
        WorkspaceContext::for($records['workspace']->id, fn () => $request->update(['revoked_at' => now()]));
        $this->get(route('public.restaurant.feedback.show', $request->public_token))->assertNotFound();
    }

    /** @return array{workspace: Workspace, bill: RestaurantBill} */
    private function records(): array
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $outlet = RestaurantOutlet::factory()->create([
            'workspace_id' => $workspace->id,
            'feedback_request_enabled' => true,
            'timezone' => 'Asia/Kolkata',
        ]);
        $connection = PosConnection::factory()->create(['workspace_id' => $workspace->id, 'outlet_id' => $outlet->id]);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'phone_e164' => '+919876543210']);
        $bill = RestaurantBill::factory()->create([
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'outlet_id' => $outlet->id,
            'contact_id' => $contact->id,
            'source_order_status' => 'Success',
            'placed_at' => now(),
        ]);
        $waba = WhatsappBusinessAccount::factory()->create(['workspace_id' => $workspace->id, 'credentials' => ['system_user_token' => 'token'], 'status' => 'active']);
        $sender = WhatsappPhoneNumber::query()->create(['waba_id_fk' => $waba->id, 'phone_number_id' => 'feedback-'.uniqid()]);
        $template = WhatsappTemplate::query()->create([
            'workspace_id' => $workspace->id,
            'waba_id' => $waba->waba_id,
            'name' => 'feedback-'.uniqid(),
            'language' => 'en',
            'category' => 'UTILITY',
            'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Please share feedback'], ['type' => 'BUTTONS', 'buttons' => [['type' => 'URL', 'url' => 'https://automationxpert.in/f/{{1}}']]]],
        ]);
        RestaurantFeedbackDeliveryConfig::query()->create([
            'workspace_id' => $workspace->id,
            'outlet_id' => $outlet->id,
            'whatsapp_phone_number_id' => $sender->id,
            'whatsapp_template_id' => $template->id,
            'timing_preference' => RestaurantFeedbackDeliveryConfig::TIMING_IMMEDIATELY,
        ]);

        return compact('workspace', 'bill');
    }
}

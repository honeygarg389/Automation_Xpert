<?php

namespace Tests\Feature;

use App\Events\AutomationFailed;
use App\Events\AutomationWebhookReceived;
use App\Events\CampaignCompleted;
use App\Events\CommerceEventReceived;
use App\Events\ContactCreated;
use App\Events\ConversationAssigned;
use App\Events\MessageReceived;
use App\Events\PlanChanged;
use App\Events\SubscriptionCancelled;
use App\Events\SubscriptionExpired;
use App\Events\SubscriptionRenewed;
use App\Events\SubscriptionStarted;
use App\Events\TrialEnding;
use App\Jobs\DispatchWebhookJob;
use App\Models\AuditLog;
use App\Models\WebhookEndpoint;
use App\Modules\Automation\Jobs\ExecuteAutomationRunJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Shared\Models\Contact;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Events\DiscoverEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ⚠️ THE BUG: Laravel's event auto-discovery (on by default —
 * `Illuminate\Foundation\Configuration\ApplicationBuilder` registers the
 * framework's `EventServiceProvider` unconditionally unless `bootstrap/app.php`
 * calls `->withEvents(discover: false)`) scans `app/Listeners` and
 * auto-registers every public `handle*`/`__invoke` method whose first
 * parameter is a class. Every listener living directly in `app/Listeners`
 * ALSO had an explicit `Event::listen(...)` call in
 * `App\Providers\AppServiceProvider::boot()` — so every one of them fired
 * TWICE per real event: two automation runs, two outbound webhook jobs, two
 * audit-log rows per login, etc. Confirmed via `php artisan event:list` (each
 * entry appeared twice) before the fix, and via
 * `app('events')->getListeners($event)` returning double the real listener
 * count.
 *
 * THE FIX: `bootstrap/app.php` now calls `->withEvents(discover: false)`.
 * Listeners under `app/Modules/*\/Listeners` (RecordQrAttributionListener,
 * InvalidateEntitlementCache) were never inside the discovery path
 * (`app/Listeners` only) and were single-registered before and after.
 *
 * BEFORE DISABLING DISCOVERY, every method in every app/Listeners class was
 * inventoried against AppServiceProvider::boot()'s explicit registrations
 * (see the table in dataProvider() below). Exactly ONE method —
 * `AutomationTriggerListener::handleCampaignCompleted` — had no explicit
 * registration and relied on discovery alone; it now has one, added in the
 * same commit as the discovery-disabling line, so nothing lost its only
 * wiring. `tests/Feature/ContactImportPhoneNormalizationTest.php`'s
 * `double_listener_registration_cannot_manifest_as_duplicate_automation_runs_during_import`
 * test — which originally documented this as a deliberate non-fix on a
 * different branch — is updated on this branch to pin the corrected count.
 */
class EventListenerRegistrationTest extends TestCase
{
    use RefreshDatabase;

    // ══ 1. Every audited event/listener pair is registered EXACTLY ONCE ═

    /**
     * The full inventory. Each row is (event class, expected listener count)
     * where the count is the number of DISTINCT real listener classes wired
     * to that event — i.e. what `AppServiceProvider::boot()` (plus, for the
     * two module listeners, their own service providers) explicitly
     * registers. Before the fix every app/Listeners-backed row here read
     * DOUBLE this number; after the fix it reads exactly this number.
     *
     * @return array<string, array{0: class-string, 1: int}>
     */
    public static function registeredEventsProvider(): array
    {
        return [
            // Contact-domain events — the ones the bug report named.
            'ContactCreated: AutomationTriggerListener + DispatchOutboundWebhookListener' => [ContactCreated::class, 2],
            'MessageReceived: AutomationTrigger + AutoReply + OutboundWebhook + SmartQr + NewMessageNotification' => [MessageReceived::class, 5],
            'CampaignCompleted: AutomationTrigger (now explicit) + OutboundWebhook + CampaignCompletedNotification' => [CampaignCompleted::class, 3],
            'AutomationWebhookReceived: AutomationTriggerListener' => [AutomationWebhookReceived::class, 1],
            'CommerceEventReceived: AutomationTriggerListener' => [CommerceEventReceived::class, 1],
            'AutomationFailed: SendAutomationFailedNotification' => [AutomationFailed::class, 1],
            'ConversationAssigned: SendConversationAssignedNotification' => [ConversationAssigned::class, 1],

            // Billing/entitlement events — application-wide, not contact-shaped,
            // and each one also proves the module-listener (InvalidateEntitlementCache)
            // stayed single-registered throughout, since it was never in the
            // auto-discovery path to begin with.
            'PlanChanged: SendPlanChangedNotification + InvalidateEntitlementCache' => [PlanChanged::class, 2],
            'SubscriptionStarted: SendSubscriptionStartedNotification + InvalidateEntitlementCache' => [SubscriptionStarted::class, 2],
            'SubscriptionCancelled: SendSubscriptionCancelledNotification + InvalidateEntitlementCache' => [SubscriptionCancelled::class, 2],
            'SubscriptionExpired: SendSubscriptionExpiredNotification + InvalidateEntitlementCache' => [SubscriptionExpired::class, 2],
            'SubscriptionRenewed: SendSubscriptionRenewedNotification + InvalidateEntitlementCache' => [SubscriptionRenewed::class, 2],
            'TrialEnding: SendTrialEndingNotification' => [TrialEnding::class, 1],

            // Framework auth events — proves this is an APPLICATION-WIDE fix,
            // not a contact- or even a domain-event-only patch. Registered
            // via Illuminate\Support\Facades\Event, exactly like every other
            // row here, and were just as duplicated by discovery.
            'Login: LogSuccessfulLogin' => [Login::class, 1],
            'Registered: SendWelcomeNotification (+ framework email verification, untouched)' => [Registered::class, 2],
        ];
    }

    #[Test]
    #[DataProvider('registeredEventsProvider')]
    public function each_audited_event_has_its_listeners_registered_exactly_once(string $event, int $expectedCount): void
    {
        $actual = count(app('events')->getListeners($event));

        $this->assertSame(
            $expectedCount,
            $actual,
            "Expected exactly {$expectedCount} listener(s) on {$event}, found {$actual}. ".
            'A higher count means the double-registration bug (auto-discovery + explicit '.
            'Event::listen both firing) has returned; a lower count means a listener was lost.'
        );
    }

    /**
     * The same proof from the other direction: walk every concrete listener
     * class under app/Listeners (the ONLY directory Laravel's default
     * discovery path scans) and confirm each of its handle*-or-__invoke
     * methods is registered on its event exactly once — not merely that SOME event
     * has the right total (which registeredEventsProvider proves), but that
     * THIS SPECIFIC callable appears once, closing the door on a
     * mis-counted total hiding a lost-then-regained listener.
     */
    #[Test]
    public function every_app_listeners_method_is_registered_exactly_once_by_its_own_callable(): void
    {
        $directory = app_path('Listeners');
        $discovered = DiscoverEvents::within($directory, base_path());

        $this->assertNotEmpty($discovered, 'Sanity check: app/Listeners must contain at least one discoverable listener method.');

        foreach ($discovered as $event => $listeners) {
            foreach ($listeners as $listenerCallable) {
                [$class, $method] = array_pad(explode('@', $listenerCallable), 2, 'handle');

                // getRawListeners() preserves the original registration form
                // rather than the Closure Laravel wraps every listener in for
                // dispatch, so the class/method identity is directly visible
                // without needing to invoke anything. THREE forms occur here:
                //   - array [class, method]                         — AppServiceProvider's `[X::class, 'handleY']`
                //   - string "Class@method"                         — auto-discovery's own registration form
                //   - bare string "Class" (implies ->handle())      — AppServiceProvider's `SomeListener::class`
                // Collapsing the middle case into the third (as an earlier,
                // buggy version of this test did, matching only "Class" and
                // appending '@handle' unconditionally) makes the discovered,
                // auto-registered entry invisible to the comparison — the
                // assertion below would then pass at count 1 even with BOTH
                // discovery and the explicit registration active, because it
                // was only ever seeing the explicit one. Verified: with
                // discovery re-enabled (bootstrap/app.php's `->withEvents(discover:
                // false)` temporarily removed), the unfixed matcher still
                // passed here while every other test in this file correctly
                // failed — a vacuous assertion. Explicitly branching on
                // whether the string already contains '@' is what makes this
                // discriminate.
                $raw = app('events')->getRawListeners()[$event] ?? [];
                $classMethodMatches = 0;
                foreach ($raw as $rawListener) {
                    if (is_array($rawListener)) {
                        $identity = (is_string($rawListener[0]) ? $rawListener[0] : get_class($rawListener[0])).'@'.$rawListener[1];
                    } elseif (is_string($rawListener) && str_contains($rawListener, '@')) {
                        $identity = $rawListener;
                    } elseif (is_string($rawListener)) {
                        $identity = $rawListener.'@handle';
                    } else {
                        $identity = null;
                    }

                    if ($identity === $class.'@'.$method) {
                        $classMethodMatches++;
                    }
                }

                $this->assertSame(
                    1,
                    $classMethodMatches,
                    "{$class}@{$method} (for {$event}) must be registered exactly once; found {$classMethodMatches}."
                );
            }
        }
    }

    // ══ 2. A normal contact creation triggers each downstream action once ═

    #[Test]
    public function a_normal_contact_creation_triggers_automation_and_outbound_webhook_exactly_once(): void
    {
        Queue::fake();

        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        Automation::create([
            'workspace_id' => $workspace->id,
            'name' => 'Welcome new contacts',
            'status' => 'active',
            'trigger_type' => 'contact.created',
            'nodes' => [],
            'edges' => [],
        ]);

        WebhookEndpoint::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'url' => 'https://example.test/webhooks/contact-created',
            'secret' => WebhookEndpoint::generateSecret(),
            'events' => ['contact.created'],
            'enabled' => true,
        ]);

        // The real dashboard "Add Contact" path — ContactController::store()
        // — with no import/suppression involved, so ContactCreated dispatches
        // normally and both listeners run synchronously within the request.
        $this->actingAs($user)->post(route('client.contacts.store'), [
            'first_name' => 'Honey',
            'phone_e164' => '+918630026099',
        ])->assertRedirect();

        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count());

        // Automation: exactly one run, exactly one queued execution — not two.
        $this->assertSame(
            1,
            AutomationRun::count(),
            'Exactly one AutomationRun must exist for one ContactCreated dispatch with one matching automation — double-registration would create two.'
        );
        Queue::assertPushed(ExecuteAutomationRunJob::class, 1);

        // Outbound webhook: exactly one job for the one subscribed endpoint.
        Queue::assertPushed(DispatchWebhookJob::class, 1);
    }

    // ══ 3. CSV and XLSX bulk import still dispatch nothing ══════════════

    /**
     * Belt-and-suspenders alongside
     * ContactImportPhoneNormalizationTest::importing_dispatches_no_outbound_messaging_work()
     * — that test proves the import-suppression fix in isolation; this one
     * proves it still holds true with discovery disabled and the
     * previously-discovery-only handleCampaignCompleted now explicitly wired,
     * i.e. that THIS fix did not accidentally turn suppression back on.
     */
    #[Test]
    public function csv_import_dispatches_no_contact_created_automation_webhook_or_queued_job(): void
    {
        Queue::fake();

        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        Automation::create([
            'workspace_id' => $workspace->id,
            'name' => 'Welcome new contacts',
            'status' => 'active',
            'trigger_type' => 'contact.created',
            'nodes' => [],
            'edges' => [],
        ]);

        WebhookEndpoint::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'url' => 'https://example.test/webhooks/contact-created',
            'secret' => WebhookEndpoint::generateSecret(),
            'events' => ['contact.created'],
            'enabled' => true,
        ]);

        $this->actingAs($user)->post(route('client.contacts.import'), [
            'file' => $this->csvUpload(['Phone'], [['9810000101']]),
            'default_country' => 'IN',
        ])->assertRedirect();

        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count());
        $this->assertSame(0, AutomationRun::count());
        Queue::assertNotPushed(ExecuteAutomationRunJob::class);
        Queue::assertNotPushed(DispatchWebhookJob::class);
    }

    #[Test]
    public function xlsx_grid_bulk_import_dispatches_no_contact_created_automation_webhook_or_queued_job(): void
    {
        Queue::fake();

        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        Automation::create([
            'workspace_id' => $workspace->id,
            'name' => 'Welcome new contacts',
            'status' => 'active',
            'trigger_type' => 'contact.created',
            'nodes' => [],
            'edges' => [],
        ]);

        WebhookEndpoint::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'url' => 'https://example.test/webhooks/contact-created',
            'secret' => WebhookEndpoint::generateSecret(),
            'events' => ['contact.created'],
            'enabled' => true,
        ]);

        $this->actingAs($user)->post(route('client.contacts.bulk-store'), [
            'default_country' => 'IN',
            'rows' => [[
                'name' => null,
                'phone_e164' => '9810000102',
                'tag_id' => null,
                'segment_id' => null,
                'gender' => null,
                'birthday' => null,
                'anniversary_date' => null,
                'city' => null,
                'state' => null,
                'postal_code' => null,
            ]],
        ])->assertOk();

        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count());
        $this->assertSame(0, AutomationRun::count());
        Queue::assertNotPushed(ExecuteAutomationRunJob::class);
        Queue::assertNotPushed(DispatchWebhookJob::class);
    }

    // ══ 4. Non-contact proof: a real Login fires LogSuccessfulLogin once ═

    /**
     * Application-wide, not contact-only: `Illuminate\Auth\Events\Login` is a
     * FRAMEWORK event, registered the same way as every event above
     * (`Event::listen(Login::class, LogSuccessfulLogin::class)` in
     * AppServiceProvider), and was just as duplicated by discovery —
     * `LogSuccessfulLogin::handle()` writes exactly one `AuditLog` row per
     * dispatch, so a double-registration here is directly visible as two
     * rows instead of one.
     */
    #[Test]
    public function a_real_login_writes_exactly_one_audit_log_row(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $before = AuditLog::where('action', 'auth.login')->count();

        event(new Login('web', $user, false));

        $after = AuditLog::where('action', 'auth.login')->count();

        $this->assertSame(
            1,
            $after - $before,
            'Exactly one AuditLog row must be written per Login dispatch — double-registration would write two.'
        );
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    private function csvUpload(array $headers, array $rows): UploadedFile
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            fputcsv($stream, $row);
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return UploadedFile::fake()->createWithContent('contacts.csv', $content);
    }
}

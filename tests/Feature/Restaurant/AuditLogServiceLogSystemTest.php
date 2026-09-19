<?php

namespace Tests\Feature\Restaurant;

use App\Models\AuditLog;
use App\Models\Workspace;
use App\Modules\Restaurant\Models\PosWebhookEvent;
use App\Services\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `AuditLogService::logSystem()` — the worker-originated counterpart to
 * `logAdmin()`. `log()`/`logAdmin()` both read `request()->user()`, which is
 * meaningless in a queue worker; `logSystem()` must record NO actor and NO
 * request fields rather than fabricate an HTTP request, and attribute the
 * entry to the workspace instead.
 */
class AuditLogServiceLogSystemTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_records_a_workspace_attributed_entry_with_no_actor_and_no_request_fields(): void
    {
        $workspace = Workspace::factory()->create();
        $event = PosWebhookEvent::factory()->create(['workspace_id' => $workspace->id]);

        $entry = app(AuditLogService::class)->logSystem(
            'restaurant.bill.processed',
            $event,
            $workspace->id,
            ['restaurant_bill_id' => 7],
        );

        $fresh = AuditLog::query()->findOrFail($entry->id);

        $this->assertSame($workspace->id, $fresh->workspace_id);
        $this->assertSame('restaurant.bill.processed', $fresh->action);
        $this->assertSame($event->getMorphClass(), $fresh->auditable_type);
        $this->assertSame((int) $event->id, (int) $fresh->auditable_id);
        $this->assertSame(['restaurant_bill_id' => 7], $fresh->meta);
        $this->assertNull($fresh->actor_admin_id, 'A worker has no admin actor.');
        $this->assertNull($fresh->user_id, 'A worker has no client-user actor.');
        $this->assertNull($fresh->ip, 'No HTTP request exists — none may be fabricated.');
        $this->assertNull($fresh->user_agent);
        $this->assertNull($fresh->url);
    }

    #[Test]
    public function it_accepts_a_null_auditable_for_entries_about_no_particular_row(): void
    {
        $workspace = Workspace::factory()->create();

        $entry = app(AuditLogService::class)->logSystem('restaurant.sweep.ran', null, $workspace->id);

        $fresh = AuditLog::query()->findOrFail($entry->id);
        $this->assertNull($fresh->auditable_type);
        $this->assertNull($fresh->auditable_id);
        $this->assertNull($fresh->meta);
    }
}

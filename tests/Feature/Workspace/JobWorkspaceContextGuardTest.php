<?php

namespace Tests\Feature\Workspace;

use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * Phase 0, slice 4. Every queued job that reaches tenant data must declare how
 * it establishes a workspace.
 *
 * ─── Why this list is not the one a grep produces ───────────────────────────
 *
 * The obvious inventory is "jobs that mention a scoped model". That misses
 * three, and they are the dangerous ones — they reach tenant data through a
 * service instead of naming a model:
 *
 *   ProcessInboundMessageJob(payload, verifyToken)
 *       -> WhatsappDriver, which resolves ChannelAccount from the payload and
 *          then writes Contact and Conversation. The inbound message path.
 *   ProcessInboundInboxMessageJob(payload, object)
 *       -> Messenger/Instagram drivers. Same shape.
 *   ExecuteAutomationRunJob(runId)
 *       -> AutomationRun (unscoped) -> AutomationEngine, which touches 8 scoped
 *          models.
 *
 * So the list below is maintained by hand, deliberately. An automated
 * "mentions a model" check would have produced a guard that looked complete and
 * silently omitted the webhook path.
 */
class JobWorkspaceContextGuardTest extends TestCase
{
    /**
     * Jobs that reach tenant data and MUST declare middleware().
     *
     * @var list<class-string>
     */
    private const REQUIRES_CONTEXT = [
        // ── Group A: workspace derivable from the job's own payload ──
        'App\Modules\Broadcasting\Jobs\LaunchCampaignJob',
        'App\Modules\Broadcasting\Jobs\DispatchCampaignChunkJob',
        'App\Modules\Broadcasting\Jobs\SendCampaignMessageJob',
        'App\Modules\Broadcasting\Jobs\FinalizeCampaignJob',
        'App\Modules\Ecommerce\Jobs\BackfillStoreOrdersJob',
        'App\Modules\Ecommerce\Jobs\SyncStoreCustomersJob',
        'App\Modules\Ecommerce\Jobs\SyncStoreProductsJob',
        'App\Modules\Ecommerce\Jobs\RegisterStoreWebhooksJob',
        'App\Modules\Ecommerce\Jobs\ProcessEcommerceWebhookJob',
        'App\Modules\Ecommerce\Jobs\CheckAbandonedCartJob',
        'App\Modules\Leads\Jobs\ScrapeLeadsJob',
        'App\Modules\Social\Jobs\PublishSocialPostJob',
        'App\Modules\Whatsapp\Jobs\TemplateSyncJob',

        // ── Group B: cross-tenant BY DESIGN, declared as such ──
        'App\Modules\Broadcasting\Jobs\LaunchScheduledCampaignsJob',
        'App\Modules\Social\Jobs\DispatchScheduledPostsJob',
        'App\Modules\Social\Jobs\RefreshSocialTokensJob',
    ];

    /**
     * ═══ SLICE 4c WORK QUEUE ═══
     *
     * Group C. These reach tenant data through a SERVICE, not a model, so the
     * workspace comes from inside a payload rather than from an id the job
     * carries. Two sit on the inbound webhook path, which is hazard H-3's shape
     * and where CLAUDE.md forbids building a parallel flow.
     *
     * Deferred to slice 4c on purpose: they need their own review, not a line
     * in a 19-job commit. Delete each entry in the commit that handles it.
     *
     * @var list<class-string>
     */
    private const PENDING_SLICE_4C = [
        'App\Modules\Whatsapp\Jobs\ProcessInboundMessageJob',
        'App\Modules\Inbox\Jobs\ProcessInboundInboxMessageJob',
        'App\Modules\Automation\Jobs\ExecuteAutomationRunJob',
    ];

    /**
     * Jobs that touch no tenant data at all.
     *
     * `GenerateWorkspaceExportJob` is listed because it solves this problem its
     * own way — it carries `?int $workspaceId` explicitly and already throws
     * MissingWorkspaceContextException. It predates this middleware (BUG-008)
     * and works; converting it is not a Phase 0 requirement.
     *
     * @var list<class-string>
     */
    private const NO_TENANT_DATA = [
        'App\Jobs\DispatchWebhookJob',
        'App\Jobs\GenerateWorkspaceExportJob',
        'App\Modules\AI\Jobs\IndexDocumentJob',
    ];

    #[Test]
    public function every_job_that_reaches_tenant_data_declares_how_it_gets_a_workspace(): void
    {
        $missing = [];

        foreach (self::REQUIRES_CONTEXT as $class) {
            if (! method_exists($class, 'middleware')) {
                $missing[] = $class;
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            '',
            'These jobs reach tenant data but do not declare middleware():',
            implode("\n", array_map(fn ($c) => "  {$c}", $missing)),
            '',
            'Add ONE of:',
            '  EstablishesWorkspaceContext::from(Model::class, $this->someId)',
            '  EstablishesWorkspaceContext::crossTenant(\'reason: …\')',
            '',
            'Without it the job runs with a null context, the scope matches nothing, and the',
            'job succeeds having done nothing at all. That silence is the whole problem.',
            '',
        ]));
    }

    /**
     * Every job class in the codebase must be classified. This is the test that
     * catches a NEW job — the case nobody will be thinking about later.
     */
    #[Test]
    public function every_queued_job_is_classified(): void
    {
        $known = [...self::REQUIRES_CONTEXT, ...self::PENDING_SLICE_4C, ...self::NO_TENANT_DATA];

        $unclassified = array_values(array_diff($this->discoverJobs(), $known));

        $this->assertSame([], $unclassified, implode("\n", [
            '',
            'A queued job exists that no one has classified:',
            implode("\n", array_map(fn ($c) => "  {$c}", $unclassified)),
            '',
            'Decide which it is and add it to REQUIRES_CONTEXT, PENDING_SLICE_4C, or',
            'NO_TENANT_DATA. "Does it touch tenant data?" must be answered when the job is',
            'written, not discovered when it silently processes nothing.',
            '',
        ]));
    }

    #[Test]
    public function the_lists_name_only_classes_that_exist(): void
    {
        $missing = array_values(array_filter(
            [...self::REQUIRES_CONTEXT, ...self::PENDING_SLICE_4C, ...self::NO_TENANT_DATA],
            fn (string $c) => ! class_exists($c)
        ));

        $this->assertSame([], $missing, 'Stale entries: '.implode(', ', $missing));
    }

    /**
     * The slice-4c queue must be honest — an entry already handled is a stale
     * work queue, and a work queue nobody trusts is one nobody reads.
     */
    #[Test]
    public function the_slice_4c_queue_lists_nothing_already_handled(): void
    {
        $done = array_values(array_filter(
            self::PENDING_SLICE_4C,
            fn (string $c) => class_exists($c) && method_exists($c, 'middleware')
        ));

        $this->assertSame([], $done,
            'These declare middleware() but are still listed as pending slice 4c. Move them '
            .'to REQUIRES_CONTEXT in the same commit: '.implode(', ', $done));
    }

    /** @return list<class-string> */
    private function discoverJobs(): array
    {
        $jobs = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() !== 'php' || ! str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Jobs'.DIRECTORY_SEPARATOR)) {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (! preg_match('/^namespace\s+([^;]+);/m', $source, $ns)) {
                continue;
            }
            if (! preg_match('/^(?:final\s+)?(?:abstract\s+)?class\s+(\w+)/m', $source, $cn)) {
                continue;
            }

            $class = $ns[1].'\\'.$cn[1];

            if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class)) {
                continue;
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $jobs[] = $class;
        }

        sort($jobs);

        return $jobs;
    }
}

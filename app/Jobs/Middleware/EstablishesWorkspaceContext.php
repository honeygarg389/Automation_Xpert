<?php

namespace App\Jobs\Middleware;

use App\Exceptions\MissingWorkspaceContextException;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Scopes\WorkspaceScope;
use App\Support\WorkspaceContext;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 0, slice 4. Establishes tenant context for a queued job.
 *
 * ─── The chicken-and-egg this exists to solve ───────────────────────────────
 *
 * Every worker job resolves its own workspace by loading a scoped model as its
 * FIRST statement:
 *
 *     $campaign = Campaign::find($this->campaignId);
 *     if (! $campaign) { return; }              // <- silent no-op
 *
 * Once `Campaign` is scoped, that lookup needs a workspace context in order to
 * find the row that would tell it the workspace. With no context the scope
 * matches nothing, `find()` returns null, and all 13 worker jobs take an early
 * `return`. Not an exception, not a log: the job succeeds, does nothing, and
 * leaves the queue clean.
 *
 * So the resolution CANNOT go through a normal query. This middleware performs
 * exactly one deliberately unscoped lookup — the id → workspace_id read — and
 * runs the entire job inside `WorkspaceContext::for()`.
 *
 * ─── The bypass is one QUERY wide, not one JOB wide ─────────────────────────
 *
 * The `withoutWorkspaceScope()` call lives here and nowhere else. Jobs name a
 * model class and a key; they never write a bypass of their own. That keeps the
 * inventory at ONE sanctioned entry instead of thirteen, and it means a job
 * author cannot widen the bypass beyond the single column read.
 *
 * The same rule was set for the WhatsApp webhook token lookup (hazard H-3): the
 * bypass covers the query that discovers the workspace, not the work that
 * follows it.
 *
 * ─── ⚠️ WHAT THIS DOES NOT PROTECT AGAINST ──────────────────────────────────
 *
 * A WRONG dispatch. The lookup below trusts the key it was handed. Dispatch
 * `SendCampaignMessageJob` with another tenant's campaign id and this will
 * faithfully establish that tenant's context and do the work.
 *
 * The scope protects against a MISSING workspace, never a wrong one. "We have a
 * global scope now" does not cover it — the controller sites that decide which
 * ids reach a dispatch are still what make that correct. See docs/found-bugs.md.
 */
final class EstablishesWorkspaceContext
{
    /**
     * @param  class-string<Model>|null  $modelClass  The model carrying workspace_id
     * @param  int|string|null  $key  Its primary key, from the job's own payload
     */
    private function __construct(
        private readonly ?string $modelClass,
        private readonly int|string|null $key,
        private readonly ?string $crossTenantReason,
    ) {}

    /**
     * Derive the workspace from a model the job already identifies.
     *
     * Usage, in the job:
     *
     *     public function middleware(): array
     *     {
     *         return [EstablishesWorkspaceContext::from(Campaign::class, $this->campaignId)];
     *     }
     *
     * @param  class-string<Model>  $modelClass
     */
    public static function from(string $modelClass, int|string|null $key): self
    {
        return new self($modelClass, $key, null);
    }

    /**
     * Declare that this job is cross-tenant BY DESIGN and must not have a
     * workspace context.
     *
     * For the schedulers, whose entire function is to scan every workspace for
     * due work. The reason is required and appears at the call site so that a
     * cross-tenant job reads as a decision rather than as someone forgetting to
     * add context — which is exactly what it would otherwise look like.
     */
    public static function crossTenant(string $reason): self
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('crossTenant() requires a reason.');
        }

        return new self(null, null, $reason);
    }

    public function handle(object $job, Closure $next): mixed
    {
        if ($this->crossTenantReason !== null) {
            // Declared cross-tenant. NOT the same as "no context": the scope
            // fails closed, so running a scheduler with no context would give it
            // ZERO due campaigns rather than every tenant's — the exact silent
            // no-op this middleware exists to abolish. It has to actively
            // suppress the scope for the duration of the job.
            return WorkspaceContext::crossTenant($this->crossTenantReason, fn () => $next($job));
        }

        $workspaceId = $this->resolveWorkspaceId();

        if ($workspaceId === null) {
            // Loudly. A job that cannot establish tenancy must land in
            // failed_jobs with a named exception, not succeed having done
            // nothing — that silence is the failure mode this slice exists to
            // remove.
            throw MissingWorkspaceContextException::forQueuedJob($job::class, $this->modelClass, $this->key);
        }

        return WorkspaceContext::for($workspaceId, fn () => $next($job));
    }

    /**
     * THE one sanctioned bypass. Reads a single column from a single row.
     *
     * ─── Why the NATIVE spelling here, of all places ────────────────────────
     *
     * Application code must use `withoutWorkspaceScope('reason: …')`, whose
     * required argument is the whole point. This is not application code — it
     * is the mechanism, and `$modelClass` is a `class-string<Model>`, so the
     * trait's local scope is invisible to static analysis on the resulting
     * generic Builder. Writing it the sanctioned way produces a real PHPStan
     * `method.notFound`, and silencing that with an ignore would be worse than
     * the thing it hides.
     *
     * The reason still exists — it is the constant below, and this file is
     * listed in the bypass inventory with its justification, so the bypass is
     * counted either way. The guard greps for BOTH spellings precisely so this
     * choice cannot become a way to hide.
     *
     * The trait check is not defensive clutter: slices 5-8 apply
     * BelongsToWorkspace model by model, so during that window some of these
     * classes are scoped and some are not.
     */
    private function resolveWorkspaceId(): ?int
    {
        if ($this->modelClass === null || $this->key === null) {
            return null;
        }

        $query = $this->modelClass::query();

        if (in_array(BelongsToWorkspace::class, class_uses_recursive($this->modelClass), true)) {
            // reason: resolving a queued job's tenant from its own payload. The
            // lookup that establishes context cannot itself require context.
            $query = $query->withoutGlobalScope(WorkspaceScope::class);
        }

        $workspaceId = $query->whereKey($this->key)->value('workspace_id');

        return $workspaceId === null ? null : (int) $workspaceId;
    }
}

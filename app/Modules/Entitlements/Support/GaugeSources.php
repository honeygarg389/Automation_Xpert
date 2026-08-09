<?php

namespace App\Modules\Entitlements\Support;

use App\Models\User;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\Automation\Models\Automation;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappTemplate;

/**
 * ⚠️ WHERE A GAUGE'S COUNT COMES FROM — model, and TENANT BOUNDARY.
 *
 * A gauge is "how many exist right now", answered by COUNT(*) against the owning
 * table at request time. Never by `usage_meters`: a counter is per-period and
 * only ever increases within one, while a gauge must go DOWN when a row is
 * deleted. That is BUG-024 — nine cardinality limits checked against a
 * period-keyed counter nothing increments, so they read 0 forever.
 *
 * ─── ⚠️ THE COLUMN IS PART OF THE DECLARATION, NOT AN ASSUMPTION ────────────
 *
 * `users` and `inbox_agents` are the SAME TABLE with DIFFERENT boundaries. A
 * seat limit belongs to the organisation that holds the plan, so `users` counts
 * by `client_id`; counting it by `workspace_id` would give a client with three
 * workspaces three times its seats. Getting that backwards is a tenant boundary
 * error, not a counting error, which is why `scope` is declared per key rather
 * than inferred.
 *
 * ─── ⚠️ social_accounts IS THE NAME COLLISION CLAUDE.md PREDICTED ───────────
 *
 * `App\Models\SocialAccount`               -> `social_accounts`       (OAuth logins)
 * `App\Modules\Social\Models\SocialAccount` -> `social_media_accounts` (publishing)
 *
 * Two different models, same class basename. The limit means the second. A wrong
 * import counts login providers as publishing accounts — which is the exact
 * wrong-import bug CLAUDE.md warned this collision would eventually cause. The
 * FQCN is stored here, and GaugeSourcesTest asserts the resolved TABLE, because
 * asserting the class name would pass for either.
 */
final class GaugeSources
{
    public const SCOPE_WORKSPACE = 'workspace';

    public const SCOPE_CLIENT = 'client';

    /**
     * @var array<string, array{model: class-string, scope: string}>
     */
    public const MAP = [
        // ⚠️ CLIENT-scoped. Seats belong to the organisation holding the plan.
        'users' => [
            'model' => User::class,
            'scope' => self::SCOPE_CLIENT,
        ],

        'whatsapp_accounts' => [
            'model' => WhatsappBusinessAccount::class,
            'scope' => self::SCOPE_WORKSPACE,
        ],
        'whatsapp_templates' => [
            'model' => WhatsappTemplate::class,
            'scope' => self::SCOPE_WORKSPACE,
        ],
        'knowledge_bases' => [
            'model' => AiKnowledgeBase::class,
            'scope' => self::SCOPE_WORKSPACE,
        ],
        'chatbots' => [
            'model' => AiChatbot::class,
            'scope' => self::SCOPE_WORKSPACE,
        ],
        // ⚠️ The Social MODULE model — social_media_accounts, not social_accounts.
        'social_accounts' => [
            'model' => SocialAccount::class,
            'scope' => self::SCOPE_WORKSPACE,
        ],
        'automations' => [
            'model' => Automation::class,
            'scope' => self::SCOPE_WORKSPACE,
        ],
    ];

    /**
     * ⚠️ GAUGE KEYS DELIBERATELY WITHOUT A SOURCE — each with its reason.
     *
     * Absence from MAP is what keeps a key inert. These are listed so that
     * absence reads as a decision rather than an oversight, and so a test can
     * assert the two lists together account for every gauge in
     * PlanLimitKinds — otherwise a key could go missing silently.
     *
     * @var array<string, string>
     */
    public const EXCLUDED = [
        // `storage` is bytes on disk, not rows in a table, so COUNT(*) cannot
        // answer it. It is also BUG-025: the seeder writes MEGABYTES under
        // `storage` while MediaService reads `storage_gb`, so enforcing it would
        // bound disk against a number the storage code has never seen. That is a
        // silent quota change in both directions and needs its own data
        // decision, not a line in this slice.
        'storage' => 'BUG-025 unresolved: megabytes nothing reads, and not a row count',

        // Nobody has defined what an inbox agent IS. There is an
        // `inbox_assignments` table, a `conversations.assigned_user_id` column
        // and a `conversations.assigned_to` column, and no role marker on
        // `users`. Three candidate populations, none authoritative. Enforcing a
        // limit whose population is undefined means picking one by accident.
        'inbox_agents' => 'population undefined: inbox_assignments vs assigned_user_id vs assigned_to',
    ];

    /** @return array{model: class-string, scope: string}|null */
    public static function for(string $key): ?array
    {
        return self::MAP[$key] ?? null;
    }

    public static function isExcluded(string $key): bool
    {
        return array_key_exists($key, self::EXCLUDED);
    }

    /** @return list<string> */
    public static function enforceableKeys(): array
    {
        return array_keys(self::MAP);
    }
}

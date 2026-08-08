<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enforce the workspace global scope
    |--------------------------------------------------------------------------
    |
    | The emergency brake for Phase 0's tenant-isolation scope.
    |
    | ON (the default) — every model using BelongsToWorkspace is filtered to the
    | workspace resolved by WorkspaceContext, and an unresolvable context matches
    | nothing.
    |
    | OFF — the scope becomes a no-op. This exists for exactly one situation: the
    | scope has broken production in a way that is worse than the isolation it
    | provides, at an hour when diagnosing it is not an option. It buys time to
    | roll back properly. It is not a configuration choice.
    |
    | ─── This is SERVER CONFIG ONLY, and deliberately so ─────────────────────
    |
    | Set it in `.env` as ENFORCE_WORKSPACE_SCOPE=false, then
    | `php artisan config:clear`. That is the only supported route.
    |
    | It must NEVER be exposed in the admin panel, and it must never be read from
    | the `system_settings` table. It disables a security control — a UI toggle
    | would turn the emergency brake into an attack surface, reachable by anyone
    | who compromises an admin account. Note that
    | `Admin\SystemSettingsController::update()` validates `settings.*.key` as a
    | free-form string, so an admin can write ANY system_settings row: the only
    | thing keeping this flag out of their reach is that nothing reads it from
    | there. `WorkspaceScopeFlagTest` asserts that, rather than trusting it.
    |
    | Defaulting to ON is the whole point. A brake whose default is "off" is not
    | a brake; it is an isolation feature that ships disabled and nobody notices.
    |
    */

    'enforce_scope' => env('ENFORCE_WORKSPACE_SCOPE', true),

];

<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\Client;

class ClientPolicy
{
    public function viewAny(?AdminUser $user): bool
    {
        return $user?->hasPermissionTo('view_clients') ?? false;
    }

    public function view(AdminUser $user, Client $client): bool
    {
        return $user->hasPermissionTo('view_clients');
    }

    public function create(AdminUser $user): bool
    {
        return $user->hasPermissionTo('create_clients');
    }

    public function update(AdminUser $user, Client $client): bool
    {
        return $user->hasPermissionTo('update_clients');
    }

    public function delete(AdminUser $user, Client $client): bool
    {
        return $user->hasPermissionTo('delete_clients');
    }

    /** Assigning a plan changes billing, so it needs a write permission. */
    public function assignPlan(AdminUser $user, Client $client): bool
    {
        return $user->hasPermissionTo('manage_subscriptions');
    }

    /**
     * Impersonation is a full login as the client's administrator, so it has
     * its own dedicated permission rather than riding on a read grant.
     *
     * Both this policy AND the route middleware previously checked
     * `view_clients`. Two layers checking the same read permission read as
     * defence in depth and were not — see DEEP-03.
     */
    public function impersonate(AdminUser $user, Client $client): bool
    {
        return $user->hasPermissionTo('impersonate_clients');
    }
}

<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $superAdmin = Role::firstOrCreate(
            ['key' => Role::KEY_SUPER_ADMIN],
            [
                'name' => 'Super Admin',
                'description' => 'Full system access. Receives every permission automatically, including impersonate_clients.',
                'is_system' => true,
            ]
        );

        Role::firstOrCreate(
            ['key' => Role::KEY_ADMIN],
            [
                'name' => 'Admin',
                'description' => 'Intended for staff administrators. Ships with NO permissions — grant explicitly.',
                'is_system' => false,
            ]
        );

        Role::firstOrCreate(
            ['key' => Role::KEY_SUPPORT],
            [
                'name' => 'Support',
                // Was 'View clients and subscriptions only'. That was not merely
                // inaccurate, it was the delivery mechanism for DEEP-03: an
                // operator read it and granted view_clients believing it was
                // read-only, which also granted impersonation and plan changes.
                // A role ships with NO permissions; what it can do is whatever
                // is granted to it, so the description must not promise limits
                // it does not enforce.
                'description' => 'Intended for support staff. Ships with NO permissions — grant explicitly. Note that granting view_clients does NOT grant impersonation, which needs impersonate_clients.',
                'is_system' => false,
            ]
        );

        // Assign ALL permissions to Super Admin
        $allPermissionIds = Permission::pluck('id')->all();
        $superAdmin->permissions()->sync($allPermissionIds);
    }
}

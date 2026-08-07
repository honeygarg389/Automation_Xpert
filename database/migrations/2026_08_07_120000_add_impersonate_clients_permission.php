<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DEEP-03. Adds the `impersonate_clients` permission and grants it to
 * SUPER_ADMIN only.
 *
 * A seeder change alone would only help fresh installs. Existing installs
 * already have their permissions table populated, so the permission has to
 * arrive by migration or impersonation breaks for everyone the moment the
 * route starts requiring it.
 *
 * Deliberately NOT granted to anyone currently holding `view_clients`. That
 * would re-grant precisely the hole this closes: `view_clients` is a read
 * permission, and everyone who has it could impersonate. Operators must grant
 * impersonation explicitly from here on.
 */
return new class extends Migration
{
    private const KEY = 'impersonate_clients';

    public function up(): void
    {
        $now = now();

        DB::table('permissions')->updateOrInsert(
            ['key' => self::KEY],
            [
                'name' => 'Impersonate Clients',
                'category' => 'Clients',
                'description' => "Log in AS a client administrator. Grants full access to that client's account.",
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $permissionId = DB::table('permissions')->where('key', self::KEY)->value('id');
        $superAdminId = DB::table('roles')->where('key', 'SUPER_ADMIN')->value('id');

        // Nothing to attach on an install that has not been seeded yet; the
        // seeder covers that case.
        if ($permissionId === null || $superAdminId === null) {
            return;
        }

        $alreadyAttached = DB::table('role_permission')
            ->where('role_id', $superAdminId)
            ->where('permission_id', $permissionId)
            ->exists();

        if (! $alreadyAttached) {
            DB::table('role_permission')->insert([
                'role_id' => $superAdminId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('key', self::KEY)->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('role_permission')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};

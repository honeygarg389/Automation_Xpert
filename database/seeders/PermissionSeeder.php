<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * @return list<array{key: string, name: string, category: string, description: string}>
     */
    public static function permissionSet(): array
    {
        return [
            // Admin Management
            ['key' => 'view_admins', 'name' => 'View Admins', 'category' => 'Admin Management', 'description' => 'View admin users list and details'],
            ['key' => 'create_admins', 'name' => 'Create Admins', 'category' => 'Admin Management', 'description' => 'Create new admin users'],
            ['key' => 'update_admins', 'name' => 'Update Admins', 'category' => 'Admin Management', 'description' => 'Edit admin users'],
            ['key' => 'delete_admins', 'name' => 'Delete Admins', 'category' => 'Admin Management', 'description' => 'Delete admin users'],
            ['key' => 'view_admin_roles', 'name' => 'View Admin Roles', 'category' => 'Admin Management', 'description' => 'View roles and permissions'],
            ['key' => 'manage_admin_roles', 'name' => 'Manage Admin Roles', 'category' => 'Admin Management', 'description' => 'Create, update, delete roles and permissions'],

            // Clients
            ['key' => 'view_clients', 'name' => 'View Clients', 'category' => 'Clients', 'description' => 'View clients list and details'],
            ['key' => 'impersonate_clients', 'name' => 'Impersonate Clients', 'category' => 'Clients', 'description' => 'Log in AS a client administrator. Grants full access to that client\'s account.'],
            ['key' => 'create_clients', 'name' => 'Create Clients', 'category' => 'Clients', 'description' => 'Create clients'],
            ['key' => 'update_clients', 'name' => 'Update Clients', 'category' => 'Clients', 'description' => 'Edit clients'],
            ['key' => 'delete_clients', 'name' => 'Delete Clients', 'category' => 'Clients', 'description' => 'Delete clients'],

            // Subscriptions
            ['key' => 'view_subscriptions', 'name' => 'View Subscriptions', 'category' => 'Subscriptions', 'description' => 'View subscriptions'],
            ['key' => 'manage_subscriptions', 'name' => 'Manage Subscriptions', 'category' => 'Subscriptions', 'description' => 'Manage subscriptions'],

            // Plans
            ['key' => 'view_plans', 'name' => 'View Plans', 'category' => 'Plans', 'description' => 'View plans'],
            ['key' => 'create_plans', 'name' => 'Create Plans', 'category' => 'Plans', 'description' => 'Create plans'],
            ['key' => 'update_plans', 'name' => 'Update Plans', 'category' => 'Plans', 'description' => 'Edit plans'],
            ['key' => 'delete_plans', 'name' => 'Delete Plans', 'category' => 'Plans', 'description' => 'Delete plans'],

            // Payment Gateways
            ['key' => 'view_payment_gateways', 'name' => 'View Payment Gateways', 'category' => 'Payment Gateways', 'description' => 'View payment gateways'],
            ['key' => 'manage_payment_gateways', 'name' => 'Manage Payment Gateways', 'category' => 'Payment Gateways', 'description' => 'Manage payment gateways'],

            // Email
            ['key' => 'view_email_settings', 'name' => 'View Email Settings', 'category' => 'Email', 'description' => 'View email settings'],
            ['key' => 'manage_email_settings', 'name' => 'Manage Email Settings', 'category' => 'Email', 'description' => 'Manage email settings'],

            // Currencies
            ['key' => 'view_currencies', 'name' => 'View Currencies', 'category' => 'Currencies', 'description' => 'View currencies'],
            ['key' => 'manage_currencies', 'name' => 'Manage Currencies', 'category' => 'Currencies', 'description' => 'Manage currencies'],

            // Languages
            ['key' => 'view_languages', 'name' => 'View Languages', 'category' => 'Languages', 'description' => 'View languages/locales'],
            ['key' => 'manage_languages', 'name' => 'Manage Languages', 'category' => 'Languages', 'description' => 'Manage languages/locales'],

            // Settings
            ['key' => 'view_settings', 'name' => 'View Settings', 'category' => 'Settings', 'description' => 'View system settings'],
            ['key' => 'manage_settings', 'name' => 'Manage Settings', 'category' => 'Settings', 'description' => 'Manage system settings'],

            // Integrations (third-party credential management)
            ['key' => 'view_integrations',   'name' => 'View Integrations',   'category' => 'Integrations', 'description' => 'View integration configurations'],
            ['key' => 'manage_integrations',  'name' => 'Manage Integrations', 'category' => 'Integrations', 'description' => 'Create, update and test third-party integration credentials'],

            // QR Management (Smart QR slice 3)
            //
            // ⚠️ `override_qr_assignment_limit` is deliberately SEPARATE from
            // `assign_qr_codes`. R-8 requires the over-limit path to be
            // permission-gated, and folding it into the assign permission would
            // mean everyone who can assign can also break the limit — which
            // makes the limit advisory and the audit trail uninformative.
            ['key' => 'view_qr_inventory', 'name' => 'View QR Inventory', 'category' => 'QR Management', 'description' => 'View Smart QR batches, inventory and assignments'],
            ['key' => 'manage_qr_batches', 'name' => 'Manage QR Batches', 'category' => 'QR Management', 'description' => 'Create QR batches and trigger code generation'],
            ['key' => 'assign_qr_codes', 'name' => 'Assign QR Codes', 'category' => 'QR Management', 'description' => 'Assign and unassign Smart QR codes to customer workspaces'],
            ['key' => 'override_qr_assignment_limit', 'name' => 'Override QR Assignment Limit', 'category' => 'QR Management', 'description' => 'Assign QR codes beyond a workspace\'s plan limit. Requires a written reason and is audit-logged.'],

            // ⚠️ SEPARATE FROM `assign_qr_codes` for the same reason
            // `override_qr_assignment_limit` is: locking takes a control away
            // from a paying customer until an admin gives it back. Everyone who
            // may assign a code should not automatically be able to freeze the
            // tenant out of it — that is a different, more consequential act,
            // and it deserves to be granted deliberately.
            ['key' => 'lock_qr_assignments', 'name' => 'Lock QR Assignments', 'category' => 'QR Management', 'description' => 'Lock a QR assignment\'s active status so the customer cannot change it. Requires a written reason and is audit-logged.'],

            // Restaurant Integrations (Phase 1C — Petpooja sandbox connections)
            //
            // ⚠️ `rotate_pos_webhook_secret` and `activate_pos_connections` are
            // deliberately SEPARATE from `manage_pos_connections`, same
            // reasoning as `lock_qr_assignments` above: generating a token
            // hands out the one secret Petpooja needs to submit orders as this
            // outlet, and activating a connection opens the ingress endpoint to
            // it. Everyone who may create a connection record should not
            // automatically be able to do either — they are more consequential
            // acts and are audit-logged on their own.
            ['key' => 'view_pos_connections', 'name' => 'View POS Connections', 'category' => 'Restaurant Integrations', 'description' => 'View Restaurant/POS connections, outlets and webhook health'],
            ['key' => 'manage_pos_connections', 'name' => 'Manage POS Connections', 'category' => 'Restaurant Integrations', 'description' => 'Create/edit/archive outlets, create sandbox connections, update the IP allowlist, archive a connection, and delete a zero-history test connection'],
            ['key' => 'rotate_pos_webhook_secret', 'name' => 'Rotate POS Webhook Secret', 'category' => 'Restaurant Integrations', 'description' => 'Generate or rotate a connection\'s webhook token. The plaintext is shown once and is audit-logged without the secret value.'],
            ['key' => 'activate_pos_connections', 'name' => 'Activate POS Connections', 'category' => 'Restaurant Integrations', 'description' => 'Activate/resume or pause a sandbox connection\'s ingress. Production activation is not implemented.'],

            // Separate from `manage_pos_connections` for the same reason as
            // the other splits above: the guarded move flow reassigns a
            // connection's workspace/outlet AND rotates its token in one
            // action. It is already blocked once webhook history exists, but
            // even the pre-history case is consequential enough to gate on
            // its own rather than folding into general "manage".
            ['key' => 'move_pos_connections', 'name' => 'Move POS Connections', 'category' => 'Restaurant Integrations', 'description' => 'Correct an accidental workspace/outlet mapping via the guarded move flow. Blocked once the connection has any webhook history.'],

            // Petpooja Phase 2A gate-hardening pass — Gate 5 of the six-gate
            // live activation invariant ("outlet-specific authorization").
            // Separate from `manage_pos_connections` for the same reason as
            // the other splits above: this is an admin verifying a physical
            // outlet's identity before it can go live, a more consequential
            // act than day-to-day outlet management.
            ['key' => 'authorize_pos_outlets', 'name' => 'Authorize Outlets for Live Petpooja', 'category' => 'Restaurant Integrations', 'description' => 'Record that an outlet has been verified and is authorized for a live Petpooja connection. Required before that outlet\'s connection can be activated live.'],
        ];
    }

    public function run(): void
    {
        foreach (self::permissionSet() as $row) {
            Permission::firstOrCreate(
                ['key' => $row['key']],
                $row
            );
        }
    }
}

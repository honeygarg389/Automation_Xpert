<?php

namespace Tests;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ClientSubscription;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Second line of defence for the test-database guardrail.
     *
     * tests/bootstrap.php checks the environment before the run starts. This
     * checks the *resolved framework config* once the application is booted, so
     * a runtime override — config(['database.connections.mysql.database' => …])
     * in a service provider or a test helper — cannot slip past it.
     *
     * RefreshDatabase runs migrate:fresh, so an unguarded run drops every table
     * in the connected schema.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if (! is_string($database) || ! str_ends_with($database, '_test')) {
            $this->fail(sprintf(
                'Refusing to run: resolved database [%s] on connection [%s] is not a test schema '
                .'(name must end in "_test"). RefreshDatabase would drop every table in it.',
                var_export($database, true),
                var_export($connection, true),
            ));
        }
    }

    /**
     * Create an AdminUser with the SUPER_ADMIN role and all permissions,
     * so RBAC middleware passes in feature tests.
     */
    protected function createSuperAdmin(array $attrs = []): AdminUser
    {
        $admin = AdminUser::factory()->create(array_merge(['status' => AdminUser::STATUS_ACTIVE], $attrs));

        // Ensure SUPER_ADMIN role exists
        $role = Role::firstOrCreate(
            ['key' => Role::KEY_SUPER_ADMIN],
            ['name' => 'Super Admin', 'description' => 'Full access']
        );

        // Attach common permissions so permission middleware passes
        $permKeys = [
            'view_settings', 'manage_settings',
            'view_clients', 'manage_clients',
            // DEEP-03: impersonation now has its own permission rather than
            // riding on view_clients. A super admin holds every permission, so
            // it belongs in this list.
            'impersonate_clients',
            'view_plans', 'manage_plans', 'create_plans', 'delete_plans',
            'view_subscriptions', 'manage_subscriptions',
            'view_payment_gateways', 'manage_payment_gateways',
            'view_admins', 'create_admins', 'update_admins', 'delete_admins',
            'view_admin_roles', 'manage_admin_roles',
            'view_email_settings', 'manage_email_settings',
            'view_currencies', 'view_languages',
        ];

        foreach ($permKeys as $key) {
            $perm = Permission::firstOrCreate(
                ['key' => $key],
                ['name' => ucwords(str_replace('_', ' ', $key)), 'category' => 'general']
            );
            if (! $role->permissions->contains('key', $key)) {
                $role->permissions()->syncWithoutDetaching([$perm->id]);
            }
        }

        $admin->roles()->syncWithoutDetaching([$role->id]);
        $admin->unsetRelation('roles');

        return $admin;
    }

    /**
     * Create a Client, User, and Workspace for feature tests.
     * Returns ['user' => User, 'workspace' => Workspace, 'client' => Client].
     */
    protected function createWorkspaceContext(array $clientAttrs = [], array $userAttrs = [], array $workspaceAttrs = []): array
    {
        $client = Client::create(array_merge([
            'name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'status' => Client::STATUS_ACTIVE,
        ], $clientAttrs));

        $user = User::factory()->create(array_merge([
            'role' => User::ROLE_CLIENT,
            'client_id' => $client->id,
            'client_role' => User::CLIENT_ROLE_ADMINISTRATOR,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ], $userAttrs));

        $user->refresh();
        $workspace = $client->workspaces()->orderBy('id')->first();
        if ($workspace && $workspaceAttrs !== []) {
            $workspace->update($workspaceAttrs);
        }

        return ['user' => $user, 'workspace' => $workspace, 'client' => $client];
    }

    /**
     * A user belonging to a client with TWO workspaces, both accessible.
     *
     * Every other fixture in this suite (createWorkspaceContext, and the ~140
     * tests using it) builds a user whose home workspace is their ONLY
     * workspace. That means no existing test can distinguish "resolved the
     * active workspace" from "resolved the home workspace" — they are the same
     * value. The suite is therefore structurally blind to the workspace
     * switcher, which is exactly the behaviour Phase 0 commits 1b and 1c change.
     *
     * See docs/phase-0-tenant-isolation-plan.md §G-1.
     *
     * @return array{user: User, client: Client, home: Workspace, other: Workspace}
     */
    protected function createTwoWorkspaceUser(array $userAttrs = []): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $home] =
            $this->createWorkspaceContext([], $userAttrs);

        $other = Workspace::create([
            'client_id' => $client->id,
            'name' => 'Second Workspace',
            'owner_id' => $user->id,
        ]);

        // Mirror how ClientWorkspaceService grants membership, so the fixture
        // reflects production rather than inventing its own arrangement.
        if (! $other->members()->where('user_id', $user->id)->exists()) {
            $other->members()->attach($user->id, ['role' => 'member']);
        }

        $user->refresh();

        // Guard the fixture itself: if these ever stop holding, every test built
        // on it is silently testing something else.
        $accessible = $user->accessibleWorkspaces()->pluck('id');
        if (! $accessible->contains($home->id) || ! $accessible->contains($other->id)) {
            $this->fail('createTwoWorkspaceUser: user cannot access both workspaces — fixture is broken.');
        }
        if ((int) $user->workspace_id !== (int) $home->id) {
            $this->fail('createTwoWorkspaceUser: home workspace is not the user\'s workspace_id — fixture is broken.');
        }

        return ['user' => $user, 'client' => $client, 'home' => $home, 'other' => $other];
    }

    /**
     * Attach a Plan to a Client via an active ClientSubscription.
     */
    protected function attachPlanToClient(Client $client, Plan $plan): ClientSubscription
    {
        return ClientSubscription::create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
            'status' => ClientSubscription::STATUS_ACTIVE,
        ]);
    }

    /**
     * Run a queued job the way the QUEUE WORKER runs it — through its
     * middleware — rather than by calling handle() directly.
     *
     * Phase 0, slice 6. Scoping `Contact` turned 28 tests red, and almost all of
     * them for the same reason: they call `(new SomeJob($id))->handle()`, which
     * skips `middleware()`. The queue never does that. So the job ran with no
     * workspace context, the scope failed closed, and `Contact::find()` returned
     * null — "Attempt to read property on null" fourteen times over.
     *
     * Production was correct throughout; the harness was not faithful. Calling
     * handle() directly was harmless while nothing depended on middleware, and
     * stopped being harmless the moment tenant context did.
     *
     * Use this instead. It is closer to production, and it means a job that
     * forgets its middleware fails in tests rather than in a queue worker.
     *
     * @template T
     *
     * @param  object  $job  a job instance
     * @param  array<int, mixed>  $handleArgs  dependencies handle() expects
     */
    protected function runJob(object $job, array $handleArgs = []): mixed
    {
        $pipeline = method_exists($job, 'middleware') ? array_reverse($job->middleware()) : [];

        $next = fn () => $job->handle(...$handleArgs);

        foreach ($pipeline as $middleware) {
            $current = $next;
            $next = fn () => $middleware->handle($job, fn () => $current());
        }

        return $next();
    }
}

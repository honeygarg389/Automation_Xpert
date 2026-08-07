<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Support\ApiTokenLifetime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SEC-006. Sanctum tokens never expired, and were never revoked.
 *
 * The recorded finding was "tokens never expire". The worse half was that
 * nothing revokes them: a token survived deactivation of its own user and
 * survived a password change, both proven before the fix. `auth:sanctum`
 * validates the token and nothing re-checks the account behind it, while
 * MobileAuthController::login DOES check status — so deactivation blocked new
 * logins and left every existing token live. It looked handled.
 *
 * ⚠️ THE GUARD CACHES WITHIN A REQUEST — and, in tests, across requests in one
 * test method. An earlier probe of exactly these behaviours returned 200 for
 * FOUR checks including one that should have been 401; re-run as separate test
 * methods the false one flipped. So:
 *
 *   - every check that changes state and re-requests lives in its OWN test;
 *   - `freshRequest()` is used where a second request is unavoidable, and it
 *     rebuilds the container so no resolved user survives.
 *
 * A test here that cannot fail is worse than no test, because the thing it
 * claims to prove is the headline of the finding.
 */
class ApiTokenLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const ME = '/api/v1/auth/me';

    private function user(array $attrs = []): User
    {
        ['user' => $user] = $this->createWorkspaceContext([], $attrs);

        return $user;
    }

    /** @return array<string,string> */
    private function bearer(string $plainTextToken): array
    {
        return ['Authorization' => 'Bearer '.$plainTextToken, 'Accept' => 'application/json'];
    }

    /**
     * Drop every resolved instance so the next request re-authenticates from
     * the token rather than reusing the guard's cached user.
     */
    private function freshRequest(): void
    {
        $this->app['auth']->forgetGuards();
    }

    // ── The headline: a deactivated user's token ───────────────────────────

    #[Test]
    public function a_deactivated_users_existing_token_is_rejected(): void
    {
        $user = $this->user();
        $token = $user->createToken('phone', ['*'])->plainTextToken;

        $user->update(['status' => User::STATUS_INACTIVE]);
        $this->freshRequest();

        $this->getJson(self::ME, $this->bearer($token))->assertStatus(401);
    }

    /**
     * POSITIVE CONTROL for the above. Same route, same verb, same token shape —
     * proving the 401 comes from deactivation and not from the endpoint
     * refusing everyone.
     */
    #[Test]
    public function an_active_users_token_still_works(): void
    {
        $user = $this->user();
        $token = $user->createToken('phone', ['*'])->plainTextToken;

        $this->freshRequest();

        $this->getJson(self::ME, $this->bearer($token))->assertOk();
    }

    /**
     * The middleware must hold even for a token the model hook never saw. This
     * is the case the hook cannot cover — a status written around Eloquent — and
     * therefore the one that justifies having both.
     */
    #[Test]
    public function the_middleware_refuses_a_token_that_survived_deactivation(): void
    {
        $user = $this->user();
        $token = $user->createToken('legacy', ['*'])->plainTextToken;

        // Bypass Eloquent events entirely: no model hook fires, the token row
        // stays exactly where it was.
        \DB::table('users')->where('id', $user->id)->update(['status' => User::STATUS_INACTIVE]);

        $this->assertSame(1, PersonalAccessToken::count(),
            'The token must still exist — otherwise this proves the hook, not the middleware.');

        $this->freshRequest();

        $this->getJson(self::ME, $this->bearer($token))->assertStatus(401);
    }

    // ── Revocation on credential change ────────────────────────────────────

    #[Test]
    public function changing_a_password_revokes_every_token(): void
    {
        $user = $this->user();
        $token = $user->createToken('phone', ['*'])->plainTextToken;
        $user->createToken('integration', ['contacts:read']);

        $this->assertDatabaseCount('personal_access_tokens', 2);

        $user->update(['password' => Hash::make('a-brand-new-password')]);

        // NOTE: assertDatabaseCount's third argument is a CONNECTION name, not
        // a failure message. Passing a message there makes Laravel look for a
        // database connection called that.
        $this->assertSame(0, PersonalAccessToken::count(),
            'A password change must revoke every token, not just the current one.');

        $this->freshRequest();
        $this->getJson(self::ME, $this->bearer($token))->assertStatus(401);
    }

    /**
     * The password RESET flow uses forceFill()->save(), not update(). That is a
     * different code path and the one that matters most — resetting the
     * password is what a user does when they believe they are compromised.
     */
    #[Test]
    public function resetting_a_password_via_force_fill_revokes_every_token(): void
    {
        $user = $this->user();
        $user->createToken('phone', ['*']);

        $user->forceFill(['password' => Hash::make('reset-password')])->save();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[Test]
    public function deactivating_a_user_revokes_every_token(): void
    {
        $user = $this->user();
        $user->createToken('phone', ['*']);

        $user->update(['status' => User::STATUS_INACTIVE]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * POSITIVE CONTROL. Ordinary edits must NOT nuke a user's tokens — a hook
     * that fired on every save would be a different bug, and an invisible one:
     * tokens would simply stop working after a profile edit.
     */
    #[Test]
    public function an_unrelated_profile_edit_leaves_tokens_alone(): void
    {
        $user = $this->user();
        $user->createToken('phone', ['*']);

        $user->update(['name' => 'A New Name', 'timezone' => 'Europe/London']);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    /** POSITIVE CONTROL. Re-activating must not be treated as deactivation. */
    #[Test]
    public function reactivating_a_user_does_not_revoke_the_tokens_issued_afterwards(): void
    {
        $user = $this->user(['status' => User::STATUS_INACTIVE]);
        $user->update(['status' => User::STATUS_ACTIVE]);

        $user->createToken('phone', ['*']);
        $user->update(['status' => User::STATUS_ACTIVE]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    // ── Expiry at the point of issue ───────────────────────────────────────

    #[Test]
    public function a_mobile_login_issues_a_token_that_expires_in_30_days(): void
    {
        $user = $this->user();
        $user->update(['password' => Hash::make('correct-horse')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-horse',
            'device_name' => 'Pixel 9',
        ])->assertOk();

        $this->assertNotEmpty($response->json('token'), 'A legitimate mobile login must still succeed.');

        $token = PersonalAccessToken::latest('id')->firstOrFail();

        $this->assertNotNull($token->expires_at, 'Mobile tokens must not be issued without an expiry.');
        $this->assertEqualsWithDelta(
            ApiTokenLifetime::MOBILE_DAYS,
            now()->diffInDays($token->expires_at, absolute: true),
            1
        );
    }

    #[Test]
    public function an_api_token_created_with_a_blank_expiry_gets_the_90_day_default(): void
    {
        $user = $this->user();

        $this->withHeaders($this->bearer($user->createToken('bootstrap', ['*'])->plainTextToken))
            ->postJson('/api/v1/tokens', ['name' => 'CI pipeline'])
            ->assertCreated();

        $token = PersonalAccessToken::latest('id')->firstOrFail();

        $this->assertNotNull($token->expires_at, 'A blank expiry used to mean "never".');
        $this->assertEqualsWithDelta(
            ApiTokenLifetime::API_DEFAULT_DAYS,
            now()->diffInDays($token->expires_at, absolute: true),
            1
        );
    }

    #[Test]
    public function a_user_supplied_expiry_is_preserved(): void
    {
        $user = $this->user();
        $chosen = now()->addDays(7);

        $this->withHeaders($this->bearer($user->createToken('bootstrap', ['*'])->plainTextToken))
            ->postJson('/api/v1/tokens', [
                'name' => 'Short-lived',
                'expires_at' => $chosen->toIso8601String(),
            ])
            ->assertCreated();

        $token = PersonalAccessToken::latest('id')->firstOrFail();

        $this->assertEqualsWithDelta(7, now()->diffInDays($token->expires_at, absolute: true), 1,
            'An explicit choice must be honoured — that is what the field is for.');
    }

    #[Test]
    public function an_expiry_beyond_one_year_is_capped(): void
    {
        $user = $this->user();

        $this->withHeaders($this->bearer($user->createToken('bootstrap', ['*'])->plainTextToken))
            ->postJson('/api/v1/tokens', [
                'name' => 'Forever',
                'expires_at' => now()->addYears(50)->toIso8601String(),
            ])
            ->assertCreated();

        $token = PersonalAccessToken::latest('id')->firstOrFail();

        $this->assertEqualsWithDelta(
            ApiTokenLifetime::API_MAX_DAYS,
            now()->diffInDays($token->expires_at, absolute: true),
            1,
            '"Never" must not be reachable by picking a distant date.'
        );
    }

    /** An expired token is actually refused, not merely stamped. */
    #[Test]
    public function an_expired_token_is_refused(): void
    {
        $user = $this->user();
        $token = $user->createToken('stale', ['*'], now()->subMinute())->plainTextToken;

        $this->freshRequest();

        $this->getJson(self::ME, $this->bearer($token))->assertStatus(401);
    }

    // ── Positive control: the ability system still functions ───────────────

    #[Test]
    public function existing_api_scopes_still_work(): void
    {
        $user = $this->user();
        $scoped = $user->createToken('reader', ['contacts:read'])->plainTextToken;

        $this->freshRequest();
        $this->getJson('/api/v1/contacts', $this->bearer($scoped))->assertOk();

        $this->freshRequest();
        $this->postJson('/api/v1/contacts', ['name' => 'X'], $this->bearer($scoped))
            ->assertStatus(403);
    }
}

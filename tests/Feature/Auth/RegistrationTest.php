<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            // Registration requires accepting the Terms & Conditions
            // (RegisteredUserController: 'agree_terms' => ['accepted']).
            // Omitting it failed validation, so no user was created and the
            // test's assertAuthenticated() failed.
            'agree_terms' => true,
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('client.dashboard', absolute: false));
    }

    /**
     * Paired negative for the test above. Without it, adding agree_terms only
     * proves the happy path and leaves us unable to tell whether the rule is
     * still enforced — the same blind spot in a new place.
     */
    public function test_registration_is_rejected_without_accepting_terms(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'noterms@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            // agree_terms deliberately omitted
        ]);

        $response->assertSessionHasErrors('agree_terms');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'noterms@example.com']);
    }
}

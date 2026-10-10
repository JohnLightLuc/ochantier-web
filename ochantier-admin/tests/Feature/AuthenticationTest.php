<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/connexion')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('step', 'login'));
    }

    public function test_valid_credentials_lead_to_the_otp_step_without_logging_in(): void
    {
        User::factory()->create(['email' => 'n.yao@ochantier.ci']);

        $this->post('/connexion', ['email' => 'n.yao@ochantier.ci', 'password' => 'password'])
            ->assertRedirect('/connexion/verification');

        $this->assertGuest();

        $this->get('/connexion/verification')
            ->assertInertia(fn (Assert $page) => $page->where('step', 'otp')->where('maskedEmail', 'n•••@ochantier.ci'));
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        User::factory()->create(['email' => 'n.yao@ochantier.ci']);

        $this->post('/connexion', ['email' => 'n.yao@ochantier.ci', 'password' => 'mauvais'])
            ->assertSessionHasErrors(['email' => 'Adresse e-mail ou mot de passe incorrect.']);

        $this->assertGuest();
    }

    public function test_otp_step_requires_pending_credentials(): void
    {
        $this->get('/connexion/verification')->assertRedirect('/connexion');
        $this->post('/connexion/verification', ['code' => '123456'])->assertRedirect('/connexion');
    }

    public function test_a_six_digit_code_completes_the_login(): void
    {
        $user = User::factory()->create(['email' => 'n.yao@ochantier.ci']);

        $this->post('/connexion', ['email' => 'n.yao@ochantier.ci', 'password' => 'password']);

        $this->post('/connexion/verification', ['code' => '12345'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/connexion/verification', ['code' => '123456'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_password_reset_request_shows_the_confirmation(): void
    {
        $this->post('/mot-de-passe-oublie', ['email' => 'n.yao@ochantier.ci'])
            ->assertRedirect('/mot-de-passe-oublie');

        $this->get('/mot-de-passe-oublie')
            ->assertInertia(fn (Assert $page) => $page->where('step', 'sent')->where('sentTo', 'n.yao@ochantier.ci'));
    }

    public function test_users_can_logout(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/deconnexion')
            ->assertRedirect('/connexion');

        $this->assertGuest();
    }
}

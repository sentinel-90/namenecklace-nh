<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_admin_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertSeeText('Войдите в свой аккаунт');
    }

    public function test_an_employee_can_sign_in_and_see_the_dashboard(): void
    {
        $password = 'test-password';
        $employee = User::factory()->unverified()->create(['password' => $password]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Login::class)
            ->set('data.email', $employee->email)
            ->set('data.password', $password)
            ->call('authenticate')
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($employee);
        $this->get('/admin')
            ->assertOk()
            ->assertSeeText('Панель управления')
            ->assertDontSeeText('Привет, мир!');
    }

    public function test_an_incorrect_password_leaves_the_employee_signed_out(): void
    {
        $employee = User::factory()->create(['password' => 'test-password']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Login::class)
            ->set('data.email', $employee->email)
            ->set('data.password', 'incorrect-password')
            ->call('authenticate')
            ->assertHasErrors('data.email');

        $this->assertGuest();
    }

    public function test_an_employee_can_sign_out_of_the_admin_panel(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/admin/logout')->assertRedirect('/admin/login');

        $this->assertGuest();
    }

    public function test_a_blocked_employee_cannot_sign_in_with_the_correct_password(): void
    {
        $employee = User::factory()->create(['password' => 'test-password', 'is_blocked' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Login::class)
            ->set('data.email', $employee->email)
            ->set('data.password', 'test-password')
            ->call('authenticate')
            ->assertHasErrors('data.email');

        $this->assertGuest();
    }

    public function test_a_blocked_employee_cannot_access_the_users_page_with_an_existing_session(): void
    {
        $this->actingAs(User::factory()->create(['is_blocked' => true]));

        $this->get('/admin/users')->assertForbidden();
    }
}

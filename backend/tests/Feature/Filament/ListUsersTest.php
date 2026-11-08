<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ListUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_an_employee_can_create_a_user_and_see_them_in_the_list(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(ListUsers::class)->callAction('create', data: [
            'name' => 'New Employee',
            'email' => 'new-employee@example.test',
            'password' => 'test-password',
        ])->assertHasNoFormErrors();

        $user = User::query()->where('email', 'new-employee@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('test-password', $user->password));
        $this->assertFalse($user->is_blocked);
        $this->get('/admin/users')->assertOk()->assertSeeText('New Employee');
    }

    public function test_an_existing_email_cannot_be_used_to_create_another_user(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        $component = Livewire::test(ListUsers::class)->callAction('create', data: [
            'name' => 'Duplicate Email',
            'email' => $employee->email,
            'password' => 'test-password',
        ])->assertHasFormErrors(['email' => 'unique']);

        $this->assertStringContainsString('уже используется.', $component->errors()->first());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_an_employee_can_block_and_unblock_another_user(): void
    {
        $target = User::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test(ListUsers::class)
            ->assertActionEnabled(TestAction::make('block')->table($target))
            ->callAction(TestAction::make('block')->table($target));

        $this->assertTrue($target->refresh()->is_blocked);
        $this->assertNull($target->remember_token);

        Livewire::test(ListUsers::class)->callAction(TestAction::make('unblock')->table($target));

        $this->assertFalse($target->refresh()->is_blocked);
    }

    public function test_an_employee_can_delete_another_user(): void
    {
        $target = User::factory()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test(ListUsers::class)
            ->assertActionEnabled(TestAction::make('delete')->table($target))
            ->callAction(TestAction::make('delete')->table($target));

        $this->assertModelMissing($target);
    }

    public function test_an_employee_can_change_another_users_password_and_end_their_sessions(): void
    {
        config(['session.driver' => 'database']);
        $employee = User::factory()->create();
        $target = User::factory()->create(['password' => 'previous-password']);
        $this->actingAs($employee);
        DB::table('sessions')->insert([
            'id' => 'target-session',
            'user_id' => $target->id,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('changePassword')->table($target), data: [
                'password' => 'replacement-password',
                'password_confirmation' => 'replacement-password',
            ])
            ->assertHasNoFormErrors();

        $target->refresh();
        $this->assertTrue(Hash::check('replacement-password', $target->password));
        $this->assertFalse(Hash::check('previous-password', $target->password));
        $this->assertNull($target->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertAuthenticatedAs($employee);
    }

    public function test_an_employee_can_change_their_own_password_and_stay_signed_in(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('changePassword')->table($employee), data: [
                'password' => 'replacement-password',
                'password_confirmation' => 'replacement-password',
            ])
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin/users');

        $this->assertTrue(Hash::check('replacement-password', $employee->refresh()->password));
        $this->assertAuthenticatedAs($employee);
        $this->get('/admin/users')->assertOk();
    }

    #[TestWith(['replacement-password', 'different-password', 'password_confirmation'])]
    #[TestWith(['short', 'short', 'password'])]
    public function test_invalid_password_changes_leave_the_previous_password_in_place(string $password, string $confirmation, string $field): void
    {
        $this->actingAs(User::factory()->create());
        $target = User::factory()->create(['password' => 'previous-password']);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('changePassword')->table($target), data: [
                'password' => $password,
                'password_confirmation' => $confirmation,
            ])
            ->assertHasFormErrors([$field]);

        $this->assertTrue(Hash::check('previous-password', $target->refresh()->password));
    }

    public function test_an_employee_cannot_block_or_delete_their_own_account(): void
    {
        $employee = User::factory()->create();
        $this->actingAs($employee);

        Livewire::test(ListUsers::class)
            ->assertSee('Действие')
            ->assertSee('Ваша учётная запись')
            ->assertActionVisible(TestAction::make('block')->table($employee))
            ->assertActionDisabled(TestAction::make('block')->table($employee))
            ->assertActionVisible(TestAction::make('delete')->table($employee))
            ->assertActionDisabled(TestAction::make('delete')->table($employee));
    }
}

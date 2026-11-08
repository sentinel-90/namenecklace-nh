<?php

namespace App\Services;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;

class UserAccess
{
    public function changePassword(User $employee, User $target, string $password): User
    {
        return $this->change($employee, $target, 'changePassword', function (User $user) use ($password): User {
            $user->password = Hash::make($password);
            $user->setRememberToken(null);
            $user->saveOrFail();
            $this->forgetDatabaseSessions($user);

            return $user;
        });
    }

    public function setBlocked(User $employee, User $target, bool $blocked): void
    {
        $this->change($employee, $target, 'manageAccess', function (User $user) use ($blocked): void {
            $user->is_blocked = $blocked;

            if ($blocked) {
                $user->setRememberToken(null);
            }

            $user->save();

            if ($blocked) {
                $this->forgetDatabaseSessions($user);
            }
        });
    }

    public function delete(User $employee, User $target): bool
    {
        return $this->change($employee, $target, 'delete', function (User $user): bool {
            if (! $user->delete()) {
                return false;
            }

            $this->forgetDatabaseSessions($user);

            return true;
        });
    }

    /**
     * @template T
     *
     * @param  Closure(User): T  $operation
     * @return T
     */
    private function change(User $employee, User $target, string $ability, Closure $operation): mixed
    {
        return DB::transaction(function () use ($employee, $target, $ability, $operation): mixed {
            // Lock both accounts in the same order so concurrent actions cannot lock out both employees.
            $users = User::query()
                ->whereKey([$employee->getKey(), $target->getKey()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $actor = $users->get($employee->getKey());
            $record = $users->get($target->getKey());
            abort_if($actor === null, 403);
            abort_if($record === null, 404);

            Gate::forUser($actor)->authorize($ability, $record);

            return $operation($record);
        }, attempts: 3);
    }

    private function forgetDatabaseSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::connection(config('session.connection'))
                ->table(config('session.table'))
                ->where('user_id', $user->getKey())
                ->delete();
        }
    }
}

<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    public function changePassword(User $employee): Response
    {
        return $employee->is_blocked
            ? Response::deny('Ваша учётная запись заблокирована.')
            : Response::allow();
    }

    public function manageAccess(User $employee, User $target): Response
    {
        if ($employee->is_blocked) {
            return Response::deny('Ваша учётная запись заблокирована.');
        }

        return $employee->is($target)
            ? Response::deny('Свою учётную запись нельзя заблокировать или удалить.')
            : Response::allow();
    }

    public function delete(User $employee, User $target): Response
    {
        return $this->manageAccess($employee, $target);
    }
}

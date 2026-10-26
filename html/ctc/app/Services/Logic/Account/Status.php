<?php


namespace App\Services\Logic\Account;

use App\Models\User as UserModel;
use App\Repos\Account as AccountRepo;
use App\Services\Logic\Service as LogicService;

class Status extends LogicService
{

    public function handle(UserModel $user): array
    {
        $accountRepo = new AccountRepo();

        $account = $accountRepo->findById($user->id);

        if (!$account) {
            throw new \RuntimeException('account.not_found');
        }

        $hasPassword = $account->password !== '';
        $hasLoginName = $account->phone !== '' || $account->email !== '';

        return [
            'has_password' => $hasPassword,
            'has_login_name' => $hasLoginName,
            'need_set_password' => !$hasPassword,
            'need_bind_phone' => !$hasLoginName,
        ];
    }

}

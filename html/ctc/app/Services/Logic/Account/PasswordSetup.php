<?php


namespace App\Services\Logic\Account;

use App\Exceptions\BadRequest as BadRequestException;
use App\Library\Utils\Lock as LockUtil;
use App\Library\Utils\Password as PasswordUtil;
use App\Repos\Account as AccountRepo;
use App\Services\Logic\Service as LogicService;
use App\Validators\Account as AccountValidator;
use App\Validators\Verify as VerifyValidator;

class PasswordSetup extends LogicService
{

    public function handle()
    {
        $user = $this->getLoginUser();

        $lockKey = "account_password_setup:{$user->id}";
        $phoneLockKey = '';
        $phoneLockId = '';

        $lockId = LockUtil::addLock($lockKey, 30);

        if ($lockId === false) {
            throw new \RuntimeException('sys.service_unavailable');
        }

        try {

            $accountRepo = new AccountRepo();

            $account = $accountRepo->findById($user->id);

            if (!$account) {
                throw new BadRequestException('account.not_found');
            }

            $accountValidator = new AccountValidator();

            if ($account->password !== '') {
                throw new BadRequestException('account.password_already_set');
            }

            $post = $this->request->getPost();

            $hasLoginName = $account->phone !== '' || $account->email !== '';

            if (!$hasLoginName) {

                $phone = $post['phone'] ?? '';

                if (empty($phone)) {
                    throw new BadRequestException('account.phone_required');
                }

                $phone = $accountValidator->checkPhone($phone);

                $phoneLockKey = "account_bind_phone:{$phone}";

                $phoneLockId = LockUtil::addLock($phoneLockKey, 30);

                if ($phoneLockId === false) {
                    throw new \RuntimeException('sys.service_unavailable');
                }

                $accountValidator->checkIfPhoneTaken($phone);

                $verifyValidator = new VerifyValidator();

                $verifyValidator->checkCode($phone, $post['verify_code'] ?? '');

                $account->phone = $phone;
            }

            $newPassword = $accountValidator->checkPassword($post['new_password'] ?? '');

            $accountValidator->checkConfirmPassword($newPassword, $post['confirm_password'] ?? '');

            $salt = PasswordUtil::salt();

            $account->salt = $salt;
            $account->password = PasswordUtil::hash($newPassword, $salt);

            $account->update();

            return $account;

        } finally {

            LockUtil::releaseLock($lockKey, $lockId);

            if ($phoneLockKey && $phoneLockId) {
                LockUtil::releaseLock($phoneLockKey, $phoneLockId);
            }

        }
    }

}

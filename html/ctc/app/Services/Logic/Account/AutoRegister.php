<?php


namespace App\Services\Logic\Account;

use App\Library\Utils\Lock as LockUtil;
use App\Library\Validators\Common as CommonValidator;
use App\Models\Account as AccountModel;
use App\Repos\Account as AccountRepo;
use App\Services\Logic\Service as LogicService;

class AutoRegister extends LogicService
{

    /**
     * @param string $loginName
     * @return array [AccountModel, bool]
     */
    public function handle($loginName): array
    {
        $account = $this->findAccount($loginName);

        if ($account) {
            return [$account, false];
        }

        $lockId = LockUtil::addLock("account_auto_register:{$loginName}", 30);

        if ($lockId === false) {
            usleep(100000);

            $account = $this->findAccount($loginName);

            if ($account) {
                return [$account, false];
            }

            throw new \RuntimeException('sys.service_unavailable');
        }

        try {

            $this->db->begin();

            $account = $this->findAccount($loginName);

            if ($account) {
                $this->db->commit();
                return [$account, false];
            }

            $account = new AccountModel();

            unset($account->id);

            if (CommonValidator::email($loginName)) {
                $account->email = $loginName;
            } else {
                $account->phone = $loginName;
            }

            $account->password = '';
            $account->salt = '';

            $account->create();

            $this->db->commit();

            return [$account, true];

        } catch (\Exception $e) {

            $this->db->rollback();

            $logger = $this->getLogger();

            $logger->error('Auto Register Account Exception: ' . kg_json_encode([
                    'login_name' => $loginName,
                    'line' => $e->getLine(),
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                ]));

            throw new \RuntimeException('sys.trans_rollback');

        } finally {

            LockUtil::releaseLock("account_auto_register:{$loginName}", $lockId);

        }
    }

    protected function findAccount($loginName)
    {
        $accountRepo = new AccountRepo();

        if (CommonValidator::email($loginName)) {
            return $accountRepo->findByEmail($loginName);
        }

        return $accountRepo->findByPhone($loginName);
    }

}

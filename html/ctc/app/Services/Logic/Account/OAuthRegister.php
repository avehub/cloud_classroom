<?php


namespace App\Services\Logic\Account;

use App\Exceptions\BadRequest as BadRequestException;
use App\Library\Utils\Lock as LockUtil;
use App\Models\Account as AccountModel;
use App\Models\Connect as ConnectModel;
use App\Models\User as UserModel;
use App\Repos\Connect as ConnectRepo;
use App\Repos\User as UserRepo;
use App\Services\Logic\Service as LogicService;

class OAuthRegister extends LogicService
{

    /**
     * @param array $openUser
     * @return array [UserModel, bool]
     */
    public function handle(array $openUser): array
    {
        $openUser = self::normalizeOpenUser($openUser);

        $openId = $openUser['id'];
        $provider = $openUser['provider'];

        $lockKey = "oauth_auto_register:{$provider}:{$openId}";

        $lockId = LockUtil::addLock($lockKey, 30);

        if ($lockId === false) {
            usleep(100000);

            $connectRepo = new ConnectRepo();

            $connect = $connectRepo->findByOpenId($openId, $provider);

            if ($connect) {
                return [$this->handleExistingConnect($connect, $openUser), false];
            }

            throw new \RuntimeException('sys.service_unavailable');
        }

        try {

            $this->db->begin();

            $connectRepo = new ConnectRepo();

            $connect = $connectRepo->findByOpenId($openId, $provider);

            if ($connect) {

                $user = $this->handleExistingConnect($connect, $openUser);

                $this->db->commit();

                return [$user, false];
            }

            $account = new AccountModel();

            unset($account->id);

            $account->password = '';
            $account->salt = '';

            $account->create();

            $userRepo = new UserRepo();

            $user = $userRepo->findById($account->id);

            if (!$user) {
                throw new BadRequestException('user.not_found');
            }

            $connect = new ConnectModel();

            $connect->user_id = $user->id;
            $connect->union_id = $openUser['unionid'] ?? '';
            $connect->open_id = $openId;
            $connect->open_name = $openUser['name'] ?? '';
            $connect->open_avatar = $openUser['avatar'] ?? '';
            $connect->provider = $provider;

            $connect->create();

            $this->db->commit();

            return [$user, true];

        } catch (BadRequestException $e) {

            $this->db->rollback();

            throw $e;

        } catch (\Exception $e) {

            $this->db->rollback();

            $logger = $this->getLogger();

            $logger->error('OAuth Auto Register Exception: ' . kg_json_encode([
                    'provider' => $provider,
                    'open_id' => $openId,
                    'line' => $e->getLine(),
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                ]));

            throw new \RuntimeException('sys.trans_rollback');

        } finally {

            LockUtil::releaseLock($lockKey, $lockId);

        }
    }

    protected function handleExistingConnect(ConnectModel $connect, array $openUser): UserModel
    {
        $userRepo = new UserRepo();

        $user = $userRepo->findById($connect->user_id);

        if (!$user || $user->deleted == 1) {
            throw new BadRequestException('user.not_found');
        }

        $connect->open_name = $openUser['name'] ?? $connect->open_name;
        $connect->open_avatar = $openUser['avatar'] ?? $connect->open_avatar;

        if (empty($connect->union_id) && !empty($openUser['unionid'])) {
            $connect->union_id = $openUser['unionid'];
        }

        if ($connect->deleted == 1) {
            $connect->deleted = 0;
        }

        $connect->update();

        return $user;
    }

    public static function normalizeOpenUser(array $openUser): array
    {
        $openId = (string)($openUser['id'] ?? '');
        $provider = (int)($openUser['provider'] ?? 0);

        if (empty($openId) || mb_strlen($openId, 'UTF-8') > 64 || $provider <= 0) {
            throw new BadRequestException('connect.invalid_open_user');
        }

        $unionId = (string)($openUser['unionid'] ?? '');
        $name = (string)($openUser['name'] ?? '');
        $avatar = (string)($openUser['avatar'] ?? '');

        if (mb_strlen($avatar, 'UTF-8') > 255) {
            $avatar = '';
        }

        $openUser['id'] = $openId;
        $openUser['provider'] = $provider;
        $openUser['unionid'] = mb_substr($unionId, 0, 64, 'UTF-8');
        $openUser['name'] = mb_substr($name, 0, 30, 'UTF-8');
        $openUser['avatar'] = $avatar;

        return $openUser;
    }

}

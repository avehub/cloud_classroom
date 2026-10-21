<?php


namespace App\Services\Logic\Vip;

use App\Exceptions\BadRequest as BadRequestException;
use App\Models\User as UserModel;
use App\Models\VipRedeemBatch as VipRedeemBatchModel;
use App\Models\VipRedeemCode as VipRedeemCodeModel;
use App\Repos\VipRedeemBatch as VipRedeemBatchRepo;
use App\Repos\VipRedeemCode as VipRedeemCodeRepo;
use App\Services\Logic\Service as LogicService;
use App\Traits\Client as ClientTrait;
use App\Validators\VipRedeem as VipRedeemValidator;

class CodeRedeem extends LogicService
{

    use ClientTrait;

    public function handle()
    {
        $user = $this->getLoginUser();

        $validator = new VipRedeemValidator();

        $code = $validator->checkCode($this->request->getPost('code'));
        $clientIp = $this->getClientIp();

        $this->checkRateLimit($user->id, $clientIp);

        $codeHash = hash('sha256', $code);

        $this->db->begin();

        try {
            $codeRepo = new VipRedeemCodeRepo();

            $redeem = $codeRepo->findByHash($codeHash, true);

            if (!$redeem) {
                throw new BadRequestException('vip_redeem.invalid_code');
            }

            if ($redeem->status == VipRedeemCodeModel::STATUS_USED) {
                if ($redeem->user_id != $user->id) {
                    throw new BadRequestException('vip_redeem.code_used');
                }

                $this->db->commit();

                return $this->handleResult($user, $redeem);
            }

            if ($redeem->status != VipRedeemCodeModel::STATUS_UNUSED) {
                throw new BadRequestException('vip_redeem.code_disabled');
            }

            $batchRepo = new VipRedeemBatchRepo();

            $batch = $batchRepo->findById($redeem->batch_id);

            if (!$batch || $batch->status != VipRedeemBatchModel::STATUS_ENABLED) {
                throw new BadRequestException('vip_redeem.batch_disabled');
            }

            if ($batch->expire_time > 0 && $batch->expire_time <= time()) {
                throw new BadRequestException('vip_redeem.code_expired');
            }

            $lockedUser = UserModel::findFirst([
                'conditions' => 'id = :id:',
                'bind' => ['id' => $user->id],
                'for_update' => true,
            ]);

            if (!$lockedUser) {
                throw new BadRequestException('user.not_found');
            }

            if ($lockedUser->vip == 1 && $lockedUser->vip_permanent == 1) {
                throw new BadRequestException('vip_redeem.already_permanent');
            }

            $lockedUser->vip = 1;
            $lockedUser->vip_permanent = 1;
            $lockedUser->vip_expiry_time = 0;
            $lockedUser->update();

            $redeem->status = VipRedeemCodeModel::STATUS_USED;
            $redeem->user_id = $lockedUser->id;
            $redeem->user_name = $lockedUser->name;
            $redeem->redeem_time = time();
            $redeem->client_ip = $clientIp;
            $redeem->update();

            $this->db->commit();

            return $this->handleResult($lockedUser, $redeem);

        } catch (BadRequestException $e) {
            $this->db->rollback();
            throw $e;
        } catch (\Exception $e) {
            $this->db->rollback();

            $logger = $this->getLogger('vip');
            $logger->error('Vip Code Redeem Exception: ' . kg_json_encode([
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'message' => $e->getMessage(),
                ]));

            throw new \RuntimeException('sys.trans_rollback');
        }
    }

    protected function handleResult(UserModel $user, VipRedeemCodeModel $redeem)
    {
        return [
            'vip' => (int)$user->vip,
            'vip_permanent' => (int)$user->vip_permanent,
            'vip_expiry_time' => (int)$user->vip_expiry_time,
            'redeemed' => true,
            'redeem_time' => (int)$redeem->redeem_time,
        ];
    }

    protected function checkRateLimit($userId, $clientIp)
    {
        $cache = $this->getDI()->getShared('cache');

        $userKey = "vip_redeem_attempt:user:{$userId}";
        $ipKey = 'vip_redeem_attempt:ip:' . hash('sha256', $clientIp);

        $userCount = $cache->increment($userKey);
        $ipCount = $cache->increment($ipKey);

        if ($userCount == 1) {
            $cache->expire($userKey, 600);
        }

        if ($ipCount == 1) {
            $cache->expire($ipKey, 600);
        }

        if ($userCount > 10 || $ipCount > 30) {
            throw new BadRequestException('vip_redeem.too_many_attempts');
        }
    }

}

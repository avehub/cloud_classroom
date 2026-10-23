<?php


namespace App\Services\Logic\Vip;

use App\Repos\VipRedeemCode as VipRedeemCodeRepo;
use App\Services\Logic\Service as LogicService;

class RedeemInfo extends LogicService
{

    public function handle()
    {
        $user = $this->getLoginUser();

        $codeRepo = new VipRedeemCodeRepo();

        $items = $codeRepo->findUserRedeems($user->id);
        $redeems = [];

        foreach ($items as $item) {
            $code = \App\Library\Utils\VipRedeemCode::decrypt($item->code);

            $redeems[] = [
                'code' => substr($code, 0, 4) . '************' . substr($code, -4),
                'redeem_time' => $item->redeem_time,
            ];
        }

        return [
            'user' => [
                'vip' => (int)$user->vip,
                'vip_permanent' => (int)$user->vip_permanent,
                'vip_expiry_time' => (int)$user->vip_expiry_time,
            ],
            'redeems' => $redeems,
        ];
    }

}

<?php


namespace App\Services\Logic\Notice\External\Sms;

use App\Repos\Account as AccountRepo;
use App\Services\Smser;

class RefundFinish extends Smser
{

    protected $templateCode = 'refund_finish';

    /**
     * @param array $params
     * @return bool|null
     */
    public function handle(array $params)
    {
        $accountRepo = new AccountRepo();

        $account = $accountRepo->findById($params['user']['id']);

        if (!$account->phone) return null;

        /**
         * 退款成功，商品名称：${goods_name}，退款序号：${refund_sn}，退款金额：${amount}元
         */
        $params = [
            $params['refund']['subject'],
            $params['refund']['sn'],
            $params['refund']['amount'],
        ];

        return $this->send($account->phone, $this->templateCode, $params);
    }

}

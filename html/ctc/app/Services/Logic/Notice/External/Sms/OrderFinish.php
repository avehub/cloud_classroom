<?php


namespace App\Services\Logic\Notice\External\Sms;

use App\Repos\Account as AccountRepo;
use App\Services\Smser;

class OrderFinish extends Smser
{

    protected $templateCode = 'order_finish';

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
         * 下单成功，商品名称：${goods_name}，订单序号：${order_sn}，订单金额：${amount}元
         */
        $params = [
            $params['order']['subject'],
            $params['order']['sn'],
            $params['order']['amount'],
        ];

        return $this->send($account->phone, $this->templateCode, $params);
    }

}

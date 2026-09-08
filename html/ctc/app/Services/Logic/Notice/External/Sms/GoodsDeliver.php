<?php


namespace App\Services\Logic\Notice\External\Sms;

use App\Repos\Account as AccountRepo;
use App\Services\Smser;

class GoodsDeliver extends Smser
{

    protected $templateCode = 'goods_deliver';

    /**
     * @param array $params
     * @return bool|null
     */
    public function handle(array $params)
    {
        $accountRepo = new AccountRepo();

        $account = $accountRepo->findById($params['user']['id']);

        if (!$account->phone) return null;

        $params['deliver_time'] = date('Y-m-d H:i', $params['deliver_time']);

        /**
         * 发货成功，商品名称：${goods_name}，订单序号：${order_sn}，发货时间：${deliver_time}，请注意查收。
         */
        $params = [
            $params['goods_name'],
            $params['order_sn'],
            $params['deliver_time'],
        ];

        return $this->send($account->phone, $this->templateCode, $params);
    }

}

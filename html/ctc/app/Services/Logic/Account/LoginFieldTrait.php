<?php


namespace App\Services\Logic\Account;

trait LoginFieldTrait
{

    protected function handleLoginFields(array $params): array
    {
        /**
         * 使用[account|phone|email]做账户名字段兼容
         */
        if (isset($params['phone'])) {
            $params['account'] = $params['phone'];
        } elseif (isset($params['email'])) {
            $params['account'] = $params['email'];
        }

        return $params;
    }

}

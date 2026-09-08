<?php


namespace App\Services\Logic\Notice\External\Sms;

use App\Services\Smser as SmserService;
use App\Services\Verify as VerifyService;

class Verify extends SmserService
{

    protected $templateCode = 'verify';

    /**
     * @param string $phone
     * @return bool
     */
    public function handle($phone)
    {
        $verify = new VerifyService();

        $minutes = 5;

        $code = $verify->getSmsCode($phone, 60 * $minutes);

        /**
         * 验证码：${code}，${minutes} 分钟内有效，如非本人操作请忽略。
         */
        $params = [$code, $minutes];

        return $this->send($phone, $this->templateCode, $params);
    }

}

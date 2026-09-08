<?php


namespace App\Services\Logic\Verify;

use App\Library\Captcha as AppCaptcha;
use App\Services\Logic\Service as LogicService;

class Captcha extends LogicService
{

    public function handle()
    {
        $captcha = new AppCaptcha();

        return $captcha->generate();
    }

}

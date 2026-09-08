<?php


namespace App\Services\Logic\Verify;

use App\Library\Captcha as ImageCaptcha;
use App\Services\Logic\Notice\External\Sms\Verify as SmsVerifyService;
use App\Services\Logic\Service as LogicService;
use App\Validators\Captcha as CaptchaValidator;
use App\Validators\Verify as VerifyValidator;

class SmsCode extends LogicService
{

    public function handle()
    {
        $post = $this->request->getPost();

        $validator = new VerifyValidator();

        $phone = $validator->checkPhone($post['phone']);

        $validator = new CaptchaValidator();

        $validator->checkCode($post['ticket'], $post['rand']);

        $captcha = new ImageCaptcha();

        $captcha->clear($post['ticket']);

        $service = new SmsVerifyService();

        $service->handle($phone);
    }

}

<?php


namespace App\Services\Logic\Verify;

use App\Library\Captcha as ImageCaptcha;
use App\Services\Logic\Notice\External\Mail\Verify as MailVerifyService;
use App\Services\Logic\Service as LogicService;
use App\Validators\Captcha as CaptchaValidator;
use App\Validators\Verify as VerifyValidator;

class MailCode extends LogicService
{

    public function handle()
    {
        $post = $this->request->getPost();

        $validator = new VerifyValidator();

        $email = $validator->checkEmail($post['email']);

        $validator = new CaptchaValidator();

        $validator->checkCode($post['ticket'], $post['rand']);

        $captcha = new ImageCaptcha();

        $captcha->clear($post['ticket']);

        $service = new MailVerifyService();

        $service->handle($email);
    }

}

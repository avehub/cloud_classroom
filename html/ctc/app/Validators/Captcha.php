<?php


namespace App\Validators;

use App\Exceptions\BadRequest as BadRequestException;
use App\Library\Captcha as ImageCaptcha;

class Captcha extends Validator
{

    public function checkCode($ticket, $rand)
    {
        $captcha = new ImageCaptcha();

        $result = $captcha->check($ticket, $rand);

        if (!$result) {
            throw new BadRequestException('captcha.invalid_code');
        }
    }

}

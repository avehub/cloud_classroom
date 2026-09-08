<?php


namespace App\Services\Logic\Notice\External\Mail;

use App\Services\Mailer;

class Test extends Mailer
{

    public function handle($email)
    {
        $subject = $this->formatSubject('测试邮件');
        $content = $this->formatContent('东风快递，使命必达');

        return $this->send($email, $subject, $content);
    }

}

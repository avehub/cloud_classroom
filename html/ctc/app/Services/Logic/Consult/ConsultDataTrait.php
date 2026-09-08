<?php


namespace App\Services\Logic\Consult;

use App\Traits\Client as ClientTrait;
use App\Validators\Consult as ConsultValidator;

trait ConsultDataTrait
{

    use ClientTrait;

    protected function handlePostData($post)
    {
        $data = [];

        $data['client_type'] = $this->getClientType();
        $data['client_ip'] = $this->getClientIp();

        $validator = new ConsultValidator();

        if (isset($post['question'])) {
            $data['question'] = $validator->checkQuestion($post['question']);
        }

        if (isset($post['private'])) {
            $data['private'] = $validator->checkPrivateStatus($post['private']);
        }

        return $data;
    }

}

<?php


namespace App\Services\Logic\User\Console;

use App\Services\Logic\Service as LogicService;
use App\Validators\Connect as ConnectValidator;

class ConnectDelete extends LogicService
{

    public function handle($id)
    {
        $user = $this->getLoginUser();

        $validator = new ConnectValidator();

        $connect = $validator->checkById($id);

        $validator->checkOwner($user->id, $connect->user_id);

        $connect->deleted = 1;

        $connect->update();
    }

}

<?php


namespace App\Http\Home\Services;

use App\Models\Connect as ConnectModel;
use App\Repos\Connect as ConnectRepo;

class UserConsole extends Service
{

    public function getWeChatOAConnect()
    {
        $user = $this->getLoginUser();

        $connectRepo = new ConnectRepo();

        return $connectRepo->findByUserId($user->id, ConnectModel::PROVIDER_WECHAT_OA);
    }

}

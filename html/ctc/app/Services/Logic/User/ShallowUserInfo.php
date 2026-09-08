<?php


namespace App\Services\Logic\User;

use App\Models\User as UserModel;
use App\Services\Logic\Service as LogicService;
use App\Services\Logic\UserTrait;

class ShallowUserInfo extends LogicService
{

    use UserTrait;

    public function handle($id)
    {
        $user = $this->checkUser($id);

        return $this->handleUser($user);
    }

    protected function handleUser(UserModel $user)
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'avatar' => $user->avatar,
            'title' => $user->title,
            'about' => $user->about,
            'vip' => $user->vip,
        ];
    }

}

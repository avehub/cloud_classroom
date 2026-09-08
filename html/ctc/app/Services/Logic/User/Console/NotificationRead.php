<?php


namespace App\Services\Logic\User\Console;

use App\Repos\Notification as NotificationRepo;
use App\Services\Logic\Service as LogicService;

class NotificationRead extends LogicService
{

    public function handle()
    {
        $user = $this->getLoginUser();

        if ($user->notice_count == 0) return;

        $user->notice_count = 0;

        $user->update();

        $notifyRepo = new NotificationRepo();

        $notifyRepo->markAllAsViewed($user->id);
    }

}

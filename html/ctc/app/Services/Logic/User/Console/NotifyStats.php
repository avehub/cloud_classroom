<?php


namespace App\Services\Logic\User\Console;

use App\Services\Logic\Service as LogicService;

class NotifyStats extends LogicService
{

    public function handle()
    {
        $user = $this->getLoginUser();

        return ['notice_count' => $user->notice_count];
    }

}

<?php


namespace App\Console\Migrations;

use App\Models\UserBalance;
use App\Repos\User as UserRepo;

class V20250110191618 extends Migration
{

    public function run()
    {
        $this->handleRootUserBalance();
    }

    /**
     * 之前migration初始化root账号缺少user_balance数据
     */
    protected function handleRootUserBalance()
    {
        $userId = 10000;

        $userRepo = new UserRepo();

        $userBalance = $userRepo->findUserBalance($userId);

        if ($userBalance) return;

        $userBalance = new UserBalance();

        $userBalance->user_id = $userId;

        $userBalance->create();
    }

}

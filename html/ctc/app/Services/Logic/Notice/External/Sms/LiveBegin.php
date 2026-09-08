<?php


namespace App\Services\Logic\Notice\External\Sms;

use App\Repos\Account as AccountRepo;
use App\Services\Smser;

class LiveBegin extends Smser
{

    protected $templateCode = 'live_begin';

    /**
     * @param array $params
     * @return bool|null
     */
    public function handle(array $params)
    {
        $accountRepo = new AccountRepo();

        $account = $accountRepo->findById($params['user']['id']);

        if (!$account->phone) return null;

        $params['live']['start_time'] = date('H:i', $params['live']['start_time']);

        /**
         * 直播预告，课程名称：${course_name}，章节名称：${chapter_name}，开播时间：${start_time}
         */
        $params = [
            $params['course']['title'],
            $params['chapter']['title'],
            $params['live']['start_time'],
        ];

        return $this->send($account->phone, $this->templateCode, $params);
    }

}

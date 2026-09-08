<?php


namespace App\Services\Logic\Teacher\Console;

use App\Services\Logic\Service as LogicService;
use App\Services\Logic\Teacher\CourseList as CourseListService;

class CourseList extends LogicService
{

    public function handle()
    {
        $user = $this->getLoginUser();

        $service = new CourseListService();

        return $service->handle($user->id);
    }

}

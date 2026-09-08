<?php


namespace App\Services\Logic\User\Console;

use App\Library\Paginator\Query as PagerQuery;
use App\Repos\Answer as AnswerRepo;
use App\Services\Logic\Answer\AnswerList as AnswerListService;
use App\Services\Logic\Service as LogicService;

class AnswerList extends LogicService
{

    public function handle()
    {
        $user = $this->getLoginUser();

        $pagerQuery = new PagerQuery();

        $params = $pagerQuery->getParams();

        $params['owner_id'] = $user->id;
        $params['deleted'] = 0;

        $sort = $pagerQuery->getSort();
        $page = $pagerQuery->getPage();
        $limit = $pagerQuery->getLimit();

        $answerRepo = new AnswerRepo();

        $pager = $answerRepo->paginate($params, $sort, $page, $limit);

        return $this->handleAnswers($pager);
    }

    protected function handleAnswers($pager)
    {
        $service = new AnswerListService();

        return $service->handleAnswers($pager);
    }

}

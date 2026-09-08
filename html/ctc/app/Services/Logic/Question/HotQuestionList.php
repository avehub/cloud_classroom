<?php


namespace App\Services\Logic\Question;

use App\Caches\HotQuestionList as HotQuestionListCache;
use App\Services\Logic\Service as LogicService;

class HotQuestionList extends LogicService
{

    public function handle()
    {
        $limit = $this->request->getQuery('limit', 'int', 10);

        $cache = new HotQuestionListCache();

        $list = $cache->get();

        if($limit < count($list)) {
            $list = array_slice($list, $limit);
        }

        return $list;
    }

}

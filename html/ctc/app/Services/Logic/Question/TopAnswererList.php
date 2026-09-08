<?php


namespace App\Services\Logic\Question;

use App\Caches\TopAnswererList as TopAnswererListCache;
use App\Services\Logic\Service as LogicService;

class TopAnswererList extends LogicService
{

    public function handle()
    {
        $limit = $this->request->getQuery('limit', 'int', 10);

        $cache = new TopAnswererListCache();

        $list = $cache->get();

        if($limit < count($list)) {
            $list = array_slice($list, $limit);
        }

        return $list;
    }

}

<?php


namespace App\Services\Logic\Article;

use App\Caches\TopAuthorList as TopAuthorListCache;
use App\Services\Logic\Service as LogicService;

class TopAuthorList extends LogicService
{

    public function handle()
    {
        $limit = $this->request->getQuery('limit', 'int', 10);

        $cache = new TopAuthorListCache();

        $list = $cache->get();

        if($limit < count($list)) {
            $list = array_slice($list, $limit);
        }

        return $list;
    }

}

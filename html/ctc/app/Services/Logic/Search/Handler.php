<?php


namespace App\Services\Logic\Search;

use App\Services\Logic\Service as LogicService;

abstract class Handler extends LogicService
{

    abstract function search();

    abstract function getHotQuery($limit, $type);

    abstract function getRelatedQuery($query, $limit);

    protected function handleKeywords($str)
    {
        return kg_substr($str, 0, 50, '');
    }

}

<?php


namespace App\Library\Paginator\Adapter;

use App\Library\Paginator\Query as PaginatorQuery;
use App\Library\Paginator\Repository;
use Phalcon\Paginator\Adapter\QueryBuilder as PhQueryBuilder;
use Phalcon\Paginator\RepositoryInterface;

class QueryBuilder extends PhQueryBuilder
{

    protected $baseUrl;

    protected $params = [];

    public function paginate(): RepositoryInterface
    {
        $repository = parent::paginate();

        $query = new PaginatorQuery();

        $this->baseUrl = $query->getBaseUrl();
        $this->params = $query->getParams();

        $pager = new Repository();

        $pager->setProperties([
            'items' => $repository->items,
            'total_items' => $repository->total_items,
            'total_pages' => $repository->last,
            'current' => $repository->current,
            'limit' => $repository->limit,
            'first' => $this->buildPageUrl($repository->first),
            'previous' => $this->buildPageUrl($repository->previous),
            'next' => $this->buildPageUrl($repository->next),
            'last' => $this->buildPageUrl($repository->last),
        ]);

        return $pager;
    }

    protected function buildPageUrl($page)
    {
        $this->params['page'] = $page;

        return $this->baseUrl . '?' . http_build_query($this->params);
    }

}

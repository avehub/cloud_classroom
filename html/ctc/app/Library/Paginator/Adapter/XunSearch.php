<?php


namespace App\Library\Paginator\Adapter;

use App\Library\Paginator\Query as PaginatorQuery;
use App\Library\Paginator\Repository;
use App\Library\Validators\Common as CommonValidator;
use Phalcon\Paginator\Adapter\AbstractAdapter as PaginatorAdapter;
use Phalcon\Paginator\Exception as PaginatorException;
use Phalcon\Paginator\RepositoryInterface;

/**
 *
 * Pagination using xunsearch as source of data
 *
 * <code>
 * use App\Library\Paginator\Adapter\XunSearch;
 *
 * $paginator = new XunSearch(
 *     [
 *         "xs"  => $xs,
 *         "query"  => $query,
 *         "highlight"  => $highlight,
 *         "page"  => $page,
 *         "limit" => $limit,
 *     ]
 * );
 *</code>
 */
class XunSearch extends PaginatorAdapter
{

    protected $baseUrl;

    protected $params = [];

    public function __construct(array $config)
    {
        if (!isset($config['xs']) || !($config['xs'] instanceof \XS)) {
            throw new PaginatorException('Invalid xs parameter');
        }

        if (empty($config['query'])) {
            throw new PaginatorException('Invalid query parameter');
        }

        if (isset($config['page']) && !CommonValidator::positiveNumber($config['page'])) {
            throw new PaginatorException('Invalid page parameter');
        }

        if (isset($config['limit']) && !CommonValidator::positiveNumber($config['limit'])) {
            throw new PaginatorException('Invalid limit parameter');
        }

        if (isset($config['highlight']) && !is_array($config['highlight'])) {
            throw new PaginatorException('Invalid highlight parameter');
        }

        $config['page'] = $config['page'] ?? 1;
        $config['limit'] = $config['limit'] ?? 12;

        parent::__construct($config);

        $this->config = $config;

        $query = new PaginatorQuery();

        $this->baseUrl = $query->getBaseUrl();
        $this->params = $query->getParams();
    }

    public function paginate(): RepositoryInterface
    {
        /**
         * @var \XS $xs
         */
        $xs = $this->config['xs'];

        $page = $this->page;
        $limit = $this->getLimit();
        $offset = ($page - 1) * $limit;

        $search = $xs->getSearch();

        $docs = $search->setQuery($this->config['query'])
            ->setLimit($limit, $offset)
            ->search();

        $totalCount = $search->getLastCount();

        $fields = array_keys($xs->getAllFields());

        $items = [];

        foreach ($docs as $doc) {
            $item = [];
            foreach ($fields as $field) {
                if (in_array($field, $this->config['highlight'])) {
                    $item[$field] = $search->highlight($doc->{$field});
                } else {
                    $item[$field] = $doc->{$field};
                }
            }
            $items[] = $item;
        }

        $totalPages = (int)ceil($totalCount / $limit);

        $pager = new Repository();

        $pager->setProperties([
            'items' => $items,
            'total_items' => $totalCount,
            'total_pages' => $totalPages,
            'current' => $page,
            'limit' => $limit,
            'first' => $this->buildPageUrl(1),
            'previous' => $this->buildPageUrl($page > 1 ? $page - 1 : 1),
            'next' => $this->buildPageUrl($page < $totalPages ? $page + 1 : $page),
            'last' => $this->buildPageUrl($totalPages),
        ]);

        return $pager;
    }

    public function getPaginate(): RepositoryInterface
    {
        return $this->paginate();
    }

    protected function buildPageUrl($page)
    {
        $this->params['page'] = $page;

        return $this->baseUrl . '?' . http_build_query($this->params);
    }

}

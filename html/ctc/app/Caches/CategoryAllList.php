<?php


namespace App\Caches;

use App\Models\Category as CategoryModel;
use Phalcon\Mvc\Model\Resultset;

class CategoryAllList extends Cache
{

    protected $lifetime = 365 * 86400;

    public function getLifetime()
    {
        return $this->lifetime;
    }

    public function getKey($id = null)
    {
        return "category_all_list:{$id}";
    }

    public function getContent($id = null)
    {
        /**
         * @var Resultset $categories
         */
        $categories = CategoryModel::query()
            ->columns(['id', 'parent_id', 'name', 'priority', 'level', 'path'])
            ->where('type = :type:', ['type' => $id])
            ->orderBy('level ASC, priority ASC')
            ->execute();

        if ($categories->count() == 0) {
            return [];
        }

        return $categories->toArray();
    }

}

<?php


namespace App\Services;

use App\Caches\Category as CategoryCache;
use App\Caches\CategoryList as CategoryListCache;
use App\Caches\CategoryTreeList as CategoryTreeListCache;
use App\Models\Category as CategoryModel;

class Category extends Service
{

    public function getCategoryOptions($type)
    {
        $cache = new CategoryTreeListCache();

        $categories = $cache->get($type);

        $result = [];

        if (!$categories) return $result;

        foreach ($categories as $category) {
            $result[] = [
                'id' => $category['id'],
                'name' => $category['name'],
            ];
            if (count($category['children']) > 0) {
                foreach ($category['children'] as $child) {
                    $result[] = [
                        'id' => $child['id'],
                        'name' => sprintf('|--- %s', $child['name']),
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * 获取节点路径
     *
     * @param int $id
     * @return array
     */
    public function getCategoryPaths($id)
    {
        $categoryCache = new CategoryCache();

        $category = $categoryCache->get($id);

        if (!$category) {
            return [];
        }

        $result = [
            [
                'id' => $category->id,
                'name' => $category->name,
            ]
        ];

        /**
         * 父级编号为 0，或历史脏数据 level=0，均按顶级分类处理。
         * 不能去读取 category:0，否则会把 null 写入缓存并污染后续读取。
         */
        if ($category->parent_id <= 0 || $category->level <= 1) {
            return $result;
        }

        $parent = $categoryCache->get($category->parent_id);

        if (!$parent) {
            return $result;
        }

        return [
            [
                'id' => $parent->id,
                'name' => $parent->name,
            ],
            [
                'id' => $category->id,
                'name' => $category->name,
            ]
        ];
    }

    /**
     * 获取子节点
     *
     * @param string $type
     * @param int $id
     * @return array
     */
    public function getChildCategories($type, $id)
    {
        $categoryListCache = new CategoryListCache();

        $categories = $categoryListCache->get($type);

        $result = [];

        foreach ($categories as $category) {
            if ($category['parent_id'] == $id) {
                $result[] = $category;
            }
        }

        return $result;
    }

    /**
     * 获取子节点ID
     *
     * @param int $id
     * @return array
     */
    public function getChildCategoryIds($id)
    {
        $categoryCache = new CategoryCache();

        /**
         * @var CategoryModel $category
         */
        $category = $categoryCache->get($id);

        if (!$category) {
            return [];
        }

        if ($category->level == 2) {
            return [$id];
        }

        $categoryListCache = new CategoryListCache();

        $categories = $categoryListCache->get($category->type);

        $result = [];

        foreach ($categories as $category) {
            if ($category['parent_id'] == $id) {
                $result[] = $category['id'];
            }
        }

        return $result;
    }

}

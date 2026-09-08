<?php


namespace App\Builders;

use App\Models\Category as CategoryModel;
use App\Repos\Category as CategoryRepo;

class CategoryTreeList extends Builder
{

    public function handle($type)
    {
        $categoryRepo = new CategoryRepo();

        $topCategories = $categoryRepo->findTopCategories($type);

        if ($topCategories->count() == 0) {
            return [];
        }

        $list = [];

        foreach ($topCategories as $category) {
            $list[] = [
                'id' => $category->id,
                'name' => $category->name,
                'alias' => $category->alias,
                'icon' => $category->icon,
                'children' => $this->handleChildren($category),
            ];
        }

        return $list;
    }

    protected function handleChildren(CategoryModel $category)
    {
        $categoryRepo = new CategoryRepo();

        $subCategories = $categoryRepo->findChildCategories($category->id);

        if ($subCategories->count() == 0) {
            return [];
        }

        $list = [];

        foreach ($subCategories as $category) {
            $list[] = [
                'id' => $category->id,
                'name' => $category->name,
                'alias' => $category->alias,
                'icon' => $category->icon,
            ];
        }

        return $list;
    }

}

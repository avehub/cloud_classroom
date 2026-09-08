<?php


namespace App\Http\Admin\Services;

use App\Services\Utils\IndexPageCache as IndexPageCacheUtil;

class Util extends Service
{

    public function handleIndexCache()
    {
        $items = $this->request->getPost('items');

        $sections = [
            'slide',
            'featured_course',
            'new_course',
            'free_course',
            'vip_course',
        ];

        if (empty($items)) {
            $items = $sections;
        }

        $util = new IndexPageCacheUtil();

        foreach ($sections as $section) {
            if (in_array($section, $items)) {
                $util->rebuild($section);
            }
        }
    }

}

<?php


namespace App\Services\Logic\Page;

use App\Models\Page as PageModel;
use App\Services\Logic\PageTrait;
use App\Services\Logic\Service as LogicService;

class PageInfo extends LogicService
{

    use PageTrait;

    public function handle($id)
    {
        $page = $this->checkPage($id);

        $this->incrPageViewCount($page);

        $this->eventsManager->fire('Page:afterView', $this, $page);

        return $this->handlePage($page);
    }

    protected function handlePage(PageModel $page)
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'keywords' => $page->keywords,
            'content' => $page->content,
            'published' => $page->published,
            'deleted' => $page->deleted,
            'view_count' => $page->view_count,
            'create_time' => $page->create_time,
            'update_time' => $page->update_time,
        ];
    }

    protected function incrPageViewCount(PageModel $page)
    {
        $page->view_count += 1;

        $page->update();
    }

}

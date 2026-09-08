<?php


namespace App\Listeners;

use App\Models\Page as PageModel;
use Phalcon\Events\Event as PhEvent;

class Page extends Listener
{

    public function afterCreate(PhEvent $event, $source, PageModel $page)
    {

    }

    public function afterUpdate(PhEvent $event, $source, PageModel $page)
    {

    }

    public function afterDelete(PhEvent $event, $source, PageModel $page)
    {

    }

    public function afterRestore(PhEvent $event, $source, PageModel $page)
    {

    }

    public function afterView(PhEvent $event, $source, PageModel $page)
    {

    }

}
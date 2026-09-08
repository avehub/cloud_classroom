<?php


namespace App\Listeners;

use App\Models\Help as HelpModel;
use Phalcon\Events\Event as PhEvent;

class Help extends Listener
{

    public function afterCreate(PhEvent $event, $source, HelpModel $help)
    {

    }

    public function afterUpdate(PhEvent $event, $source, HelpModel $help)
    {

    }

    public function afterDelete(PhEvent $event, $source, HelpModel $help)
    {

    }

    public function afterRestore(PhEvent $event, $source, HelpModel $help)
    {

    }

    public function afterView(PhEvent $event, $source, HelpModel $help)
    {

    }

}
<?php


namespace App\Listeners;

use App\Services\Service as AppService;
use App\Traits\Service as ServiceTrait;
use Phalcon\Di\Injectable as UserPlugin;

class Listener extends UserPlugin
{

    use ServiceTrait;

    public function getLogger($channel = 'listen')
    {
        $appService = new AppService();

        return $appService->getLogger($channel);
    }

}

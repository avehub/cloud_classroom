<?php


namespace App\Providers;

use Phalcon\Flash\Session as PhFlashSession;

class FlashSession extends Provider
{

    protected $serviceName = 'flashSession';

    public function register()
    {
        $this->di->setShared($this->serviceName, function () {

            $flash = new PhFlashSession();

            $flash->setCssClasses([
                'error' => 'alert alert-danger',
                'success' => 'alert alert-success',
                'notice' => 'alert alert-info',
                'warning' => 'alert alert-warning',
            ]);

            return $flash;
        });
    }

}

<?php


namespace App\Providers;

use App\Library\Session\RedisHandler;
use Phalcon\Config\Config;
use Phalcon\Session\Manager as SessionManager;

class Session extends Provider
{

    protected $serviceName = 'session';

    public function register()
    {
        /**
         * @var Config $config
         */
        $config = $this->di->getShared('config');

        $this->di->setShared($this->serviceName, function () use ($config) {

            $session = new SessionManager();

            $session->setAdapter(new RedisHandler([
                'host' => $config->path('redis.host'),
                'port' => $config->path('redis.port'),
                'auth' => $config->path('redis.auth'),
                'index' => $config->path('redis.index'),
                'lifetime' => $config->path('session.lifetime'),
                'prefix' => '_SESSION_:',
            ]));

            $session->start();

            return $session;
        });
    }

}

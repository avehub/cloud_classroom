<?php


namespace App\Providers;

use App\Library\Cache\Backend\Redis as RedisBackend;
use Phalcon\Config\Config;

class Cache extends Provider
{

    protected $serviceName = 'cache';

    public function register()
    {
        /**
         * @var Config $config
         */
        $config = $this->di->getShared('config');

        $this->di->setShared($this->serviceName, function () use ($config) {

            return new RedisBackend([
                'host' => $config->path('redis.host'),
                'port' => $config->path('redis.port'),
                'auth' => $config->path('redis.auth'),
                'index' => $config->path('redis.index'),
                'lifetime' => $config->path('cache.lifetime'),
            ]);
        });
    }

}

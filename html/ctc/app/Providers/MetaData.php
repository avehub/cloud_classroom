<?php


namespace App\Providers;

use Phalcon\Cache\AdapterFactory as CacheAdapterFactory;
use Phalcon\Config\Config;
use Phalcon\Mvc\Model\MetaData\Memory as MemoryMetaData;
use Phalcon\Mvc\Model\MetaData\Redis as RedisMetaData;
use Phalcon\Storage\SerializerFactory;

class MetaData extends Provider
{

    protected $serviceName = 'modelsMetadata';

    public function register()
    {
        /**
         * @var Config $config
         */
        $config = $this->di->getShared('config');

        $this->di->setShared($this->serviceName, function () use ($config) {

            if ($config->get('env') == ENV_DEV) {

                $metaData = new MemoryMetaData();

            } else {

                $statsKey = '_METADATA_';

                $adapterFactory = new CacheAdapterFactory(new SerializerFactory());

                $metaData = new RedisMetaData($adapterFactory, [
                    'host' => $config->path('redis.host'),
                    'port' => $config->path('redis.port'),
                    'auth' => $config->path('redis.auth'),
                    'index' => $config->path('redis.index'),
                    'lifetime' => $config->path('metadata.lifetime'),
                    'prefix' => $statsKey . ':',
                    'defaultSerializer' => 'Igbinary',
                ]);
            }

            return $metaData;
        });
    }

}

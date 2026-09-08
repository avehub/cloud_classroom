<?php

namespace App\Providers;

use Phalcon\Annotations\Adapter\Files as FilesAnnotations;
use Phalcon\Annotations\Adapter\Memory as MemoryAnnotations;
use Phalcon\Config;

class Annotation extends Provider
{

    protected $serviceName = 'annotations';

    public function register()
    {
        /**
         * @var Config $config
         */
        $config = $this->di->getShared('config');

        $this->di->setShared($this->serviceName, function () use ($config) {

            if ($config->get('env') == ENV_DEV) {

                $annotations = new MemoryAnnotations();

            } else {

                $annotationsDir = storage_path('cache/annotations/');

                if (!is_dir($annotationsDir)) {
                    @mkdir($annotationsDir, 0777, true);
                }

                if (class_exists('Phalcon\Annotations\Adapter\Files')) {
                    $annotations = new FilesAnnotations([
                        'annotationsDir' => $annotationsDir,
                        'lifetime' => $config->path('annotation.lifetime', 86400),
                    ]);
                } else {
                    $annotations = new MemoryAnnotations();
                }
            }

            return $annotations;
        });
    }

}
<?php


namespace Bootstrap;

use Phalcon\Autoload\Loader;
use Phalcon\Cli\Console;
use Phalcon\Config\Config;
use Phalcon\Di\Di;
use Phalcon\Mvc\Application;

abstract class Kernel
{

    /**
     * @var Di
     */
    protected $di;

    /**
     * @var Application|Console
     */
    protected $app;

    /**
     * @var Loader
     */
    protected $loader;

    protected function initAppEnv()
    {
        require __DIR__ . '/Helper.php';
    }

    protected function registerSettings()
    {
        /**
         * @var Config $config
         */
        $config = $this->di->getShared('config');

        ini_set('date.timezone', $config->get('timezone'));

        if ($config->get('env') == ENV_DEV) {
            ini_set('display_errors', 1);
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', 0);
            error_reporting(0);
        }
    }

    abstract public function handle();

    abstract protected function registerLoaders();

    abstract protected function registerServices();

    abstract protected function registerErrorHandler();

}

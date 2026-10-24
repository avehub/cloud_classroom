<?php


namespace App\Library;

use Phalcon\Config\Config;
use Phalcon\Di\Di;
use Phalcon\Logger\Adapter\Stream as StreamLogger;
use Phalcon\Logger\Logger as PhalconLogger;

class Logger
{

    /**
     * @param string $channel
     * @return PhalconLogger
     */
    public function getInstance($channel = 'common')
    {
        /**
         * @var Config $config
         */
        $config = Di::getDefault()->getShared('config');

        $filename = sprintf('%s-%s.log', $channel, date('Y-m-d'));

        $path = log_path($filename);

        $level = $config->get('env') != ENV_DEV ? $config->path('log.level') : PhalconLogger::DEBUG;

        $logger = new PhalconLogger($channel, [
            $channel => new StreamLogger($path),
        ]);

        $logger->setLogLevel($level);

        return $logger;
    }

}

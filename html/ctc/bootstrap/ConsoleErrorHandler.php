<?php


namespace Bootstrap;

use App\Library\Logger as AppLogger;
use Throwable;

class ConsoleErrorHandler extends ErrorHandler
{

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @param Throwable $e
     */
    public function handleException($e)
    {
        $logger = $this->getLogger();

        $content = sprintf('%s(%d): %s', $e->getFile(), $e->getLine(), $e->getMessage());

        $logger->error($content);

        $config = $this->getConfig();

        if ($config->path('env') == 'dev' || $config->path('log.trace')) {

            $trace = sprintf('Trace Content: %s', $e->getTraceAsString());

            $logger->error($trace);

            $content .= $trace;
        }

        echo $content . PHP_EOL;
    }

    protected function getLogger()
    {
        $logger = new AppLogger();

        return $logger->getInstance('console');
    }

}

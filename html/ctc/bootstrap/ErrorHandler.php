<?php


namespace Bootstrap;

use App\Listeners\Db as DbListener;
use Phalcon\Config;
use Phalcon\Di\Injectable;
use Throwable;

abstract class ErrorHandler extends Injectable
{

    public function __construct()
    {
        set_exception_handler([$this, 'handleException']);

        set_error_handler([$this, 'handleError']);

        register_shutdown_function([$this, 'handleShutdown']);
    }

    public function handleError($errNo, $errMsg, $errFile, $errLine)
    {
        if (in_array($errNo, [E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_WARNING, E_USER_NOTICE, E_USER_DEPRECATED])) {
            return true;
        }

        $logger = $this->getLogger();

        $logger->error("Error [{$errNo}]: {$errMsg} in {$errFile} on line {$errLine}");

        return false;
    }

    public function handleShutdown()
    {
        $error = error_get_last();

        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {

            $logger = $this->getLogger();

            $logger->error("Fatal Error [{$error['type']}]: {$error['message']} in {$error['file']} on line {$error['line']}");
        }
    }

    /**
     * @return Config
     */
    protected function getConfig()
    {
        return $this->getDI()->getShared('config');
    }

    /**
     * 获取数据库异常对应的 SQL 内容（最近一次执行的语句与绑定参数）
     *
     * @param Throwable $e
     * @return string
     */
    protected function getFailedSql($e)
    {
        if (!$e instanceof Throwable) return '';

        $isDbException = false;

        while ($e instanceof Throwable) {

            if ($e instanceof \PDOException || strpos($e->getMessage(), 'SQLSTATE') !== false) {
                $isDbException = true;
                break;
            }

            $e = $e->getPrevious();
        }

        return $isDbException ? DbListener::getLastSql() : '';
    }

    /**
     * @param Throwable $e
     */
    abstract public function handleException($e);

    abstract protected function getLogger();

}

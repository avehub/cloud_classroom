<?php


namespace App\Listeners;

use Phalcon\Db\Adapter as DbAdapter;
use Phalcon\Db\Profiler as DbProfiler;
use Phalcon\Events\Event as PhEvent;
use Phalcon\Logger\Adapter\File as FileLogger;

class Db extends Listener
{

    /**
     * 单个绑定参数值的记录长度上限
     */
    const MAX_VALUE_LENGTH = 300;

    /**
     * 单条 SQL 日志长度上限
     */
    const MAX_LOG_LENGTH = 4000;

    /**
     * @var FileLogger
     */
    protected $logger;

    /**
     * @var DbProfiler
     */
    protected $profiler;

    /**
     * 是否输出查询日志（仅开发环境开启）
     *
     * @var bool
     */
    protected $logEnabled = false;

    /**
     * 最近一次执行的 SQL 语句
     *
     * @var string
     */
    protected static $lastStatement;

    /**
     * 最近一次执行的 SQL 绑定参数
     *
     * @var array
     */
    protected static $lastVariables = [];

    /**
     * @param bool $logEnabled
     */
    public function __construct($logEnabled = false)
    {
        $this->logEnabled = (bool)$logEnabled;

        if ($this->logEnabled) {
            $this->logger = $this->getLogger('sql');
            $this->profiler = new DbProfiler();
        }
    }

    /**
     * @param PhEvent $event
     * @param DbAdapter $connection
     */
    public function beforeQuery(PhEvent $event, $connection)
    {
        self::$lastStatement = $connection->getSqlStatement();

        $variables = $connection->getSqlVariables();

        self::$lastVariables = is_array($variables) ? $variables : [];

        if (!$this->logEnabled) return;

        $this->profiler->startProfile($connection->getSqlStatement(), $connection->getSqlVariables());
    }

    /**
     * @param PhEvent $event
     * @param DbAdapter $connection
     */
    public function afterQuery(PhEvent $event, $connection)
    {
        if (!$this->logEnabled) return;

        $this->profiler->stopProfile();

        foreach ($this->profiler->getProfiles() as $profile) {

            $statement = sprintf('sql statement: %s', $profile->getSqlStatement());
            $elapsedTime = sprintf('elapsed time: %03f seconds', $profile->getTotalElapsedSeconds());

            $this->logger->debug('--- BEGIN OF QUERY ---');
            $this->logger->debug($statement);

            if ($profile->getSqlVariables()) {
                $variables = sprintf('sql variables: %s', kg_json_encode($profile->getSqlVariables()));
                $this->logger->debug($variables);
            }

            $this->logger->debug($elapsedTime);
            $this->logger->debug('--- END OF QUERY ---');
        }
    }

    /**
     * 获取最近一次执行的 SQL 内容
     *
     * 数据库异常抛出时，Phalcon 会清空连接上的语句信息，
     * 故在此处留存，便于错误日志输出具体入库数据
     *
     * @return string
     */
    public static function getLastSql()
    {
        if (empty(self::$lastStatement)) return '';

        $content = sprintf('statement: %s', self::$lastStatement);

        if (self::$lastVariables) {

            $variables = self::filterVariables(self::$lastVariables);

            $content .= sprintf(' | variables: %s', kg_json_encode($variables));
            $content .= sprintf(' | raw sql: %s', self::interpolate(self::$lastStatement, $variables));
        }

        if (strlen($content) > self::MAX_LOG_LENGTH) {
            $content = substr($content, 0, self::MAX_LOG_LENGTH) . '...(truncated)';
        }

        return $content;
    }

    /**
     * 过滤绑定参数值，避免对象与超长内容污染日志
     *
     * @param array $variables
     * @return array
     */
    protected static function filterVariables(array $variables)
    {
        $result = [];

        foreach ($variables as $key => $value) {

            if (is_array($value)) {
                $value = kg_json_encode($value);
            } elseif (is_bool($value)) {
                $value = $value ? 1 : 0;
            } elseif (is_object($value)) {
                $value = method_exists($value, '__toString') ? (string)$value : get_class($value);
            }

            if (is_string($value) && strlen($value) > self::MAX_VALUE_LENGTH) {
                $value = sprintf('%s...(length: %d)', substr($value, 0, self::MAX_VALUE_LENGTH), strlen($value));
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * 将绑定参数回填到占位符，生成可读的完整 SQL
     *
     * @param string $statement
     * @param array $variables
     * @return string
     */
    protected static function interpolate($statement, array $variables)
    {
        if (strpos($statement, '?') === false) {
            return $statement;
        }

        $values = array_values($variables);

        $index = 0;

        return preg_replace_callback('/\?/', function () use (&$index, $values) {

            if (!array_key_exists($index, $values)) {
                return '?';
            }

            $value = $values[$index];

            $index++;

            if (is_null($value)) return 'NULL';

            if (is_int($value) || is_float($value)) return (string)$value;

            return sprintf("'%s'", addslashes((string)$value));

        }, $statement);
    }

}

<?php


const ENV_DEV = 'dev';
const ENV_TEST = 'test';
const ENV_PRO = 'pro';

/**
 * 从 .env 文件兜底加载环境变量
 *
 * 容器内 cron 不会把 compose 注入的环境变量传给任务进程（cron 只提供
 * HOME/LOGNAME/PATH/SHELL），而 config.php 全部通过 getenv() 读取密钥，
 * 于是定时任务会集体报 "NOAUTH Authentication required" 或数据库拒绝连接。
 *
 * 这里只在变量确实缺失时写入，php-fpm / websocket / docker exec 等
 * 本来就带环境的场景不受影响，也不会覆盖显式传入的值。
 * 值按字面量处理（不展开 $VAR、不解析转义），与 config.php 的读取方式一致。
 *
 * @param string|null $path .env 路径，默认取只读挂载点 /etc/ctc/app.env
 * @return int 实际载入的变量数量
 */
function kg_load_env_file($path = null)
{
    if ($path === null) {
        /**
         * 依次尝试：容器内只读挂载点 -> 应用根目录 -> 仓库根目录（宿主机直接跑 CLI 的场景）
         */
        $candidates = ['/etc/ctc/app.env', root_path('.env'), dirname(__DIR__, 2) . '/.env'];

        foreach ($candidates as $candidate) {
            if (is_readable($candidate)) {
                $path = $candidate;
                break;
            }
        }
    }

    if (!$path || !is_readable($path)) {
        return 0;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        return 0;
    }

    $loaded = 0;

    foreach ($lines as $line) {

        $line = trim(str_replace("\r", '', $line));

        if ($line === '' || $line[0] === '#') continue;

        if (strpos($line, 'export ') === 0) {
            $line = trim(substr($line, 7));
        }

        $pos = strpos($line, '=');

        if ($pos === false) continue;

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) continue;

        /**
         * 去掉成对的包裹引号
         */
        $length = strlen($value);

        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        if (getenv($key) !== false) continue;

        putenv($key . '=' . $value);

        $_ENV[$key] = $value;

        $loaded++;
    }

    return $loaded;
}

/**
 * Get the root path.
 *
 * @param string $path
 * @return string
 */
function root_path($path = '')
{
    return dirname(__DIR__) . trim_path($path);
}

/**
 * Get the application path.
 *
 * @param string $path
 * @return string
 */
function app_path($path = '')
{
    return root_path('app') . trim_path($path);
}

/**
 * Get the bootstrap path.
 *
 * @param string $path
 * @return string
 */
function bootstrap_path($path = '')
{
    return root_path('bootstrap') . trim_path($path);
}

/**
 * Get the configuration path.
 *
 * @param string $path
 * @return string
 */
function config_path($path = '')
{
    return root_path('config') . trim_path($path);
}

/**
 * Get the storage path.
 *
 * @param string $path
 * @return string
 */
function storage_path($path = '')
{
    return root_path('storage') . trim_path($path);
}

/**
 * Get the vendor path.
 *
 * @param string $path
 * @return string
 */
function vendor_path($path = '')
{
    return root_path('vendor') . trim_path($path);
}

/**
 * Get the public path.
 *
 * @param string $path
 * @return string
 */
function public_path($path = '')
{
    return root_path('public') . trim_path($path);
}

/**
 * Get the static path.
 *
 * @param string $path
 * @return string
 */
function static_path($path = '')
{
    return public_path('static') . trim_path($path);
}

/**
 * Get the cache path.
 *
 * @param string $path
 * @return string
 */
function cache_path($path = '')
{
    return storage_path('cache') . trim_path($path);
}

/**
 * Get the log path.
 *
 * @param string $path
 * @return string
 */
function log_path($path = '')
{
    return storage_path('log') . trim_path($path);
}

/**
 * Get the tmp path.
 *
 * @param string $path
 * @return string
 */
function tmp_path($path = '')
{
    return storage_path('tmp') . trim_path($path);
}

/**
 * Trim path slash
 *
 * @param string $path
 * @return string
 */
function trim_path($path)
{
    $path = trim($path, '/');

    return $path ? "/{$path}" : '';
}

/**
 * Dump the args then exit.
 *
 * @param array $args
 */
function dd(...$args)
{
    foreach ($args as $arg) {
        var_dump($arg);
    }
    exit();
}

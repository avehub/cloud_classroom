<?php


$config = [];

/**
 * 运行环境（dev|test|pro）
 */
$config['env'] = getenv('APP_ENV') ?: 'pro';

/**
 * 密钥
 */
$config['key'] = getenv('APP_KEY') ?: 'Zc4EN90quNxfhsOU';

/**
 * 所在时区
 */
$config['timezone'] = getenv('APP_TIMEZONE') ?: 'Asia/Shanghai';

/**
 * 日志级别
 */
$config['log']['level'] = Phalcon\Logger::INFO;

/**
 * 日志链路
 */
$config['log']['trace'] = (getenv('APP_LOG_TRACE') === 'true');

/**
 * 网站根地址，必须以"/"结尾
 */
$config['base_uri'] = getenv('APP_BASE_URI') ?: '/';

/**
 * 静态资源根地址，必须以"/"结尾
 */
$config['static_base_uri'] = getenv('APP_STATIC_BASE_URI') ?: '/static/';

/**
 * 静态资源版本
 */
$config['static_version'] = getenv('APP_STATIC_VERSION') ?: '202004080830';

/**
 * 数据库主机名
 */
$config['db']['host'] = getenv('MYSQL_HOST') ?: 'mysql';

/**
 * 数据库端口
 */
$config['db']['port'] = (int)(getenv('MYSQL_PORT') ?: 3306);

/**
 * 数据库名称
 */
$config['db']['dbname'] = getenv('MYSQL_DATABASE') ?: 'ctc';

/**
 * 数据库用户名
 */
$config['db']['username'] = getenv('MYSQL_USER') ?: 'ctc';

/**
 * 数据库密码
 */
$config['db']['password'] = getenv('MYSQL_PASSWORD') ?: '1qaz2wsx3edc';

/**
 * 数据库编码
 */
$config['db']['charset'] = getenv('MYSQL_CHARSET') ?: 'utf8mb4';

/**
 * redis主机名
 */
$config['redis']['host'] = getenv('REDIS_HOST') ?: 'redis';

/**
 * redis端口号
 */
$config['redis']['port'] = (int)(getenv('REDIS_PORT') ?: 6379);

/**
 * redis库编号
 */
$config['redis']['index'] = (int)(getenv('REDIS_INDEX') ?: 0);

/**
 * redis密码
 */
$config['redis']['auth'] = getenv('REDIS_PASSWORD') ?: '1qaz2wsx3edc';

/**
 * 缓存有效期（秒）
 */
$config['cache']['lifetime'] = 24 * 3600;

/**
 * 会话有效期（秒）
 */
$config['session']['lifetime'] = 24 * 3600;

/**
 * 令牌有效期（秒）
 */
$config['token']['lifetime'] = 7 * 86400;

/**
 * 元数据有效期（秒）
 */
$config['metadata']['lifetime'] = 7 * 86400;

/**
 * 注解有效期（秒）
 */
$config['annotation']['lifetime'] = 7 * 86400;

/**
 * CsrfToken有效期（秒）
 */
$config['csrf_token']['lifetime'] = 86400;

/**
 * 允许跨域
 */
$config['cors']['enabled'] = true;

/**
 * 允许跨域域名(字符|数组)
 */
$config['cors']['allow_origin'] = '*';

/**
 * 允许跨域字段（string|array）
 */
$config['cors']['allow_headers'] = '*';

/**
 * 允许跨域方法
 */
$config['cors']['allow_methods'] = ['GET', 'POST', 'OPTIONS'];

/**
 * 客户端ping服务端间隔（秒）
 */
$config['websocket']['ping_interval'] = 30;

/**
 * 客户端连接地址（外部可访问的域名或ip），带端口号
 */
$config['websocket']['connect_address'] = getenv('WEBSOCKET_CONNECT_ADDRESS') ?: 'ctc.docker:8282';

/**
 * gateway和worker注册地址，带端口号
 */
$config['websocket']['register_address'] = getenv('WEBSOCKET_REGISTER_ADDRESS') ?: '127.0.0.1:1238';

/**
 * 资源监控: CPU负载（0.1-1.0）
 */
$config['server_monitor']['cpu'] = 0.8;

/**
 * 资源监控: 内存剩余占比（10-100）%
 */
$config['server_monitor']['memory'] = 10;

/**
 * 资源监控: 磁盘剩余占比（10-100）%
 */
$config['server_monitor']['disk'] = 20;

/**
 * 交易有效期（秒）
 */
$config['trade']['lifetime'] = 15 * 60;

/**
 * 阿里云短信服务（配置统一在项目根目录 .env 中维护）
 */
$config['sms']['access_key_id'] = getenv('SMS_ACCESS_KEY_ID') ?: '';

/**
 * 阿里云短信 AccessKey Secret
 */
$config['sms']['access_key_secret'] = getenv('SMS_ACCESS_KEY_SECRET') ?: '';

/**
 * 短信签名
 */
$config['sms']['sign_name'] = getenv('SMS_SIGN_NAME') ?: '';

/**
 * 短信 API 接入区域
 */
$config['sms']['region'] = getenv('SMS_REGION') ?: 'cn-hangzhou';

/**
 * 短信模板 Code（留空表示停用）
 */
$config['sms']['templates'] = [
    'verify' => getenv('SMS_TEMPLATE_VERIFY') ?: '',
    'order_finish' => getenv('SMS_TEMPLATE_ORDER_FINISH') ?: '',
    'refund_finish' => getenv('SMS_TEMPLATE_REFUND_FINISH') ?: '',
    'goods_deliver' => getenv('SMS_TEMPLATE_GOODS_DELIVER') ?: '',
    'live_begin' => getenv('SMS_TEMPLATE_LIVE_BEGIN') ?: '',
    'consult_reply' => getenv('SMS_TEMPLATE_CONSULT_REPLY') ?: '',
];

return $config;

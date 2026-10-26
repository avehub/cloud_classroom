<?php


namespace App\Providers;

use App\Library\Session\RedisHandler;
use Phalcon\Config\Config;
use Phalcon\Session\Manager as SessionManager;

class Session extends Provider
{

    protected $serviceName = 'session';

    public function register()
    {
        /**
         * @var Config $config
         */
        $config = $this->di->getShared('config');

        $di = $this->di;

        $this->di->setShared($this->serviceName, function () use ($config, $di) {

            /**
             * 同时支持 HTTP (gdk.cn) 与 HTTPS (k.gaodekuai.cn) 访问。
             * Secure Cookie 在 HTTP 下不会被浏览器回传，需按当前请求协议动态设置。
             */
            $secure = '0';

            if ($di->has('request')) {
                $request = $di->getShared('request');
                $secure = $request->isSecure() ? '1' : '0';
            }

            ini_set('session.cookie_secure', $secure);
            ini_set('session.cookie_httponly', '1');
            ini_set('session.cookie_samesite', 'Lax');

            $session = new SessionManager();

            $session->setAdapter(new RedisHandler([
                'host' => $config->path('redis.host'),
                'port' => $config->path('redis.port'),
                'auth' => $config->path('redis.auth'),
                'index' => $config->path('redis.index'),
                'lifetime' => $config->path('session.lifetime'),
                'prefix' => '_SESSION_:',
            ]));

            $session->start();

            return $session;
        });
    }

}

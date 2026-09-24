<?php


namespace App\Http\Home\Controllers;

/**
 * 存活探测（供容器健康检查、监控系统调用）
 *
 * 该入口不渲染视图、不读取首页缓存，避免以 localhost 访问的探测请求
 * 把 http://localhost 前缀写入 Redis 缓存，污染前台资源地址
 *
 * @RoutePrefix("/health")
 */
class HealthController extends \Phalcon\Mvc\Controller
{

    /**
     * @Get("", name="home.health")
     */
    public function indexAction()
    {
        $this->view->disable();

        $this->response->setJsonContent([
            'status' => 'ok',
            'time' => date('Y-m-d H:i:s'),
        ]);

        return $this->response;
    }

}

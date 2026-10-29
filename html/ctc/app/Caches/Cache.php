<?php


namespace App\Caches;

use App\Library\Cache\Backend\Redis as RedisCache;
use Phalcon\Di\Injectable;

abstract class Cache extends Injectable
{

    /**
     * @var RedisCache
     */
    protected $cache;

    public function __construct()
    {
        $this->cache = $this->getDI()->getShared('cache');
    }

    /**
     * 获取缓存内容
     *
     * @param mixed $id
     * @return mixed
     */
    public function get($id = null)
    {
        $key = $this->getKey($id);

        if ($this->cache->exists($key)) {

            $content = $this->cache->get($key);

            /**
             * 历史缓存中可能写入了 null。Phalcon Redis 适配器读取 null 后，
             * 同一请求内后续 get 会全部返回 null，因此这里按未命中处理，
             * 删除脏键后回源重建，避免一个不存在的对象拖垮整页缓存。
             */
            if ($content !== null) {
                return $content;
            }

            $this->cache->delete($key);
        }

        $content = $this->getContent($id);

        /**
         * 不缓存 null，避免后续请求反复读到空值并污染缓存连接。
         */
        if ($content === null) {
            return null;
        }

        $this->cache->save($key, $content, $this->getLifetime());

        return $content;
    }

    /**
     * 删除缓存内容
     *
     * @param mixed $id
     */
    public function delete($id = null)
    {
        $key = $this->getKey($id);

        $this->cache->delete($key);
    }

    /**
     * 重建缓存内容
     *
     * @param mixed $id
     */
    public function rebuild($id = null)
    {
        $this->delete($id);

        $this->get($id);
    }

    /**
     * 缓存有效期
     */
    abstract public function getLifetime();

    /**
     * 键值
     *
     * @param mixed $id
     */
    abstract public function getKey($id = null);

    /**
     * 原始内容
     *
     * @param mixed $id
     */
    abstract public function getContent($id = null);

}

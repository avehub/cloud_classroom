<?php


namespace App\Library\Cache\Backend;

use Phalcon\Cache\Adapter\Redis as RedisAdapter;
use Phalcon\Storage\SerializerFactory;

class Redis
{

    /**
     * @var RedisAdapter
     */
    protected $adapter;

    /**
     * @var string
     */
    protected $prefix = '';

    /**
     * @var int
     */
    protected $defaultLifetime = 3600;

    /**
     * @var string|null
     */
    protected $lastKey;

    /**
     * @var int|null
     */
    protected $lastLifetime;

    /**
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $this->prefix = $options['prefix'] ?? '';
        $this->defaultLifetime = $options['lifetime'] ?? 3600;

        $serializerFactory = new SerializerFactory();

        $this->adapter = new RedisAdapter($serializerFactory, [
            'host' => $options['host'] ?? '127.0.0.1',
            'port' => $options['port'] ?? 6379,
            'auth' => $options['auth'] ?? '',
            'index' => $options['index'] ?? 0,
            'lifetime' => $this->defaultLifetime,
            'prefix' => $this->prefix,
            'defaultSerializer' => 'Igbinary',
        ]);
    }

    /**
     * @param string $keyName
     * @param int $lifetime
     * @return mixed|null
     */
    public function get($keyName, $lifetime = null)
    {
        $lastKey = $this->getKeyName($keyName);

        $this->lastKey = $lastKey;
        $this->lastLifetime = $lifetime;

        return $this->adapter->get($lastKey);
    }

    /**
     * @param string $keyName
     * @param mixed $content
     * @param int $lifetime
     * @param bool $stopBuffer
     * @return bool
     */
    public function save($keyName = null, $content = null, $lifetime = null, bool $stopBuffer = true): bool
    {
        $lastKey = $keyName === null ? $this->lastKey : $this->getKeyName($keyName);

        $this->lastKey = $lastKey;
        $this->lastLifetime = $lifetime;

        $ttl = $lifetime === null ? $this->defaultLifetime : (int)$lifetime;

        if ($ttl > 0) {
            return $this->adapter->set($lastKey, $content, $ttl);
        }

        return $this->adapter->setForever($lastKey, $content);
    }

    /**
     * @param string $keyName
     * @return bool
     */
    public function delete($keyName): bool
    {
        return $this->adapter->delete($this->getKeyName($keyName));
    }

    /**
     * @param string $prefix
     * @param int $limit
     * @return array
     */
    public function queryKeys($prefix = null, $limit = 1000): array
    {
        $result = [];

        $redis = $this->getRedis();

        $pattern = $this->prefix . ($prefix ?: '') . '*';

        $cursor = 0;

        do {
            $reply = $redis->rawCommand('SCAN', $cursor, 'MATCH', $pattern, 'COUNT', 100);

            if (!is_array($reply)) break;

            $cursor = (int)$reply[0];

            $result = array_merge($result, $reply[1]);

            if (count($result) >= $limit) break;
        } while ($cursor != 0);

        return $result;
    }

    /**
     * @param string $keyName
     * @param int $lifetime
     * @return bool
     */
    public function exists($keyName = null, $lifetime = null): bool
    {
        $lastKey = $keyName === null ? $this->lastKey : $this->getKeyName($keyName);

        return $this->adapter->has($lastKey);
    }

    /**
     * @param string $keyName
     * @param int $lifetime
     * @return bool
     */
    public function expire($keyName = null, $lifetime = null): bool
    {
        $lastKey = $keyName === null ? $this->lastKey : $this->getKeyName($keyName);

        $ttl = $lifetime === null ? $this->defaultLifetime : (int)$lifetime;

        return $this->getRedis()->expire($lastKey, $ttl);
    }

    /**
     * @param string $keyName
     * @return int|bool
     */
    public function ttl($keyName = null)
    {
        $lastKey = $keyName === null ? $this->lastKey : $this->getKeyName($keyName);

        return $this->getRedis()->ttl($lastKey);
    }

    /**
     * @param string $keyName
     * @param int $value
     * @return int
     */
    public function increment($keyName = null, $value = 1): int
    {
        $lastKey = $keyName === null ? $this->lastKey : $this->getKeyName($keyName);

        return (int)$this->adapter->increment($lastKey, $value);
    }

    /**
     * @param string $keyName
     * @param int $value
     * @return int
     */
    public function decrement($keyName = null, $value = 1): int
    {
        $lastKey = $keyName === null ? $this->lastKey : $this->getKeyName($keyName);

        return (int)$this->adapter->decrement($lastKey, $value);
    }

    /**
     * @return bool
     */
    public function flush(): bool
    {
        return true;
    }

    /**
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * @return \Redis
     */
    public function getRedis()
    {
        return $this->adapter->getAdapter();
    }

    /**
     * @param string $keyName
     * @return string
     */
    public function getKeyName($keyName)
    {
        return $this->prefix . $keyName;
    }

    /**
     * @param string $keyName
     * @return string
     */
    public function getRawKeyName($keyName)
    {
        if ($this->prefix) {
            $keyName = str_replace($this->prefix, '', $keyName);
        }

        return $keyName;
    }

}

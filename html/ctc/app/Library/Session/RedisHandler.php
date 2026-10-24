<?php


namespace App\Library\Session;

use SessionHandlerInterface;

class RedisHandler implements SessionHandlerInterface
{

    /**
     * @var \Redis
     */
    protected $redis;

    /**
     * @var string
     */
    protected $prefix = '_SESSION_:';

    /**
     * @var int
     */
    protected $lifetime = 86400;

    /**
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        $this->prefix = $options['prefix'] ?? $this->prefix;
        $this->lifetime = (int)($options['lifetime'] ?? $this->lifetime);

        $redis = new \Redis();

        $redis->connect(
            $options['host'] ?? '127.0.0.1',
            (int)($options['port'] ?? 6379)
        );

        if (!empty($options['auth'])) {
            $redis->auth($options['auth']);
        }

        if (!empty($options['index'])) {
            $redis->select((int)$options['index']);
        }

        $this->redis = $redis;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $data = $this->redis->get($this->getKeyName($id));

        return $data === false ? '' : $data;
    }

    public function write(string $id, string $data): bool
    {
        return $this->redis->setex($this->getKeyName($id), $this->lifetime, $data);
    }

    public function destroy(string $id): bool
    {
        $this->redis->del($this->getKeyName($id));

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }

    /**
     * @return \Redis
     */
    public function getRedis()
    {
        return $this->redis;
    }

    /**
     * @param string $id
     * @return string
     */
    protected function getKeyName($id)
    {
        return $this->prefix . $id;
    }

}

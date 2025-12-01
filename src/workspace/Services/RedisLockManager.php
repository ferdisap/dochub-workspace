<?php

namespace Dochub\Workspace\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

// Fitur Enterprise: 
// Atomic lock via Redis SET NX PX
// Lua script untuk safe release
// Instance ID untuk traceability
// Auto-expire jika proses crash
class RedisLockManager implements LockManager
{
    /**
     * UUID unik per instance (untuk identifikasi lock ownership)
     */
    private string $instanceId;

    /**
     * Menyimpan nilai lock per kunci (untuk verifikasi saat release)
     * @var array<string, string>
     */
    private array $lockValues = [];

    public function __construct()
    {
        $this->instanceId = Config::get('app.instance_id', substr(md5(gethostname() . microtime()), 0, 12));
    }

    public function acquire(string $key, int $timeoutMs = 30000): bool
    {
        $lockKey = "lock:{$key}";
        $lockValue = $this->generateLockValue();

        // SET key value NX PX timeout → atomic lock
        $acquired = Redis::set($lockKey, $lockValue, 'NX', 'PX', $timeoutMs);

        if ($acquired) {
            $this->lockValues[$key] = $lockValue;
            return true;
        }

        return false;
    }

    public function release(string $key): void
    {
        if (!isset($this->lockValues[$key])) {
            return; // Tidak punya lock ini
        }

        $lockKey = "lock:{$key}";
        $lockValue = $this->lockValues[$key];

        // Lua script: hapus hanya jika nilai cocok (hindari race saat release)
        Redis::eval(<<<LUA
            if redis.call('GET', KEYS[1]) == ARGV[1] then
                return redis.call('DEL', KEYS[1])
            else
                return 0
            end
        LUA, 1, $lockKey, $lockValue);

        unset($this->lockValues[$key]);
    }

    public function withLock(string $key, callable $callback, int $timeoutMs = 30000)
    {
        if (!$this->acquire($key, $timeoutMs)) {
            throw new RuntimeException("Timeout menunggu lock: {$key}");
        }

        try {
            return $callback();
        } finally {
            $this->release($key);
        }
    }

    /**
     * Generate nilai unik untuk lock ini
     */
    private function generateLockValue(): string
    {
        return $this->instanceId . ':' . uniqid('', true) . ':' . getmypid();
    }
}
<?php

namespace Dochub\Upload\Services;

use TusPhp\Cache\Cacheable;
use Dochub\Upload\Tus;
use Illuminate\Support\Facades\App;
use Predis\Client as PredisClient;
use Redis;

// Cara Cek Redis Extension di Server
// php -m | grep redis
// # Output: redis (jika terinstal)
// atau
// <?php
// if (extension_loaded('redis')) {
//     echo "Redis extension: ✅ ADA\n";
//     $r = new Redis();
//     $r->connect('127.0.0.1');
//     echo "Koneksi Redis: ✅ BERHASIL\n";
// } else {
//     echo "Redis extension: ❌ TIDAK ADA\n";
//     if (class_exists('Predis\Client')) {
//         echo "Predis library: ✅ ADA\n";
//     } else {
//         echo "Predis library: ❌ TIDAK ADA\n";
//     }
// }
/**
 * @deprecated karena sudah di TusUploadHandler
 */
class TusRedis implements Cacheable
{
  protected Redis | PredisClient $redis;
  protected int $ttl;
  protected string $prefix;

  public function __construct(mixed $redis)
  {
    $this->redis = $redis;
    $this->ttl = Tus::cache_ttl();
    $this->prefix = rtrim(Tus::cache_prefix(), ':') . ':';
  }

  // =============== Implementasi wajib Cacheable ===============

  public function get(string $key, bool $withExpired = false): mixed
  {
    $fullKey = $this->prefix . $key;
    $value = $this->redis->get($fullKey);

    if ($value === false) {
      return null;
    }

    $data = @unserialize($value);
    if ($data === false || ! is_array($data) || ! isset($data['value'])) {
      return null;
    }

    if (! $withExpired && isset($data['expires_at']) && time() > $data['expires_at']) {
      $this->delete($key);
      return null;
    }

    return $data['value'];
  }

  public function set(string $key, $value): mixed
  {
    $fullKey = $this->prefix . $key;
    $expiresAt = time() + $this->ttl;

    $data = [
      'value' => $value,
      'expires_at' => $expiresAt,
      'created_at' => time(),
    ];

    $serialized = serialize($data);
    $this->redis->setex($fullKey, $this->ttl, $serialized);

    return $value;
  }

  public function delete(string $key): bool
  {
    $fullKey = $this->prefix . $key;
    return $this->redis->del($fullKey) > 0;
  }

  public function deleteAll(array $keys): bool
  {
    if (empty($keys)) {
      return true;
    }
    $fullKeys = array_map(fn($k) => $this->prefix . $k, $keys);
    $deleted = $this->redis->del(...$fullKeys);
    return $deleted >= 0;
  }

  public function getTtl(): int
  {
    return $this->ttl;
  }

  public function keys(): array
  {
    if (Tus::production_safe()) {
      return $this->safeKeysInProduction();
    }

    return $this->unsafeButFastKeys();
  }

  public function setPrefix(string $prefix): self
  {
    $this->prefix = rtrim($prefix, ':') . ':';
    return $this;
  }

  public function getPrefix(): string
  {
    return $this->prefix;
  }

    // =============== Implementasi khusus ===============

  /**
   * ✅ Production-safe: gunakan SCAN (non-blocking).
   */
  protected function safeKeysInProduction(): array
  {
    $cursor = 0;
    $keys = [];
    $pattern = $this->prefix . '*';
    $count = Tus::redis_scan_count();

    do {
      $result = $this->redis->scan($cursor, 'MATCH', $pattern, 'COUNT', $count);
      if (! $result || ! is_array($result) || count($result) !== 2) {
        break;
      }

      [$cursor, $batch] = $result;

      foreach ($batch as $fullKey) {
        $keys[] = substr($fullKey, strlen($this->prefix));
      }
    } while ($cursor !== 0);

    return array_unique($keys); // SCAN bisa return duplikat
  }

  /**
   * ⚠️ Hanya untuk dev/debug — blocking dan berbahaya di production!
   */
  protected function unsafeButFastKeys(): array
  {
    // 🔒 Fail-safe: larang mutlak di production kecuali override eksplisit
    if (
      // app()->environment('production') &&
      App::environment('production') &&
      Tus::production_safe() !== false
    ) {
      throw new \LogicException(
        'TusRedis::unsafeButFastKeys() is disabled in production. ' .
          'Set TUS_PRODUCTION_SAFE=false in .env ONLY for debugging.'
      );
    }

    $pattern = $this->prefix . '*';
    $rawKeys = $this->redis->keys($pattern);

    return array_map(fn($k) => substr($k, strlen($this->prefix)), $rawKeys);
  }
}

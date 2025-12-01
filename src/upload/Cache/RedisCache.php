<?php

namespace Dochub\Upload\Cache;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class RedisCache {

  /**
   * Dapatkan keys dari file cache berdasarkan prefix
   */
  public static function keys(string $prefix): array
  {
    return self::safeKeysInProduction($prefix);
  }

    /**
   * ✅ Production-safe: gunakan SCAN (non-blocking).
   */
  private static function safeKeysInProduction($prefix): array
  {
    $cursor = 0;
    $keys = [];
    $pattern = $prefix . '*';
    $count = env('upload.redis.scan_count', 100);

    do {
      $result = Redis::scan($cursor, 'MATCH', $pattern, 'COUNT', $count);
      if (! $result || ! is_array($result) || count($result) !== 2) {
        break;
      }

      [$cursor, $batch] = $result;

      foreach ($batch as $fullKey) {
        $keys[] = substr($fullKey, strlen($prefix));
      }
    } while ($cursor !== 0);

    return array_unique($keys); // SCAN bisa return duplikat
  }

  //   /**
  //  * ⚠️ Hanya untuk dev/debug — blocking dan berbahaya di production!
  //  */
  // private static function unsafeButFastKeys(string $prefix): array
  // {
  //   // 🔒 Fail-safe: larang mutlak di production kecuali override eksplisit
  //   if (
  //     // app()->environment('production') &&
  //     App::environment('production') 
  //     // &&
  //     // Tus::production_safe() !== false
  //   ) {
  //     throw new \LogicException(
  //       'TusRedis::unsafeButFastKeys() is disabled in production. ' .
  //         'Set TUS_PRODUCTION_SAFE=false in .env ONLY for debugging.'
  //     );
  //   }

  //   $pattern = $prefix . '*';
  //   $rawKeys = $redis->keys($pattern);

  //   return array_map(fn($k) => substr($k, strlen($this->prefix)), $rawKeys);
  // }
}
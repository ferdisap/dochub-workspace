<?php

namespace Dochub\Upload\Cache;

use Illuminate\Support\Facades\Redis;
use Predis\Client;

class RedisCache {

  /**
   * Dapatkan keys dari file cache berdasarkan prefix
   */
  public static function keys(string $prefix, bool $safe = true): array
  {
    // $cache->getStore()->getKeys() coba pakai ini jika error
    return self::safeKeysInProduction($prefix);
  }

  public static function isAvailable(){
    $driver = config('cache.stores.redis.driver');
    if($driver === 'phpredis' || $driver === 'redis'){
      return class_exists(\Redis);
    } 
    else if($driver === 'predis'){
      return class_exists(Client::class);
    }
    return false;
  }

    /**
   * ✅ Production-safe: gunakan SCAN (non-blocking).
   */
  public static function safeKeysInProduction(string $prefix, int $batchSize = 0): array
  {
    $cursor = 0;
    $keys = [];
    $pattern = $prefix . '*';
    $count = $batchSize ? $batchSize : env('upload.redis.scan_count', 100);

    do {
      $result = Redis::scan($cursor, [
          'MATCH' => $pattern,
          'COUNT' => $count
        ]);
      // $result = Redis::scan($cursor, 'MATCH', $pattern, 'COUNT', $count);
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
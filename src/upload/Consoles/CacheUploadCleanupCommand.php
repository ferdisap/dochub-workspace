<?php

namespace App\Console\Commands;

use Dochub\Upload\Services\CacheCleanup;
use Dochub\Upload\Services\RedisCleanup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CacheUploadCleanupCommand extends Command
{
  protected $signature = 'cache:upload-cleanup 
                            {--driver= : Cache driver to use}
                            {--expired : Only cleanup expired uploads}
                            {--stats : Show cache statistics}
                            {--force : Force cleanup without confirmation}';

  protected $description = 'Cleanup upload cache entries';

  public function handle()
  {
    $driver = $this->option('driver') ?: config('cache.default');

    if ($this->option('stats')) {
      $stats = CacheCleanup::getStats($driver);
      $this->table(
        ['Metric', 'Value'],
        [
          ['Driver', $stats['driver']],
          ['Total Uploads', $stats['total']],
          ['Uploading', $stats['uploading']],
          ['Processing', $stats['processing']],
          ['Completed', $stats['completed']],
        ]
      );
      return 0;
    }

    if ($this->option('expired')) {
      $cleaned = CacheCleanup::cleanupExpired(100, $driver);
      $this->info("Cleaned up {$cleaned} expired cache entries (driver: {$driver})");
      return 0;
    }

    if (!$this->option('force') && !$this->confirm("Cleanup ALL completed uploads from cache (driver: {$driver})?")) {
      return 0;
    }

    // Cleanup manual
    $cache = Cache::driver($driver);
    $pattern = 'upload:*';

    // Untuk Redis
    // if ($driver === 'redis' && $cache->getStore() instanceof \Illuminate\Redis\Cache\RedisStore) {
    if ($driver === 'redis' && $cache->getStore()) {
      $cursor = 0;
      $cleaned = 0;

      do {
        [$cursor, $keys] = \Illuminate\Support\Facades\Redis::scan(
          $cursor,
          'MATCH',
          $pattern,
          'COUNT',
          100
        );

        foreach ($keys as $key) {
          $metadata = \Illuminate\Support\Facades\Redis::get($key);
          if ($metadata) {
            $data = json_decode($metadata, true);
            $id = str_replace('upload:', '', $key);
            if (RedisCleanup::cleanupIfCompleted($id, $data)) {
              $cleaned++;
            }
          }
        }
      } while ($cursor !== 0);

      // do {
      //   $result = $redis->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 100]);
      //   $cursor = $result[0];
      //   $keys = $result[1] ?? [];

      //   foreach ($keys as $key) {
      //     $metadata = $cache->get($key);
      //     if ($metadata) {
      //       $id = str_replace('upload:', '', $key);
      //       $data = is_string($metadata) ? json_decode($metadata, true) : $metadata;
      //       if ($data && CacheCleanup::cleanupIfCompleted($id, $data, $driver)) {
      //         $cleaned++;
      //       }
      //     }
      //   }
      // } while ($cursor != 0);

      $this->info("Cleaned up {$cleaned} cache entries (driver: {$driver})");
    }
    // Untuk driver lain
    else {
      $cleaned = CacheCleanup::cleanupExpired(1000, $driver);
      $this->info("Cleaned up {$cleaned} cache entries (driver: {$driver})");
    }

    return 0;
  }
}

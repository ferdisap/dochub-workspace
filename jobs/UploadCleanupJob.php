<?php

namespace Dochub\Job;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class UploadCleanupJob implements ShouldQueue
{
  use Dispatchable, InteractsWithQueue, Queueable;

  public function handle()
  {
    // Cleanup Tus uploads
    $this->cleanupTusUploads();

    // Cleanup Native uploads
    $this->cleanupNativeUploads();

    Log::info("Upload cleanup completed");
  }

  private function cleanupTusUploads()
  {
    if (!class_exists(\TusPhp\Tus\Server::class)) return;

    $server = new \TusPhp\Tus\Server();
    $server->setUploadDir(config('upload.driver.tus.root'));

    if (config('upload.driver.tus.use_redis')) {
      $server->setCache($this->getRedisClient());
    }

    // Gunakan method bawaan Tus
    $server->handleExpiration();
  }

  private function cleanupNativeUploads()
  {
    $pattern = 'upload:native_*';
    $cursor = 0;
    $deleted = 0;

    do {
      [$cursor, $keys] = Redis::scan($cursor, 'MATCH', $pattern, 'COUNT', config('upload.cleanup.batch_size'));

      foreach ($keys as $key) {
        $metadata = Redis::get($key);
        if (!$metadata) continue;

        $data = json_decode($metadata, true);
        if (isset($data['expires_at']) && $data['expires_at'] < time()) {
          @unlink($data['path']);
          Redis::del($key);
          $deleted++;
        }
      }
    } while ($cursor !== 0);

    if ($deleted > 0) {
      Log::info("Cleaned up {$deleted} native uploads");
    }
  }

  private function getRedisClient()
  {
    $config = config('upload.redis');
    return new \Predis\Client([
      'host' => $config['host'],
      'port' => $config['port'],
      'password' => $config['password'],
      'database' => $config['database'],
    ]);
  }
}

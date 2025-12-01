<?php

namespace Dochub\Job;

use Dochub\Upload\Cache\NativeCache;
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
    // // Cleanup Tus uploads
    // $this->cleanupTusUploads();

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
      $server->setCache(new NativeCache());
    }

    // Gunakan method bawaan Tus
    $server->handleExpiration();
  }

  private function cleanupNativeUploads()
  {
    $this->cleanupNativeUploadCache();
  }
  private function cleanupNativeUploadCache()
  {
    $cache = new NativeCache();
    $deleted = $cache->cleanupExpired();
    // $keys = $cache->keys();
    // $delete = $cache->deleteAll($keys);
    // $deleted = $delete ? count($keys) : 0;

    Log::info("Cleaned up {$deleted} native uploads");
  }
}

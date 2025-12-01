<?php

namespace App\Console\Commands;

use Dochub\Upload\Services\RedisCleanup;
use Illuminate\Console\Command;

class UploadCleanupCommand extends Command
{
  protected $signature = 'upload:cleanup 
                            {--expired : Only cleanup expired uploads}
                            {--force : Force cleanup without confirmation}';

  protected $description = 'Cleanup uploaded files and Redis metadata';

  public function handle()
  {
    if ($this->option('expired')) {
      $cleaned = RedisCleanup::cleanupExpired();
      $this->info("Cleaned up {$cleaned} expired uploads");
      return;
    }

    if (!$this->option('force') && !$this->confirm('This will cleanup ALL completed uploads. Continue?')) {
      return;
    }

    // Cleanup semua yang selesai
    $pattern = 'upload:native_*';
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

    $this->info("Cleaned up {$cleaned} uploads");
  }
}

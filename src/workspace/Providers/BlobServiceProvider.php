<?php

namespace Dochub\Workspace\Providers;

use Dochub\Workspace\Blob;
use Dochub\Workspace\Consoles\BlobBenchmarkCommand;
use Dochub\Workspace\Consoles\BlobGcCommand;
use Dochub\Workspace\Consoles\BlobStatsCommand;
use Dochub\Workspace\Services\BlobLocalStorage;
use Dochub\Workspace\Services\FlockLockManager;
use Dochub\Workspace\Services\NullLockManager;
use Dochub\Workspace\Services\RedisLockManager;
use Dochub\Workspace\Workspace;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class BlobServiceProvider extends ServiceProvider
{
  public function boot()
  {
    // ✅ Load config — tanpa foundation
    $this->mergeConfigFrom(__DIR__ . '/../../../config/blob.php', 'blob');
    
    // ✅ Commands — aman
    if ($this->app->runningInConsole()) {
      $this->commands([
        // BlobBenchmarkCommand::class, 
        BlobGcCommand::class,
        BlobStatsCommand::class
      ]);
    }

    // Pastikan folder blobs ada
    @mkdir(Blob::path(), 0755, true);

    // 🔔 Publish opsional — hanya di console
    // if ($this->app->runningInConsole()) {
    //   $this->publishes([
    //     __DIR__ . '/../../../config/blob.php' => $this->app->configPath('blob.php'),
    //   ], 'blob-config');
    // }
  }

  public function register()
  {
    // bind BlobLocalStorage
    $this->app->bind(BlobLocalStorage::class, function ($app) {
      return match (Config::get('lock.driver')) {
        // 'redis' => new RedisLockManager(),
        'redis' => new BlobLocalStorage(new RedisLockManager()),
        // 'flock' => new FlockLockManager(),
        'flock' => new BlobLocalStorage(new FlockLockManager()),
        // 'null' => new NullLockManager(),
        'null' => new BlobLocalStorage(new NullLockManager()),
        default => new BlobLocalStorage(new FlockLockManager()), // fallback aman
      };
    });
  }

  // public function register()
  // {
  //   // Pastikan folder locks ada
  //   @mkdir(Workspace::blobPath(), 0755, true);
  //   // Bind ke container tanpa app()
  //   // $this->app->singleton('blob.manager', function ($app) {
  //   //     return new BlobManager($app['config']['blob'] ?? []);
  //   // });
  // }
}

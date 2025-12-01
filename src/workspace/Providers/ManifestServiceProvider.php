<?php

namespace Dochub\Workspace\Providers;

use Dochub\Workspace\Blob;
use Dochub\Workspace\Consoles\BlobBenchmarkCommand;
use Dochub\Workspace\Consoles\BlobGcCommand;
use Dochub\Workspace\Consoles\BlobStatsCommand;
use Dochub\Workspace\Manifest;
use Dochub\Workspace\Services\BlobLocalStorage;
use Dochub\Workspace\Services\FlockLockManager;
use Dochub\Workspace\Services\ManifestLocalStorage;
use Dochub\Workspace\Services\NullLockManager;
use Dochub\Workspace\Services\RedisLockManager;
use Dochub\Workspace\Workspace;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class ManifestServiceProvider extends ServiceProvider
{
  public function boot()
  {
    // Pastikan folder blobs ada
    @mkdir(Blob::path(), 0755, true);

    // bind BlobLocalStorage
    $this->app->bind(ManifestLocalStorage::class, function ($app) {
      return new ManifestLocalStorage();
    });

    // 🔔 Publish opsional — hanya di console
    // if ($this->app->runningInConsole()) {
    //   $this->publishes([
    //     __DIR__ . '/../../config/blob.php' => $this->app->configPath('blob.php'),
    //   ], 'blob-config');
    // }
  }

  public function register()
  {
  }
}

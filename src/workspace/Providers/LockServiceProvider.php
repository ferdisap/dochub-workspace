<?php

namespace Dochub\Workspace\Providers;

use Dochub\Workspace\Lock;
use Dochub\Workspace\Services\FlockLockManager;
use Dochub\Workspace\Services\LockManager;
use Dochub\Workspace\Services\NullLockManager;
use Dochub\Workspace\Services\RedisLockManager;
use Dochub\Workspace\Workspace;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class LockServiceProvider extends ServiceProvider
{
  public function register()
  {
    // belum dipakai kecuali didalam BlobLocalStorage. Lihat BlobServiceProvider
    // $this->app->bind(LockManager::class, function ($app) {
    //   return match (Config::get('lock.driver')) {
    //     'redis' => new RedisLockManager(),
    //     'flock' => new FlockLockManager(),
    //     'null' => new NullLockManager(),
    //     default => new FlockLockManager(), // fallback aman
    //   };
    // });
  }

  public function boot()
  {
    // Pastikan folder locks ada
    if (Lock::driver() === 'flock') {
      @mkdir(Lock::path(), 0755, true);
    }
  }
}

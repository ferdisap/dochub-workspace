<?php

namespace Dochub\Workspace\Providers;

use Dochub\Workspace\Consoles\WorkspaceCleanupRollbackCommand;
use Dochub\Workspace\Consoles\WorkspaceCompareCommand;
use Dochub\Workspace\Consoles\WorkspaceRollbackCommand;
use Dochub\Workspace\Workspace;
use Illuminate\Support\ServiceProvider;
use Illuminate\Filesystem\Filesystem;

class WorkspaceServiceProvider extends ServiceProvider
{
  // Fungsi boot() dipanggil setelah semua Service Provider lainnya selesai didaftarkan dan di-booting.
  public function boot()
  {
    // ✅ Load config — tanpa foundation
    $this->mergeConfigFrom(__DIR__ . '/../../../config/workspace.php', 'workspace');
    $this->mergeConfigFrom(__DIR__ . '/../../../config/token.php', 'dochub.token');

    // ✅ Auto-load migrations — tanpa foundation
    $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');

    // ✅ Routes — tanpa foundation
    $this->loadRoutesFrom(__DIR__ . '/../../../routes/encryption.php');
    $this->loadRoutesFrom(__DIR__ . '/../../../routes/file.php');
    $this->loadRoutesFrom(__DIR__ . '/../../../routes/manifest.php');
    $this->loadRoutesFrom(__DIR__ . '/../../../routes/upload.php');
    $this->loadRoutesFrom(__DIR__ . '/../../../routes/workspace.php');
    $this->loadRoutesFrom(__DIR__ . '/../../../routes/token.php');

    // ✅ View >>> php artisan vendor:publish --tag=dochub-views
    // 1. Daftarkan Views
    $this->loadViewsFrom(__DIR__ . '/../../../view/src', 'dochub-views');

    // 2. Tentukan apa yang bisa di-publish
    $this->publishes([
      // A. Views untuk di-publish
      __DIR__ . '/../../../view/src' => resource_path('views/vendor/dochub'),
    ], 'dochub-views'); // 'views' adalah TAG publikasi

    // ✅ Asset >>> php artisan vendor:publish --tag=workspace-assets
    // B. Assets untuk di-publish
    $this->publishes([
      __DIR__ . '/../../../view/dist' => public_path('vendor/dochub'),
    ], 'dochub-assets'); // 'assets' adalah TAG publikasi



    // check and create path
    @mkdir(Workspace::path(), 0755, true);

    // 🔑 Ini yang membuat config('blob.xxx') bisa dipakai
    // $this->mergeConfigFrom(__DIR__ . '/../../../config/blob.php', 'blob');

    // Di ServiceProvider::boot()
    // $this->publishes([
    //     __DIR__ . '/../../../config/blob.php' => config_path('blob.php'),
    // ], 'mypackage-config');

    // Publish config
    // $this->publishes([
    //     __DIR__ . '/config/mypackage.php' => config_path('mypackage.php'),
    // ], 'mypackage-config');

    // // Publish migrations
    // $this->publishes([
    //     __DIR__ . '/database/migrations' => database_path('migrations'),
    // ], 'mypackage-migrations');

    // // Load config
    // // agar config tetap bisa di-override user.
    // $this->mergeConfigFrom(__DIR__ . '/config/mypackage.php', 'mypackage');

    // // Load migrations (opsional: auto-load tanpa publish)
    // // hanya untuk development/testing atau internal
    // if (!class_exists('CreateMypackageTable')) {
    //     $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    // }

    // // Load routes
    // $this->loadRoutesFrom(__DIR__ . '/routes/web.php');

    // // Load commands
    if ($this->app->runningInConsole()) {
      $this->commands([
        WorkspaceCleanupRollbackCommand::class,
        WorkspaceCompareCommand::class,
        WorkspaceRollbackCommand::class,
      ]);
    }
  }

  // Fungsi register() dipanggil sangat awal dalam siklus hidup aplikasi Laravel.
  public function register()
  {
    // Opsional: bind services di sini
  }
}

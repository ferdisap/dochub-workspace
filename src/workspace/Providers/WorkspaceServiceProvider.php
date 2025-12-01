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

    // ✅ Auto-load migrations — tanpa foundation
    $this->loadMigrationsFrom(__DIR__ . '/../../../database/migrations');

    // ✅ Routes — tanpa foundation
    $this->loadRoutesFrom(__DIR__ . '/../../../routes/web.php');
    $this->loadRoutesFrom(__DIR__ . '/../../../routes/api.php');

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

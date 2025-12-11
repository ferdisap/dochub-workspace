<?php

use Dochub\Controller\WorkspaceCleanupController;
use Dochub\Controller\WorkspaceController;
use Dochub\Controller\WorkspaceRollbackController;
use Illuminate\Support\Facades\Route;

Route::prefix('dochub')->middleware([
  'web',
  'throttle:100,1' // 100 chunk/menit
])->group(function () {
  // CREATE Eksekusi new
  Route::get('/workspace/blank', [WorkspaceController::class, 'blank'])->middleware('auth')->name('workspace.blank');
  Route::get('/workspace/clone/{workspace:name}', [WorkspaceController::class, 'clone'])->middleware('auth')->name('workspace.clone');
  // ROLLBACK Eksekusi rollback
  Route::get('/workspace/rollback', [WorkspaceRollbackController::class, 'rollback'])->middleware('auth')->name('workspace.rollback');
  // CLEANUP Preview (GET) cleanupand and Eksekusi(POST) cleanup
  Route::get('/workspace/cleanup-rollback', [WorkspaceCleanupController::class, 'preview'])->middleware('auth')->name('workspace.preview');
  Route::post('/workspace/cleanup-rollback', [WorkspaceCleanupController::class, 'execute'])->middleware('auth')->name('workspace.cleanup');
});

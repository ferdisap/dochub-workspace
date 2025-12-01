<?php

use Dochub\Controller\WorkspaceCleanupController;
use Dochub\Controller\WorkspaceController;
use Dochub\Controller\WorkspaceRollbackController;
use Illuminate\Support\Facades\Route;

// CREATE Eksekusi new
Route::get('/dochub/workspace/blank', [WorkspaceController::class, 'blank'])->middleware('auth')->name('workspace.blank');
Route::get('/dochub/workspace/clone/{workspace:name}', [WorkspaceController::class, 'clone'])->middleware('auth')->name('workspace.clone');
// ROLLBACK Eksekusi rollback
Route::get('/dochub/workspace/rollback', [WorkspaceRollbackController::class, 'rollback'])->middleware('auth')->name('workspace.rollback');
// CLEANUP Preview (GET) cleanupand and Eksekusi(POST) cleanup
Route::get('/dochub/workspace/cleanup-rollback', [WorkspaceCleanupController::class, 'preview'])->middleware('auth')->name('workspace.preview');
Route::post('/dochub/workspace/cleanup-rollback', [WorkspaceCleanupController::class, 'execute'])->middleware('auth')->name('workspace.cleanup');

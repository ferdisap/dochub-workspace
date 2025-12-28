<?php

use Dochub\Controller\WorkspaceCleanupController;
use Dochub\Controller\WorkspaceController;
use Dochub\Controller\WorkspacePushController;
use Dochub\Controller\WorkspaceRollbackController;
use Illuminate\Support\Facades\Route;

Route::prefix('dochub')->middleware([
  'web', // diubah jika authentication pakai web
  'throttle:100,1' // 100 chunk/menit
])->group(function () {
  /**
   * ---------
   * READ 
   * ---------
   */
  Route::get('/workspace/detail/{workspace:name}', [WorkspaceController::class, 'detail'])->middleware('auth')->name('workspace.detail');
  Route::get('/workspace/tree/{name}', [WorkspaceController::class, 'tree'])->middleware('auth')->name('workspace.tree');
  Route::get('/workspace/search', [WorkspaceController::class, 'search'])->middleware('auth')->name('workspace.search');
  
  /**
   * ---------
   * CREATE Eksekusi new 
   * ---------
   */
  Route::get('/workspace/blank', [WorkspaceController::class, 'blank'])->middleware('auth')->name('workspace.blank');
  Route::get('/workspace/clone/{workspace:name}', [WorkspaceController::class, 'clone'])->middleware('auth')->name('workspace.clone');
  /**
   * ---------
   * ROLLBACK Eksekusi rollback 
   * ---------
   */
  Route::get('/workspace/rollback/{workspace}', [WorkspaceRollbackController::class, 'rollback'])->middleware('auth')->name('workspace.rollback');
  /**
   * ---------
   * CLEANUP Preview (GET) cleanupand and Eksekusi(POST) cleanup
   * ---------
   */
  // Route::get('/workspace/cleanup-rollback', [WorkspaceCleanupController::class, 'preview'])->middleware('auth')->name('workspace.preview');
  // Route::post('/workspace/cleanup-rollback', [WorkspaceCleanupController::class, 'execute'])->middleware('auth')->name('workspace.cleanup');

  /**
   * ---------
   * ANALYZE 
   * ---------
   */
  Route::get('/workspace/analyze', [WorkspaceController::class, 'analyzeView'])->middleware('auth')->name('workspace.analyze');

  /**
   * ---------
   * PUSH 
   * ---------
   * setiap post method should fetch by 'content-type' : 'application/json' headers
   * 1. client request init push WorkspacePushController@init
   * 2. client request upload config UploadController@getConfig
   * 3. client request check chunk WorkspacePushController@checkChunk
   * 4. client request upload chunk UploadController@uploadChunk
   * 5. client request process upload WorkspacePushController@processUpload
   * 6. client request status upload UploadController@statusUpload
   * 7. client request process push WorkspacePushController@processPush
   * 8. client request status push WorkspacePushController@statusPush
   */
  Route::post('/workspace/push/init', [WorkspacePushController::class, 'init'])->middleware('auth')->name('dochub.workspace.push.init');
  // route sama seperti di upload.php, yaitu Route::get('/upload/config', [UploadController::class, 'getConfig'])->middleware('auth')->name('dochub.upload.config');
  Route::post('/workspace/chunk/check', [WorkspacePushController::class, 'checkChunk'])->middleware('auth')->name('dochub.workspace.check.chunk');
  // route sama seperti di upload.php, yaitu Route::post('/upload/chunk', [UploadNativeController::class, 'uploadChunk'])->middleware('auth')->name('dochub.upload.chunk');
  Route::post('/workspace/chunk/process', [WorkspacePushController::class, 'processUpload'])->middleware('auth')->name('dochub.workspace.upload.process');
  // route sama seperti di upload.php, yaitu Route::get('/upload/{id}/status', [UploadNativeController::class, 'statusUpload'])->middleware('auth')->name('dochub.upload.status');
  Route::post('/workspace/push/process', [WorkspacePushController::class, 'processPush'])->middleware('auth')->name('dochub.workspace.push.process');
  Route::get('/workspace/push/status/{id}', [WorkspacePushController::class, 'statusPush'])->middleware('auth')->name('dochub.upload.status');  
});

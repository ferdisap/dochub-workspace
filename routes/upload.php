<?php

use Dochub\Controller\EncryptFileController;
use Dochub\Controller\UploadController;
use Dochub\Controller\UploadNativeController;
use Illuminate\Support\Facades\Route;


Route::prefix('dochub')->middleware([
  'web',
  'throttle:100,1' // 100 chunk/menit
])->group(function () {
  // view start upload
  Route::get('/upload', [UploadController::class, 'formView'])->middleware('auth');
  // list uploaded
  Route::get('/upload/list', [UploadController::class, 'listView'])->middleware('auth');
  Route::post('/upload/list', [UploadController::class, 'list'])->middleware('auth');
  // config before upload, check and put chunk, process
  Route::get('/upload/config', [UploadController::class, 'getConfig'])->middleware('auth')->name('dochub.upload.config');
  Route::post('/upload/chunk/check', [UploadNativeController::class, 'checkChunk'])->middleware('auth')->name('dochub.upload.check');
  Route::post('/upload/chunk', [UploadNativeController::class, 'uploadChunk'])->middleware('auth')->name('dochub.upload.chunk.check');
  Route::post('/upload/process', [UploadNativeController::class, 'processUpload'])->middleware('auth')->name('dochub.upload.process');
  // polling status chunk and delete
  Route::get('/upload/{id}/status', [UploadNativeController::class, 'getUploadStatus'])->middleware('auth')->name('dochub.upload.status');
  Route::delete('/upload/{id}/delete', [UploadNativeController::class, 'deleteUpload'])->middleware('auth')->name('dochub.upload.delete');
  // cast to workspace
  Route::post('/upload/make/workspace', [UploadController::class, 'makeWorkspace'])->middleware('auth')->name('dochub.upload.make.workspace');

  // Route::get('/tes/chunk/{uploadId}/{chunkId}', [UploadNativeController::class, 'tesCheckChunk']);

  // Route::get("/tes", [UploadNativeController::class, 'tesJob']);

  // Manual cleanup
  // Route::post('/upload/cleanup', function () {
  //   dispatch(new UploadCleanupJob());
  //   return response()->json(['status' => 'cleanup scheduled']);
  // })->middleware('auth');
});



// Tus routes (already exists)
// Route::post('/', [UploadController::class, 'createUpload']);
// Route::head('/{id}', [UploadController::class, 'headUpload']);
// Route::patch('/{id}', [UploadController::class, 'patchUpload']);

// // 🔑 Native routes baru
// Route::get('/{id}/status', [UploadController::class, 'getUploadStatus']); // GET /upload/abc123/status
// Route::delete('/{id}', [UploadController::class, 'deleteUpload']);        // DELETE /upload/abc123

// use App\Http\Controllers\Api\UploadController;

// Route::prefix('upload')->group(function () {
//     // Tus Protocol Routes (WAJIB)
//     Route::post('/', [UploadController::class, 'createUpload']);    // POST /upload
//     Route::head('/{id}', [UploadController::class, 'headUpload']);  // HEAD /upload/abc123
//     Route::patch('/{id}', [UploadController::class, 'patchUpload']); // PATCH /upload/abc123
    
//     // Additional routes
//     Route::get('/config', [UploadController::class, 'getConfig']);
//     Route::post('/cleanup', [UploadController::class, 'cleanup']);
// });

// CARA PAKAI
// Native Upload (Shared Hosting)
// # Dapatkan konfigurasi
// curl http://localhost/api/upload/config

// # Upload langsung
// curl -X POST http://localhost/api/upload \
//   -H "Content-Type: application/zip" \
//   --data-binary @large-file.zip

// Tus Upload (Production)
// # Dapatkan konfigurasi
// curl http://localhost/api/upload/config

// # Upload dengan Tus client
// npx tus-upload large-file.zip http://localhost/api/upload \
//   --chunk-size 10485760 \
//   --header "Upload-Metadata: filename dGVzdC56aXA="

// Force Driver via Query Param
// # Paksa native meski di production
// curl -X POST "http://localhost/api/upload?driver=native" --data-binary @file.zip
// # Paksa tus meski di shared hosting
// curl -X POST "http://localhost/api/upload?driver=tus" --data-binary @file.zip







// ✅ Keuntungan Tus:
// use TusPhp\Tus\Server;

// // Endpoint universal
// Route::post('/upload', [UploadController::class, 'handleUpload']);
// Route::head('/upload/{id}', [UploadController::class, 'handleUpload']);
// Route::patch('/upload/{id}', [UploadController::class, 'handleUpload']);

// // Callback setelah upload selesai
// Route::post('/upload-complete', [UploadController::class, 'uploadComplete']);

// File ditulis langsung ke disk (bukan memory)
// Chunk digabung oleh server (bukan PHP)
// Built-in resume & checksum

// Route::post('/upload', function (Request $request) {
//     $server = new Server(storage_path('tus'));
//     return $server->serve();
// })->middleware('throttle:3,1') // 3 upload/jam
// ->name('tus.upload');

// Route::patch('/upload/{id}', function (Request $request, $id) {
//     $server = new Server(storage_path('tus'));
//     return $server->serve();
// })
// ->middleware('throttle:3,1') // 3 upload/jam
// ->name('tus.upload.patch');

// public function processUpload(Request $request)
// {
//     $tus = new \TusPhp\Tus\Client('http://localhost:8000');
//     $info = $tus->file($request->input('upload_id'))->getFileInfo();

//     $zipPath = $info['path']; // Lokasi file utuh di disk

//     // Validasi sebelum proses
//     $this->validateZipSafety($zipPath);

//     // Proses async
//     ZipProcessJob::dispatch($zipPath, $request->user()->id);
    
//     return response()->json(['status' => 'processing']);
// }

// Redis::hset("upload:{$jobId}", [
//     'status' => 'extracting',
//     'progress' => '42/1248 files',
//     'eta' => '2m 15s'
// ]);

// # Buat file 1 GB
// dd if=/dev/zero of=large.bin bs=1M count=1000

// # Kompres jadi ZIP
// zip test-1gb.zip large.bin
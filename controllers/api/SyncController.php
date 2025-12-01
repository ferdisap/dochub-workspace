<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DochubMerge;
use App\Models\DochubMergeSession;
use App\Models\DochubWorkspace;
use App\Models\DochubFile;
use App\Services\BlobLocalStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// use App\Http\Controllers\Api\SyncController;
// php artisan make:controller Api/SyncController

// Route::post('/sync/{workspaceId}', [SyncController::class, 'syncManifest']);
// Route::post('/rollback/{workspaceId}', [SyncController::class, 'rollback']);

// curl -X POST http://localhost:8000/api/sync/1 \
//   -H "Content-Type: application/json" \
//   -d '{
//     "source": "thirdparty:client-abc",
//     "label": "v1.2.0",
//     "files": [
//       {
//         "path": "config/app.php",
//         "size": 2048,
//         "sha256": "a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2",
//         "mime": "text/php"
//       }
//     ]
//   }'
class SyncController extends Controller
{
  public function __construct(
    protected BlobLocalStorage $blobLocalStorage
  ) {}

  /**
   * Endpoint: POST /api/sync/{workspaceId}
   * Third-party kirim manifest JSON
   */
  public function syncManifest(Request $request, int $workspaceId)
  {
    $workspace = DochubWorkspace::findOrFail($workspaceId);

    // Validasi manifest
    $request->validate([
      'source' => 'required|string',
      'files' => 'required|array',
      'files.*.path' => 'required|string',
      'files.*.size' => 'required|integer|min:0',
      'files.*.sha256' => 'required|regex:/^[a-f0-9]{64}$/i',
      'files.*.mime' => 'nullable|string',
    ]);

    // Mulai session
    $session = DochubMergeSession::create([
      'target_workspace_id' => $workspace->id,
      'source_identifier' => $request->input('source'),
      'source_type' => 'remote',
      'started_at' => now(),
      'status' => 'scanning',
      'initiated_by_user_id' => auth()->id() ?? 1,
      'metadata' => $request->all(),
    ]);

    try {
      // Dapatkan merge terakhir untuk workspace ini
      $lastMerge = DochubMerge::where('workspace_id', $workspace->id)
        ->orderBy('merged_at', 'desc')
        ->first();

      // Buat merge baru
      $newMerge = DochubMerge::create([
        'id' => Str::uuid(),
        'prev_merge_id' => $lastMerge?->id,
        'workspace_id' => $workspace->id,
        'label' => $request->input('label'),
        'message' => $request->input('message'),
        'merged_at' => now(),
      ]);

      // Proses tiap file
      $changes = [];
      $tempDir = sys_get_temp_dir();

      foreach ($request->input('files') as $fileData) {
        // Download file dari third-party (simulasi: file sudah di-upload ke temp)
        // Di produksi: gunakan HTTP client (Guzzle) atau queue
        $tempPath = $tempDir . '/' . Str::random(16) . '-' . basename($fileData['path']);
        // ⚠️ Ini simulasi — ganti dengan download dari URL
        file_put_contents($tempPath, str_repeat('X', $fileData['size']));

        try {
          // Simpan ke blob storage
          $hash = $this->blobLocalStorage->store(
            $tempPath,
            $fileData['sha256'],
            ['mime' => $fileData['mime'] ?? 'application/octet-stream']
          );

          // Tentukan aksi
          $action = 'added';
          $oldHash = null;

          if ($lastMerge) {
            $oldFile = DochubFile::where([
              'merge_id' => $lastMerge->id,
              'relative_path' => $fileData['path']
            ])->first();

            if ($oldFile) {
              $action = $oldFile->blob_hash === $hash ? 'unchanged' : 'updated';
              $oldHash = $oldFile->blob_hash;
            }
          }

          if ($action !== 'unchanged') {
            DochubFile::create([
              'merge_id' => $newMerge->id,
              'workspace_id' => $workspace->id,
              'relative_path' => $fileData['path'],
              'blob_hash' => $hash,
              'old_blob_hash' => $oldHash,
              'action' => $action,
              'size_bytes' => $fileData['size'],
              'file_modified_at' => now(),
            ]);

            $changes[] = [
              'path' => $fileData['path'],
              'action' => $action,
              'size' => $fileData['size'],
            ];
          }
        } finally {
          @unlink($tempPath);
        }
      }

      // Update session
      $session->update([
        'result_merge_id' => $newMerge->id,
        'completed_at' => now(),
        'status' => 'applied',
        'metadata' => array_merge($session->metadata ?? [], [
          'files_processed' => count($request->input('files')),
          'changes' => $changes,
        ]),
      ]);

      return response()->json([
        'success' => true,
        'merge_id' => $newMerge->id,
        'changes' => $changes,
      ]);
    } catch (\Exception $e) {
      $session->update(['status' => 'failed']);
      throw $e;
    }
  }

  // Di controller sync
  // public function syncManifest(Request $request)
  // {
  //   $manifestData = $request->all();

  //   // Simpan konten lengkap ke disk
  //   $storagePath = app(ManifestLocalStorage::class)->store($manifestData);

  //   // Simpan metadata ke DB
  //   $manifest = DochubManifest::create([
  //     'repository_id' => $request->repository_id,
  //     'from_id' => auth()->id(),
  //     'source' => $manifestData['source'],
  //     'version' => $manifestData['version'],
  //     'total_files' => $manifestData['total_files'],
  //     'total_size_bytes' => $manifestData['total_size_bytes'],
  //     'hash_tree_sha256' => $manifestData['hash_tree_sha256'],
  //     'storage_path' => $storagePath,
  //   ]);

  //   // Lanjutkan ke proses merge...
  // }

  // /**
  //  * Rollback ke merge tertentu
  //  */
  // public function rollback(Request $request, int $workspaceId)
  // {
  //   $request->validate(['merge_id' => 'required|uuid']);

  //   $workspace = DochubWorkspace::findOrFail($workspaceId);
  //   $targetMerge = DochubMerge::where('workspace_id', $workspace->id)
  //     ->where('id', $request->merge_id)
  //     ->firstOrFail();

  //   // Ambil semua file di merge target
  //   $files = DochubFile::where('merge_id', $targetMerge->id)->get();

  //   // Rekonstruksi workspace (simulasi: hanya log)
  //   foreach ($files as $file) {
  //     $blobPath = $this->blobLocalStorage->getBlobPath($file->blob_hash);
  //     $workspacePath = storage_path("app/workspaces/{$workspace->id}/{$file->relative_path}");

  //     // Pastikan folder ada
  //     @mkdir(dirname($workspacePath), 0755, true);

  //     // Simpan file (hardlink lebih efisien, tapi copy untuk kompatibilitas)
  //     if ($file->action !== 'deleted') {
  //       copy($blobPath, $workspacePath);
  //     } else {
  //       @unlink($workspacePath);
  //     }
  //   }

  //   return response()->json([
  //     'success' => true,
  //     'message' => "Workspace #{$workspaceId} di-rollback ke merge {$targetMerge->id}",
  //     'files_restored' => $files->count(),
  //   ]);
  // }

  // Di controller sync
  // public function syncManifest(Request $request, int $workspaceId)
  // {
  //   $validated = $request->validate([
  //     'source' => 'required|string',
  //     'version' => 'required|string', // Akan dinormalisasi ke ISO 8601
  //     'files' => 'required|array',
  //   ]);

  //   // Simpan manifest
  //   $manifest = Manifest::create([
  //     'source' => $validated['source'],
  //     'version' => $validated['version'], // ✅ Auto-normalisasi ke UTC
  //     'from_id' => auth()->id(),
  //     'total_files' => count($validated['files']),
  //     'total_size_bytes' => collect($validated['files'])->sum('size'),
  //     'storage_path' => $this->saveManifestToDisk($validated),
  //   ]);

  //   // Cek apakah ini update atau duplikat
  //   $existing = Manifest::where('source', $manifest->source)
  //     ->where('version', $manifest->version)
  //     ->exists();

  //   if ($existing) {
  //     return response()->json([
  //       'warning' => 'Manifest already processed',
  //       'manifest_id' => $manifest->id,
  //     ], 200);
  //   }

  //   // Lanjutkan ke merge...
  // }
}

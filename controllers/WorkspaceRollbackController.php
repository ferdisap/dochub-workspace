<?php

namespace Dochub\Controller;

use Dochub\Workspace\Models\File;
use Dochub\Workspace\Models\Merge;
use Dochub\Workspace\Models\MergeSession;
use Dochub\Workspace\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule; // Import the Rule facade

use function Illuminate\Support\now;

// storage/
// ├── workspaces/
// │   └── {workspace-id}/       # versi live (symlink/hardlink ke blobs)
// │
// ├── blobs/                    # file unik, dinamai SHA256
// │   ├── abc123...             # config/app.php versi lama
// │   └── def456...             # config/app.php versi baru
// │
// ├── backups/
// │   └── merge-42.tar.gz      # hanya berisi file yang berubah di merge #42
// │                             # (opsional — bisa dihilangkan jika pakai blobs)
// │
// └── manifests/
//     └── merge-42-manifest.json  # simpan manifest asli dari third-party (audit)

// ALUR ROLLBACK merge_id = 42 Qwen_mermaid
// graph LR
//     A[User: rollback ke merge_id=42] --> B[Ambil record merge_sessions#42]
//     B --> C[Ambil semua merge_files WHERE merge_session_id=42]
//     C --> D{Untuk tiap file}
//     D -->|action=updated| E[Restore old_hash dari blobs/ atau dari backup archive]
//     D -->|action=deleted| F[Ekstrak file dari merge-42.tar.gz → tempatkan di workspace]
//     D -->|action=added| G[Hapus file dari workspace]
//     E --> H[Update workspace_files]
//     F --> H
//     G --> H
//     H --> I[Selesai — workspace kembali ke state sebelum merge #42]
// Jika kamu pakai blobs/{hash} untuk deduplikasi, kamu bahkan tidak perlu ekstrak archive — cukup baca old_hash → ambil dari blobs/old_hash.

class WorkspaceRollbackController
{
  public function rollback(Request $request, int $workspaceId)
  {
    $workspace = Workspace::findOrFail($workspaceId);
    $mergeId = $request->input('merge_id'); // sama seperti request->get(), tapi input lebih canggih karena bisa nested eg: "merge.id"

    $newWorkspace = $this->duplicateFromMerge($workspace, $mergeId);

    return Response::make([
      'success' => true,
      'new_workspace' => [
        'id' => $newWorkspace->id,
        'name' => $newWorkspace->name,
        'created_at' => $newWorkspace->created_at,
      ],
    ],200, [
      "content-type" => "application/json"
    ])->json();
  }

  /** mirip dengan WorkspaceRollbackCommand@createRollbackWorkspace */
  private function duplicateFromMerge(Workspace $original, string $mergeId, string | null $newName = null): Workspace
  {
    // Validasi merge milik workspace ini
    $merge = Merge::where('id', $mergeId)
      ->where('workspace_id', $original->id)
      ->firstOrFail();

    // Buat workspace baru
    $newWorkspace = $original->replicate();
    $newWorkspace->name = $newName ?? "{$original->name}-rollback-" . now()->format('Ymd');
    $newWorkspace->save();

    // Salin semua file dari merge target
    $files = $merge->files;

    foreach ($files as $file) {
      File::create([
        'merge_id' => $mergeId, // atau buat merge baru?
        'workspace_id' => $newWorkspace->id,
        'relative_path' => $file->relative_path,
        'blob_hash' => $file->blob_hash,
        'old_blob_hash' => null,
        'action' => 'copied',
        'size_bytes' => $file->size_bytes,
        'file_modified_at' => $file->file_modified_at,
      ]);
    }

    // Opsional: buat merge session untuk audit
    MergeSession::create([
      'target_workspace_id' => $newWorkspace->id,
      'source_identifier' => "rollback:{$original->id}:{$mergeId}",
      'source_type' => 'rollback',
      'status' => 'applied',
      'started_at' => now(),
      'completed_at' => now(),
      'initiated_by_user_id' => Auth::user()->id ?? 1,
      'metadata' => [
        'original_workspace_id' => $original->id,
        'source_merge_id' => $mergeId,
        // jika rollback
        'rollback_method' => 'duplicate',
        'files_copied' => $files->count(),
      ],
    ]);

    return $newWorkspace;
  }
}

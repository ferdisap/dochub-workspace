<?php

namespace Dochub\Controller;

use Dochub\Workspace\Models\File;
use Dochub\Workspace\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule; // Import the Rule facade

// # contoh Preview (GET)
// curl -X GET "http://localhost:8000/api/workspace/cleanup-rollback?days=14"
// response:
// {
//   "success": true,
//   "data": {
//     "days": 14,
//     "candidates": [
//       {
//         "id": 42,
//         "name": "production-rollback-20251110",
//         "created_at": "2025-11-10T14:30:22+00:00",
//         "file_count": 1248,
//         "size_bytes": 432456789,
//         "size_formatted": "412.45 MB"
//       }
//     ],
//     "summary": {
//       "total_workspaces": 1,
//       "total_files": 1248,
//       "total_size_bytes": 432456789,
//       "total_size_formatted": "412.45 MB"
//     }
//   }
// }

// # contoh Cleanup (POST)
// curl -X POST "http://localhost:8000/api/workspace/cleanup-rollback" \
//   -H "Content-Type: application/json" \
//   -d '{
//     "days": 7,
//     "dry_run": true
//   }'
// response:
// {
//   "success": true,
//   "dry_run": true,
//   "message": "Preview: 3 workspace akan dihapus",
//   "candidates_count": 3,
//   "deleted": []
// }

// # contoh eksekusi (POST) not dry run
// curl -X POST "http://localhost:8000/api/workspace/cleanup-rollback" \
//   -d '{"days": 30}'
// response
// {
//   "success": true,
//   "dry_run": false,
//   "message": "Berhasil hapus 3 workspace",
//   "deleted": [
//     {"id": 42, "name": "production-rollback-20251101", ...},
//     {"id": 45, "name": "staging-rollback-20251105", ...}
//   ]
// }
class WorkspaceCleanupController
{
  /**
   * GET /api/workspace/cleanup-rollback
   * Preview workspace rollback yang bisa dihapus
   */
  public function preview(Request $request)
  {
    $days = $request->integer('days', 7);
    $workspaceId = $request->integer('workspace_id'); // opsional: filter per workspace

    try {
      $query = Workspace::rollbackWorkspaces()
        ->olderThanDays($days);

      if ($workspaceId) {
        // Cari workspace rollback yang berasal dari workspace tertentu
        $query = $query->whereHas('mergeSessions', function ($q) use ($workspaceId) {
          $q->where('source_type', 'rollback')
            ->whereJsonContains('metadata->original_workspace_id', $workspaceId);
        });
      }

      $candidates = $query->withCount('files as file_count')
        ->get()
        // Agar konsisten dengan command, tambahkan method ini di akhir WorksapceModel.php:
        ->filter(fn($ws) => $ws->isRollbackWorkspace());

      $totalSize = $candidates->sum(fn($ws) => $ws->files->sum('size_bytes'));

      return Response::make([
        'success' => true,
        'data' => [
          'days' => $days,
          'workspace_id_filter' => $workspaceId,
          'candidates' => $candidates->map(fn($ws) => [
            'id' => $ws->id,
            'name' => $ws->name,
            'created_at' => $ws->created_at->toIso8601String(),
            'file_count' => $ws->file_count,
            'size_bytes' => $ws->files->sum('size_bytes'),
            'size_formatted' => $this->formatBytes($ws->files->sum('size_bytes')),
          ]),
          'summary' => [
            'total_workspaces' => $candidates->count(),
            'total_files' => $candidates->sum('file_count'),
            'total_size_bytes' => $totalSize,
            'total_size_formatted' => $this->formatBytes($totalSize),
          ],
        ],
      ], 200, [
        "content-type" => 'application/json'
      ]);
    } catch (\Exception $e) {
      return Response::make([
        'success' => false,
        'error' => $e->getMessage(),
      ], 500, [
        "content-type" => 'application/json'
      ]);
    }
  }

  /**
   * POST /api/workspace/cleanup-rollback
   * Eksekusi pembersihan
   */
  public function execute(Request $request)
  {
    $request->validate([
      'days' => 'nullable|integer|min=1', // Hapus workspace rollback lebih dari N hari
      'workspace_ids' => 'nullable|array',
      'workspace_ids.*' => 'integer',
      'dry_run' => 'boolean', // Hanya tampilkan, jangan hapus
    ]);

    $days = $request->integer('days', 7);
    $workspaceIds = $request->input('workspace_ids', []);
    $dryRun = $request->boolean('dry_run', false);

    try {
      // Dapatkan kandidat
      $candidates = Workspace::getValidRollbackWorkspaces($days);

      // Filter berdasarkan ID jika disediakan
      if (!empty($workspaceIds)) {
        $candidates = $candidates->filter(fn($ws) => in_array($ws->id, $workspaceIds));
      }

      if ($candidates->isEmpty()) {
        return Response::make([
          'success' => true,
          'message' => 'Tidak ada workspace yang memenuhi kriteria.',
          'deleted' => [],
        ], 200, [
          "content-type" => 'application/json'
        ]);
      }

      $deleted = [];
      $errors = [];

      if (!$dryRun) {
        foreach ($candidates as $workspace) {
          DB::beginTransaction();
          try {
            // Hapus file referensi
            File::where('workspace_id', $workspace->id)->delete();

            // Hapus workspace
            $workspace->delete();

            $deleted[] = [
              'id' => $workspace->id,
              'name' => $workspace->name,
              'created_at' => $workspace->created_at->toIso8601String(),
            ];

            DB::commit();
          } catch (\Exception $e) {
            DB::rollBack();
            $errors[] = "Gagal hapus workspace {$workspace->id}: " . $e->getMessage();
          }
        }
      }

      return Response::make([
        'success' => true,
        'dry_run' => $dryRun,
        'days' => $days,
        'candidates_count' => $candidates->count(),
        'deleted' => $dryRun ? [] : $deleted,
        'errors' => $errors,
        'message' => $dryRun
          ? "Preview: {$candidates->count()} workspace akan dihapus"
          : "Berhasil hapus " . count($deleted) . " workspace",
      ], 200, [
        "content-type" => 'application/json'
      ]);
    } catch (\Exception $e) {
      return Response::make([
        'success' => false,
        'error' => $e->getMessage(),
      ], 500, [
        "content-type" => 'application/json'
      ]);
    }
  }

  /**
   * Helper: Format bytes ke string
   */
  private function formatBytes(int $bytes): string
  {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 2) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 2) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
  }
}

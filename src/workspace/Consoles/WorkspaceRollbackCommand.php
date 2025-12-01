<?php

namespace Dochub\Workspace\Consoles;

use Dochub\Workspace\Models\Merge;
use Dochub\Workspace\Models\MergeSession;
use Dochub\Workspace\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

use function Illuminate\Support\now;

// # Lihat apa yang akan dilakukan
// php artisan workspace:rollback 5 abc123-def456 --dry-run
// # Output:
// 📋 Preview Rollback
// +------------------+----------------------------------+
// | Detail           | Nilai                            |
// +------------------+----------------------------------+
// | Workspace Asli   | #5 - production                  |
// | Merge Tujuan     | #abc123-def456 (v2.1.0)         |
// | Dibuat Pada      | 2025-11-26 14:30:22              |
// | Jumlah File      | 1,248                            |
// | Ukuran Total     | 412.31 MB                        |
// | Nama Workspace Baru | production-rollback-20251127-103045 |
// +------------------+----------------------------------+
// ⚠️  Mode DRY RUN: Tidak ada perubahan yang dilakukan.

// php artisan workspace:rollback 5 abc123-def456 --name="production-pre-client-update"
// # Output:
// ✅ Sukses! Workspace baru dibuat:
// +-----------+----------------------------------+
// | Properti  | Nilai                            |
// +-----------+----------------------------------+
// | ID        | 42                               |
// | Nama      | production-pre-client-update     |
// | File      | 1,248                            |
// | Ukuran    | 412.31 MB                        |
// | Dibuat    | 2025-11-27 10:30:45              |
// +-----------+----------------------------------+
// 💡 Tips: Gunakan 'php artisan blob:stats --workspace=42' untuk analisis detail.

// Integrasi dengan script otomatis (JSON output)
// # Dalam bash script
// RESULT=$(php artisan workspace:rollback 5 abc123-def456 --json)
// WORKSPACE_ID=$(echo $RESULT | jq -r '.new_workspace.id')
// echo "Workspace baru: ID=$WORKSPACE_ID"
// # Lanjutkan deployment...

// Gabungkan dengan blob:stats untuk Analisis Lengkap
// # Rollback + analisis dalam 1 baris
// php artisan workspace:rollback 5 abc123-def456 --name="debug-rollback" && \
// php artisan blob:stats --workspace=$(php artisan workspace:rollback 5 abc123-def456 --json | jq -r '.new_workspace.id')

// # Preview dulu
// php artisan workspace:rollback 5 abc123-def456 --dry-run
// # Eksekusi
// php artisan workspace:rollback 5 abc123-def456 --name="production-pre-update"
// # Lihat apa yang akan dihapus
// php artisan workspace:cleanup-rollback --days=14 --dry-run
// # Hapus yang >30 hari
// php artisan workspace:cleanup-rollback --days=30
// # Output untuk script
// php artisan workspace:cleanup-rollback --json

class WorkspaceRollbackCommand extends Command
{
  protected $signature = 'workspace:rollback 
                            {workspace-id : ID workspace asal}
                            {merge-id : ID merge tujuan (UUID)}
                            {--name= : Nama kustom untuk workspace baru}
                            {--dry-run : Hanya tampilkan preview}
                            {--json : Output JSON}';

  protected $description = 'Buat workspace baru dari state merge tertentu';

  public function handle()
  {
    $workspaceId = (int) $this->argument('workspace-id');
    $mergeId = $this->argument('merge-id');
    $customName = $this->option('name');
    $dryRun = $this->option('dry-run');
    $isJson = $this->option('json');

    try {
      // Validasi
      $workspace = Workspace::findOrFail($workspaceId);
      $merge = Merge::where('id', $mergeId)
        ->where('workspace_id', $workspaceId)
        ->firstOrFail();

      $files = $merge->files()->count();
      $size = $merge->files()->sum('size_bytes');
      $defaultName = $customName ?: "{$workspace->name}-rollback-" . now()->format('Ymd-His');
      $workspaceName = Str::limit($defaultName, 191);

      if ($isJson) {
        $output = [
          'source' => [
            'workspace_id' => $workspaceId,
            'workspace_name' => $workspace->name,
          ],
          'target_merge' => [
            'id' => $mergeId,
            'label' => $merge->label,
            'created_at' => $merge->merged_at->toIso8601String(),
          ],
          'new_workspace' => [
            'name' => $workspaceName,
            'files_count' => $files,
            'total_size_bytes' => $size,
          ],
          'dry_run' => $dryRun,
        ];

        if (!$dryRun) {
          $newWs = $this->createRollbackWorkspace($workspace, $merge, $workspaceName);
          $output['new_workspace']['id'] = $newWs->id;
          $output['new_workspace']['created_at'] = $newWs->created_at->toIso8601String();
        }

        $this->outputJson($output);
        return Command::SUCCESS;
      }

      // Preview
      $this->info("📋 Preview Pembuatan Workspace Rollback");
      $this->table(
        ['Detail', 'Nilai'],
        [
          ['Dari Workspace', "#{$workspace->id} - {$workspace->name}"],
          ['Ke Merge', "#{$merge->id} (" . ($merge->label ?: 'tanpa label') . ")"],
          ['Dibuat Pada', $merge->merged_at->format('Y-m-d H:i:s')],
          ['Jumlah File', number_format($files)],
          ['Ukuran Total', $this->formatBytes($size)],
          ['Nama Baru', $workspaceName],
        ]
      );

      if ($dryRun) {
        $this->warn("⚠️  Mode DRY RUN: Tidak ada workspace yang dibuat.");
        return Command::SUCCESS;
      }

      if (!$this->confirm("Buat workspace baru seperti di atas?")) {
        $this->error("❌ Dibatalkan.");
        return Command::INVALID;
      }

      // Eksekusi
      $newWorkspace = $this->createRollbackWorkspace($workspace, $merge, $workspaceName);

      $this->newLine();
      $this->info("✅ Sukses! Workspace baru:");
      $this->table(
        ['Properti', 'Nilai'],
        [
          ['ID', $newWorkspace->id],
          ['Nama', $newWorkspace->name],
          ['File', number_format($files)],
          ['Ukuran', $this->formatBytes($size)],
          ['Dibuat', $newWorkspace->created_at->format('Y-m-d H:i:s')],
        ]
      );

      $this->info("💡 Gunakan 'php artisan workspace:compare w:{$workspace->id} w:{$newWorkspace->id}' untuk bandingkan.");

      return Command::SUCCESS;
    } catch (\Exception $e) {
      if ($isJson) {
        $this->outputJson(['error' => $e->getMessage()]);
      } else {
        $this->error("❌ " . $e->getMessage());
      }
      return Command::FAILURE;
    }
  }

  /** mirip dengan WorkspaceRollbackController@duplicateFromMerge */
  private function createRollbackWorkspace(Workspace $original, Merge $merge, string $name): Workspace  {
    $newWorkspace = $original->replicate();
    $newWorkspace->name = $name;
    $newWorkspace->save();

    // Salin file
    foreach ($merge->files as $file) {
      $file->replicate()->fill([
        'workspace_id' => $newWorkspace->id,
        'merge_id' => $merge->id, // atau buat merge baru? (kita pakai yang sama untuk efisiensi)'
      ])->save();
    }

    // Catat audit
    MergeSession::create([
      'target_workspace_id' => $newWorkspace->id,
      'source_identifier' => "rollback:{$original->id}:{$merge->id}",
      'source_type' => 'rollback',
      'status' => 'applied',
      'started_at' => now(),
      'completed_at' => now(),
      'initiated_by_user_id' => Auth::user()->id ?? 1,
      'metadata' => [
        'original_workspace_id' => $original->id,
        'source_merge_id' => $merge->id,
        'rollback_method' => 'duplicate',
        'files_copied' => $merge->files->count(),
      ],
    ]);

    return $newWorkspace;
  }

  private function formatBytes(int $bytes): string
  {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 2) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 2) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
  }

  private function outputJson(array $data): void
  {
    $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  }
}

<?php

namespace Dochub\Workspace\Consoles;

use Dochub\Workspace\Models\File;
use Dochub\Workspace\Models\MergeSession;
use Dochub\Workspace\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

use function Illuminate\Support\now;

class WorkspaceCleanupRollbackCommand extends Command
{
  protected $signature = 'workspace:cleanup-rollback 
                            {--days=7 : Hapus workspace rollback lebih dari N hari}
                            {--dry-run : Hanya tampilkan, jangan hapus}
                            {--json : Output JSON}';

  protected $description = 'Hapus workspace hasil rollback yang sudah tua';

  public function handle()
  {
    $days = (int) $this->option('days');
    $dryRun = $this->option('dry-run');
    $isJson = $this->option('json');
    $cutoffDate = now()->subDays($days);

    try {
      // Cari workspace rollback berdasarkan:
      // 1. Nama mengandung pola rollback
      // 2. Ada session dengan source_type = 'rollback'
      $candidates = Workspace::whereHas('sessions', function($query){
        $query->where('source_type', 'rollback');
      })
      // ->where('created_at', '<', $cutoffDate)
      ->withCount('allFiles as file_count')
      ->get();

      if ($candidates->count() < 1) {
        if ($isJson) {
          $this->outputJson(['message' => 'Tidak ada workspace rollback yang ditemukan']);
        } else {
          $this->info("✅ Tidak ada workspace rollback > {$days} hari.");
        }
        return Command::SUCCESS;
      }

      // Hitung total
      $totalSize = $candidates->sum(function ($ws) {
        return $ws->allFiles->sum('size_bytes');
      });

      if ($isJson) {
        $data = [
          'days' => $days,
          'candidates' => $candidates->map(fn($ws) => [
            'id' => $ws->id,
            'name' => $ws->name,
            'created_at' => $ws->created_at->toIso8601String(),
            'file_count' => $ws->file_count,
          ])->all(),
          'total_workspaces' => count($candidates),
          'total_size_bytes' => $totalSize,
          'dry_run' => $dryRun,
        ];

        if (!$dryRun) {
          $deleted = $this->executeDelete($candidates);
          $data['deleted'] = $deleted;
        }

        $this->outputJson($data);
        return Command::SUCCESS;
      }

      // Output human-readable
      $this->info("🗑️  Workspace Rollback yang Ditemukan (> {$days} hari)");
      $this->table(
        ['ID', 'Nama', 'Dibuat', 'File', 'Ukuran'],
        $candidates->map(fn($ws) => [
          $ws->id,
          Str::limit($ws->name, 30),
          $ws->created_at->format('Y-m-d'),
          $ws->file_count,
          $this->formatBytes($ws->allFiles->sum('size_bytes')),
        ])->toArray()
      );

      $this->newLine();
      $this->info("📊 Ringkasan:");
      $this->table([], [
        ['Total workspace', count($candidates)],
        ['Total file', collect($candidates)->sum('file_count')],
        ['Total ukuran', $this->formatBytes($totalSize)],
      ]);

      if ($dryRun) {
        $this->warn("⚠️  Mode DRY RUN: Tidak ada yang dihapus.");
        return Command::SUCCESS;
      }

      if (!$this->confirm("Hapus ". count($candidates)." workspace di atas?")) {
        $this->error("❌ Dibatalkan.");
        return Command::INVALID;
      }

      $deleted = $this->executeDelete($candidates);

      $this->newLine();
      $this->info("✅ Berhasil hapus {$deleted->count()} workspace:");
      $this->table(
        ['ID', 'Nama'],
        $deleted->map(fn($w) => [$w['id'], Str::limit($w['name'], 40)])->toArray()
      );

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

  private function executeDelete(Collection $workspaces): \Illuminate\Support\Collection
  {
    return $workspaces->map(function ($ws) {
      $data = [
        'id' => $ws->id,
        'name' => $ws->name,
        'created_at' => $ws->created_at,
      ];

      // Hapus referensi file dulu
      $ws->deleteAllFiles();

      $ws->sessions()->get(['id','target_workspace_id','result_merge_id'])->map(function ($session) {
        $merge = $session->resultMerge()->first(['id', 'manifest_hash']);
        
        $manifest = $merge->manifest()->first(['hash_tree_sha256','storage_path']);

        // hapus manifest
        $manifest->delete();
        
        // hapus referensi merges 
        $merge->delete();

        // hapus referensi session
        $session->delete();
      });


      // Hapus workspace
      $ws->delete();

      return $data;
    });
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

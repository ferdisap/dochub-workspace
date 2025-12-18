<?php

namespace Dochub\Workspace\Consoles;

use Dochub\Workspace\Blob as WorkspaceBlob;
use Dochub\Workspace\Models\Blob;
use Dochub\Workspace\Models\File;
use Dochub\Workspace\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// php artisan make:command BlobGcCommand

// # jalankan
// # Lihat dulu apa yang akan dihapus
// php artisan blob:gc --dry-run

// # Jalankan GC sungguhan
// php artisan blob:gc

// # Pertahankan blob dari 7 hari terakhir
// php artisan blob:gc --keep-days=7
class BlobGcCommand extends Command
{
  protected $signature = 'blob:gc 
                            {--dry-run : Hanya tampilkan, jangan hapus}
                            {--keep-days=30 : Pertahankan blob dari merge < N hari}';

  protected $description = 'Hapus blob yang tidak terpakai (garbage collection)';

  public function handle()
  {
    $keepDays = (int) $this->option('keep-days');
    $dryRun = $this->option('dry-run');

    $this->info("🗑️  Memulai garbage collection...");
    $this->line("   Mode: " . ($dryRun ? 'DRY RUN' : 'REAL'));
    $this->line("   Retensi: {$keepDays} hari");
    $this->newLine();

    // Cari blob tanpa referensi
    $orphanBlobs = DB::table('dochub_blobs as b')
      ->leftJoin('dochub_files as f', 'b.hash', '=', 'f.blob_hash')
      ->whereNull('f.blob_hash')
      ->select('b.hash', 'b.original_size_bytes')
      ->get(); // collection[$hash, $original_size_bytes]
    
    $totalOrphans = $orphanBlobs->count();
    $totalSize = $orphanBlobs->sum('original_size_bytes');

    if ($totalOrphans === 0) {
      $this->info("✅ Tidak ada blob orfan.");
      return;
    }

    $this->warn("⚠️  Ditemukan {$totalOrphans} blob orfan ({$this->formatBytes($totalSize)})");

    if (!$dryRun) {
      $progress = $this->output->createProgressBar($totalOrphans);
      $progress->start();

      foreach ($orphanBlobs as $blob) {
        // Hapus file fisik
        $path = WorkspaceBlob::hashPath($blob->hash);
        WorkspaceBlob::unlink($path);

        // Hapus dari DB
        Blob::where('hash', $blob->hash)->delete();

        $progress->advance();
      }

      $progress->finish();
      $this->newLine(2);
      $this->info("✅ Berhasil menghapus {$totalOrphans} blob orfan.");
    } else {
      $this->table(
        ['Hash (64)', 'Ukuran'],
        $orphanBlobs->map(fn($b) => [
          substr($b->hash, 0, 8) . '...',
          $this->formatBytes($b->original_size_bytes)
        ])->toArray()
      );
    }
  }

  private function formatBytes(int $bytes): string
  {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 2) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return round($bytes / 1024 / 1024, 2) . ' MB';
    return round($bytes / 1024 / 1024 / 1024, 2) . ' GB';
  }
}

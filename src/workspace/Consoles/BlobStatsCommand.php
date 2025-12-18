<?php

namespace Dochub\Workspace\Consoles;

use Dochub\Workspace\Models\Blob;
use Dochub\Workspace\Models\File;
use Dochub\Workspace\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// php artisan make:command BlobStatsCommand

// # jalankan
// php artisan blob:stats
// php artisan blob:stats --workspace=5

/**
 * Command blob:stats adalah alat analisis dan monitoring yang sangat penting untuk sistem versioning file berbasis blob. 
 * Fungsinya bukan hanya "lihat statistik", 
 * tapi menjadi jendela transparansi terhadap efisiensi, 
 * kesehatan, 
 * dan ROI (Return on Investment) dari sistem deduplikasi yang kamu buat.
 * 
 * Contoh nyata: 
 *  Jika storage cloud Rp15.000/GB/bulan
 *  Kamu hemat 1.93 GB → Rp28.950/bulan 
 *  Di 100 workspace → hemat jutaan rupiah/bulan.
 * 
 * >>> php artisan blob:stats --workspace=client-xyz <<< untuk klien tertentu, Apakah ada file besar tidak wajar? (misal: 10 GB video → perlu review), Berapa banyak file mereka?

 * Contoh hasil command
 * Rasio deduplikasi: 1 : 1.02  
 * Hemat: 1.8%  
 * --> File tidak terdeduplikasi (mungkin hash salah, atau selalu generate UUID).
 * --> Perlu cek logika BlobLocalStorage::store().
 * Ukuran tersimpan: 412 MB  
 * Pertumbuhan: +50 MB/minggu  
 * --> Disk 10 GB akan penuh dalam ~180 minggu.
 * --> Butuh upgrade sebelum Q3 tahun depan.
 * 
 * Debugging saat rollback gagal
 * >>> php artisan blob:stats, untuk:
 * Lihat: "Total blob unik: 1,240"
 * Tapi rollback butuh blob 'a1b2c3...' → tidak ada di daftar?
 * 
 * Contoh output
 * >>> php artisan blob:stats --workspace=5
 *  📊 Statistik untuk Workspace #5:
 * +---------------------+--------------------+ 
 * | Statistik           | Nilai              |
 * +---------------------+--------------------+
 * | Total blob unik     | 248                |
 * | Total file referensi| 3,142              |
 * | File unik (dedup)   | 248                |
 * | Rasio deduplikasi   | 1 : 12.67          |
 * | Ukuran asli         | 892.45 MB          |
 * | Ukuran tersimpan    | 142.31 MB          |
 * | Hemat               | 750.14 MB (84.06%) |
 * +---------------------+--------------------+
 * 📁 5 MIME type teratas:
 * +-------------------+---------+
 * | MIME Type         | Jumlah  |
 * +-------------------+---------+
 * | text/php          | 182     |
 * | application/json  | 45      |
 * | image/png         | 12      |
 * | text/css          | 8       |
 * | application/pdf   | 1       |
 * +-------------------+---------+
 */
class BlobStatsCommand extends Command
{
  protected $signature = 'blob:stats {--workspace=}';

  protected $description = 'Tampilkan statistik blob storage';

  public function handle()
  {
    // app/private/dochub/workspaces
    // $this->info(Workspace::path());
    // $this->info(App::storagePath()); //   D:\data_ferdi\application\S1000D\apps\csdb\storage
    // return;
    // $this->info(realpath(Storage::path("./")));
    // $this->info(Storage::path("app/public"));
    // $this->info(realpath(Storage::path("app/public")) ? 'foo' : 'bar');
    // return;
    $blobQuery = Blob::query();
    $fileQuery = File::query();

    if ($workspaceId = $this->option('workspace')) {
      $this->info("🔍 Filter: workspace ID = {$workspaceId}");
      $fileQuery->where('workspace_id', $workspaceId);
    }

    $totalBlobs = $blobQuery->count();
    $totalFiles = $fileQuery->count();
    $uniqueFiles = $fileQuery->distinct('blob_hash')->count('blob_hash');

    $sizeData = $blobQuery->select(
      DB::raw('SUM(original_size_bytes) as total_original'),
      DB::raw('SUM(stored_size_bytes) as total_stored')
    )->first();

    $naiveSize = $sizeData->total_original ?? 0;
    $actualSize = $sizeData->total_stored ?? 0;
    $savedBytes = $naiveSize - $actualSize;
    $savingsPct = $naiveSize > 0 ? ($savedBytes / $naiveSize) * 100 : 0;

    $topMimes = $blobQuery
      ->select('mime_type', DB::raw('COUNT(*) as count'))
      ->groupBy('mime_type')
      ->orderByDesc('count')
      ->limit(5)
      ->get();

    $this->table(
      ['Statistik', 'Nilai'],
      [
        ['Total blob unik', number_format($totalBlobs)],
        ['Total file referensi', number_format($totalFiles)],
        ['File unik (dedup)', number_format($uniqueFiles)],
        ['Rasio deduplikasi', sprintf('1 : %.2f', $totalFiles / max(1, $uniqueFiles))],
        ['Ukuran asli', $this->formatBytes($naiveSize)],
        ['Ukuran tersimpan', $this->formatBytes($actualSize)],
        ['Hemat', $this->formatBytes($savedBytes) . ' (' . round($savingsPct, 2) . '%)'],
      ]
    );

    if ($topMimes->isNotEmpty()) {
      $this->newLine();
      $this->info('📁 5 MIME type teratas:');
      $this->table(['MIME Type', 'Jumlah'], $topMimes->map(fn($m) => [
        $m->mime_type ?: 'unknown',
        $m->count
      ])->toArray());
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

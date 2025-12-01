<?php

namespace Dochub\Job;

use App\Services\RedisCleanupService;
use Dochub\Upload\Services\CacheCleanup;
use Dochub\Workspace\Services\BlobLocalStorage as BlobStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ProcessZipJob implements ShouldQueue
{
  // use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
  use InteractsWithQueue, Queueable, SerializesModels;

  protected $cache_driver = 'file'; // redis or etc

  public $jobId;

  /**
   * Create a new job instance.
   */
  public function __construct(
    public string $filePath,
    public int $userId,
    public ?string $uploadId = null,
    // public bool $syncMode = false // Jika true, jalankan synchronously
  ) {
    // Jika syncMode true, override queue configuration
    // if ($this->syncMode) {
    // $this->sync = true;
    // }
  }

  public function queue($queue, $command)
  {
    $this->jobId = $this->job->getJobId();
    $queue->push($command);
  }

  /**
   * Execute the job.
   */
  // public function handle(BlobStorage $blobStorage): array
  public function handle(): array
  {
    $result = [];
    $result['status'] = 'completed';
    $result['duration_seconds'] = 5;

    return $result;


    $startTime = microtime(true);
    $result = [
      'upload_id' => $this->uploadId,
      'file_path' => $this->filePath,
      'status' => 'processing',
      'files_processed' => 0,
      'errors' => [],
      'started_at' => now()->toIso8601String(),
    ];

    try {
      // Update status ke Redis
      $this->updateStatus('processing', 0);

      // Validasi file
      if (!file_exists($this->filePath)) {
        throw new \RuntimeException("File not found: {$this->filePath}");
      }

      if (!$this->isValidZip($this->filePath)) {
        throw new \RuntimeException("Invalid ZIP file");
      }

      // Ekstrak ke temporary directory
      $extractDir = $this->createExtractDirectory();
      $this->extractZip($this->filePath, $extractDir);

      // Proses file
      $files = $this->scanDirectory($extractDir);
      $result['total_files'] = count($files);

      // $this->processFiles($files, $blobStorage, $result);

      // Cleanup
      $this->deleteDirectory($extractDir);
      unlink($this->filePath);

      // Update status sukses
      $result['status'] = 'completed';
      $result['completed_at'] = now()->toIso8601String();
      $result['duration_seconds'] = round(microtime(true) - $startTime, 2);

      $this->updateStatus('completed', 100, $result);

      // 🔑 Auto-cleanup jika sync mode atau sudah selesai
      if ($this->uploadId) {
        // Cache::driver($this->cache_driver)->delete($this->uploadId);
        CacheCleanup::driver($this->cache_driver);
        CacheCleanup::cleanupUpload($this->uploadId, [
          'status' => 'completed',
          'upload_id' => $this->uploadId,
        ]);
        // RedisCleanupService::cleanupUpload($this->uploadId, [
        //     'status' => 'completed',
        //     'upload_id' => $this->uploadId,
        // ]);
      }

      Log::info("ProcessZipJob completed", $result);
      return $result;
    } catch (\Exception $e) {
      $result['status'] = 'failed';
      $result['error'] = $e->getMessage();
      $result['completed_at'] = now()->toIso8601String();
      $result['duration_seconds'] = round(microtime(true) - $startTime, 2);

      $this->updateStatus('failed', 0, $result);

      // Cleanup saat error
      if ($this->uploadId) {
        CacheCleanup::driver($this->cache_driver);
        CacheCleanup::cleanupUpload($this->uploadId, [
          'status' => 'failed',
          'upload_id' => $this->uploadId,
          'error' => $e->getMessage()
        ]);
        // RedisCleanupService::cleanupUpload($this->uploadId, [
        //     'status' => 'failed',
        //     'upload_id' => $this->uploadId,
        //     'error' => $e->getMessage(),
        // ]);
      }

      Log::error("ProcessZipJob failed", [
        'upload_id' => $this->uploadId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);

      throw $e;
    }
  }

  /**
   * Update status ke Redis untuk frontend
   */
  private function updateStatus(string $status, int $progress, array $data = []): void
  {
    if (!$this->uploadId) return;

    // $current = Redis::get("upload:{$this->uploadId}");
    $current = Cache::driver($this->cache_driver)->get("upload:{$this->uploadId}");
    $metadata = $current ? json_decode($current, true) : [];

    $update = array_merge($metadata, [
      'status' => $status,
      'progress' => $progress,
      'updated_at' => now()->timestamp,
    ], $data);

    // TTL dinamis
    $ttl = $status === 'completed' ? 300 : 3600;
    Cache::driver($this->cache_driver)->set("upload:{$this->uploadId}", json_encode($update), $ttl);
    // Redis::setex("upload:{$this->uploadId}", $ttl, json_encode($update));
  }

  /**
   * Validasi file ZIP
   */
  private function isValidZip(string $path): bool
  {
    if (!file_exists($path) || filesize($path) < 22) {
      return false;
    }

    $header = file_get_contents($path, false, null, 0, 4);
    return in_array($header, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
  }

  /**
   * Buat direktori ekstrak
   */
  private function createExtractDirectory(): string
  {
    $dir = sys_get_temp_dir() . '/extract_' . uniqid();
    mkdir($dir, 0755, true);
    return $dir;
  }

  /**
   * Ekstrak ZIP file
   */
  private function extractZip(string $zipPath, string $extractDir): void
  {
    $zip = new \ZipArchive;
    if ($zip->open($zipPath) !== true) {
      throw new \RuntimeException("Cannot open ZIP: " . $zip->getStatusString());
    }

    if (!$zip->extractTo($extractDir)) {
      throw new \RuntimeException("Failed to extract ZIP");
    }
    $zip->close();
  }

  /**
   * Scan direktori
   */
  private function scanDirectory(string $dir): array
  {
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
      if ($file->isFile()) {
        $relativePath = str_replace($dir . DIRECTORY_SEPARATOR, '', $file->getPathname());
        $files[$relativePath] = $file->getPathname();
      }
    }

    return $files;
  }

  /**
   * Proses file ke blob storage
   */
  private function processFiles(array $files, BlobStorage $blobStorage, array &$result): void
  {
    $total = count($files);
    $processed = 0;

    foreach ($files as $relativePath => $filePath) {
      try {
        // Skip file berisiko
        if ($this->isDangerousFile($relativePath)) {
          $result['errors'][] = "Skipped dangerous file: {$relativePath}";
          continue;
        }

        // Simpan ke blob
        $hash = $blobStorage->store($filePath);

        // Simpan ke database (implementasi sesuai kebutuhan)
        // Contoh: 
        // CsdbFile::create([...]);

        $processed++;

        // Update progress setiap 10 file
        if ($processed % 10 === 0 || $processed === $total) {
          $progress = round(($processed / $total) * 100);
          $this->updateStatus('processing', $progress, [
            'files_processed' => $processed,
            'total_files' => $total,
          ]);
        }
      } catch (\Exception $e) {
        $result['errors'][] = "Failed to process {$relativePath}: " . $e->getMessage();
        Log::warning("File processing failed", [
          'file' => $relativePath,
          'error' => $e->getMessage(),
        ]);
      }
    }

    $result['files_processed'] = $processed;
  }

  /**
   * Deteksi file berisiko
   */
  private function isDangerousFile(string $path): bool
  {
    $dangerous = [
      '.env',
      '.git',
      'composer.json',
      'composer.lock',
      '.php',
      '.sh',
      '.bat',
      '.exe',
      'node_modules/',
      'vendor/',
    ];

    foreach ($dangerous as $pattern) {
      if (str_contains($path, $pattern)) {
        return true;
      }
    }
    return false;
  }

  /**
   * Hapus direktori rekursif
   */
  private function deleteDirectory(string $dir): void
  {
    if (!is_dir($dir)) return;

    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
      $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
    rmdir($dir);
  }
}

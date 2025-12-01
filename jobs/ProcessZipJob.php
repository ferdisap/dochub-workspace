<?php

namespace Dochub\Job;

use App\Services\RedisCleanupService;
use Dochub\Upload\Services\CacheCleanup;
use Dochub\Workspace\Blob;
use Dochub\Workspace\Enums\ManifestSourceType;
use Dochub\Workspace\Models\Manifest;
use Dochub\Workspace\Services\BlobLocalStorage as BlobStorage;
use Dochub\Workspace\Services\ManifestSourceParser;
use Dochub\Workspace\Workspace;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
// use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Event;

class ProcessZipJob implements ShouldQueue
{
  use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

  protected $cache_driver = 'file'; // redis or etc

  public int $id = 0;

  public string $uploadId;

  /**
   * Create a new job instance.
   */
  public function __construct(
    public string $metadata, // string json
    public int $userId,
    // public bool $syncMode = false // Jika true, jalankan synchronously
  ) {
    // Jika syncMode true, override queue configuration
    // if ($this->syncMode) {
    // $this->sync = true;
    // }
  }

  /**
   * cara panggil
   * $job = ProcessZipJob::withId('', Auth::id(), 1);
   * dispatch($job)->onQueue('uploads');
   * dd($job->id);
   */
  public static function withId(...$args): self
  {
    $job = new self(...$args);
    Event::listen(JobQueued::class, function (JobQueued $event) use (&$job) {
      $job->id = $event->id;
    });
    return $job;
  }

  public function mergeZipChunk($totalChunk, $uploadDir, $fileName)
  {
    $chunks = [];
    for ($i = 0; $i < $totalChunk; $i++) {
      $chunks[] = "{$uploadDir}/{$fileName}.part{$i}";
    }

    // Gabung file
    $final = fopen($this->filePath, 'wb');
    foreach ($chunks as $chunk) {
      if (file_exists($chunk)) {
        $content = file_get_contents($chunk);
        fwrite($final, $content);
        unlink($chunk); // Hapus chunk
      }
    }
    fclose($final);


    // Validasi ZIP
    if (!$this->isValidZip($this->filePath)) {
      unlink($this->filePath);
      rmdir($uploadDir);
      throw new \RuntimeException("Invalid ZIP file", 1);
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
    // $dir = sys_get_temp_dir() . '/extract_' . uniqid();
    $dir = Workspace::path('files/');
    @mkdir($dir, 0755, true);
    return $dir;
  }

  /**
   * Ekstrak ZIP file
   */
  private function extractZip(string $zipPath, bool $unlinkIfSuccess = true): string
  {
    // Validasi file
    if (!file_exists($this->filePath)) {
      throw new \RuntimeException("File not found: {$this->filePath}");
    }

    if (!$this->isValidZip($this->filePath)) {
      throw new \RuntimeException("Invalid ZIP file");
    }

    $extractDir = $this->createExtractDirectory();

    $zip = new \ZipArchive;
    if ($zip->open($zipPath) !== true) {
      throw new \RuntimeException("Cannot open ZIP: " . $zip->getStatusString());
    }

    if (!($success = $zip->extractTo($extractDir))) {
      throw new \RuntimeException("Failed to extract ZIP");
    }
    $zip->close();

    if ($unlinkIfSuccess && $success) unlink($this->filePath);

    return $extractDir;
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
  private function processFiles(array $files, array &$result, bool $unlinkIfSuccess = true): void
  {
    $blob = new Blob();
    $totalprocessed = 0;
    $source = ManifestSourceParser::makeSource(ManifestSourceType::UPLOAD->value, "user-{$this->userId}");
    $wsManifest = $blob->store($this->userId, $source, $files, function (string $hash, string $relativePath, string $absolutePath, ?\Exception $e, int $processed, int $total) use(&$totalprocessed, $unlinkIfSuccess){
      if ($hash || ($e === null)) {
        // if ($unlinkIfSuccess) unlink($absolutePath);
        if ($processed % 10 === 0 || $processed === $total) {
          $progress = round(($processed / $total) * 100);
          $this->updateStatus('processing', $progress, [
            'files_processed' => $processed,
            'total_files' => $total,
          ]);
        }
      } else {
        $result['errors'][] = "Failed to process {$relativePath}: " . $e->getMessage();
        Log::warning("File processing failed", [
          'file' => $relativePath,
          'error' => $e->getMessage(),
        ]);
      }
      $totalprocessed = $processed;
    });
    $result['files_processed'] = $totalprocessed;

    // create manifest model
    Manifest::create([
      'from_id' => $this->userId,
      'source' => $wsManifest->source,
      'version' => $wsManifest->version,
      'total_files' => $wsManifest->total_files,
      'total_size_bytes' => $wsManifest->total_size_bytes,
      'hash_tree_sha256' => $wsManifest->hash_tree_sha256,
      'storage_path' => $wsManifest::hashPath($wsManifest->hash_tree_sha256),
    ]);
    return;
    // $total = count($files);
    // $processed = 0;
    // $blobStorage = app(BlobStorage::class);

    // foreach ($files as $relativePath => $filePath) {
    //   try {
    //     // Skip file berisiko
    //     if (Workspace::isDangerousFile($relativePath)) {
    //       $result['errors'][] = "Skipped dangerous file: {$relativePath}";
    //       continue;
    //     }

    //     // Simpan ke blob
    //     $hash = $blobStorage->store($filePath);

    //     // Simpan ke database (implementasi sesuai kebutuhan)
    //     // Contoh: 
    //     // CsdbFile::create([...]);

    //     $processed++;

    //     // Update progress setiap 10 file
    //     if ($processed % 10 === 0 || $processed === $total) {
    //       $progress = round(($processed / $total) * 100);
    //       $this->updateStatus('processing', $progress, [
    //         'files_processed' => $processed,
    //         'total_files' => $total,
    //       ]);
    //     }
    //   } catch (\Exception $e) {
    //     $result['errors'][] = "Failed to process {$relativePath}: " . $e->getMessage();
    //     Log::warning("File processing failed", [
    //       'file' => $relativePath,
    //       'error' => $e->getMessage(),
    //     ]);
    //   }
    // }
    // $result['files_processed'] = $processed;
  }

  /**
   * Deteksi file berisiko
   */
  // private function isDangerousFile(string $path): bool
  // {
  //   $dangerous = [
  //     '.env',
  //     '.git',
  //     'composer.json',
  //     'composer.lock',
  //     '.php',
  //     '.sh',
  //     '.bat',
  //     '.exe',
  //     'node_modules/',
  //     'vendor/',
  //   ];

  //   foreach ($dangerous as $pattern) {
  //     if (str_contains($path, $pattern)) {
  //       return true;
  //     }
  //   }
  //   return false;
  // }

  /**
   * Hapus direktori rekursif
   * eg: $this->deleteDirectory($extractDir);
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


  /**
   * Execute the job.
   */
  // public function handle(BlobStorage $blobStorage): array
  // public function handle(): array
  public function handle()
  {
    $metadata = json_decode($this->metadata, true);
    // $this->metadata = json_decode($this->metadata, true);
    $fileName = $metadata['file_name'];
    $uploadDir = $metadata['upload_dir'];
    $totalChunk = $metadata['total_chunks'];
    $this->filePath = $uploadDir . "/" . $fileName;
    $this->uploadId = $metadata['upload_id'];
    $startTime = microtime(true);

    // #1. merge chunked zip
    $this->mergeZipChunk($totalChunk, $uploadDir, $fileName);

    $result = [
      'upload_id' => $this->uploadId,
      'file_path' => $this->filePath,
      'status' => 'processing',
      'files_processed' => 0,
      'errors' => [],
      'started_at' => now()->toIso8601String(),
      // nanti ada errors = [string]
    ];

    try {
      // Update status ke Redis
      $this->updateStatus('processing', 0);

      // #2. Ekstrak zip ke temporary directory
      $extractDir = $this->extractZip($this->filePath);

      // Proses file
      $files = $this->scanDirectory($extractDir);
      $result['total_files'] = count($files);

      // #. change into blob
      $this->processFiles($files, $result);
      // $this->deleteDirectory($extractDir); // karena sudah di unlink, tidak perlu di delete directory

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
      }

      Log::error("ProcessZipJob failed", [
        'upload_id' => $this->uploadId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);

      throw $e;
    }
  }
  // /**
  //  * Execute the job.
  //  */
  // // public function handle(BlobStorage $blobStorage): array
  // // public function handle(): array
  // public function handle()
  // {
  //   $metadata = json_decode($this->metadata, true);
  //   // $this->metadata = json_decode($this->metadata, true);
  //   $fileName = $metadata['file_name'];
  //   $uploadDir = $metadata['upload_dir'];
  //   $totalChunk = $metadata['total_chunks'];
  //   $this->filePath = $uploadDir . "/" . $fileName;
  //   $this->uploadId = $metadata['upload_id'];
  //   $startTime = microtime(true);

  //   $this->mergeZipChunk($totalChunk, $uploadDir, $fileName);

  //   // ### start extract

  //   $result = [
  //     'upload_id' => $this->uploadId,
  //     'file_path' => $this->filePath,
  //     'status' => 'processing',
  //     'files_processed' => 0,
  //     'errors' => [],
  //     'started_at' => now()->toIso8601String(),
  //   ];

  //   try {
  //     // Update status ke Redis
  //     $this->updateStatus('processing', 0);

  //     // Validasi file
  //     if (!file_exists($this->filePath)) {
  //       throw new \RuntimeException("File not found: {$this->filePath}");
  //     }

  //     if (!$this->isValidZip($this->filePath)) {
  //       throw new \RuntimeException("Invalid ZIP file");
  //     }

  //     // Ekstrak ke temporary directory
  //     $extractDir = $this->createExtractDirectory();
  //     $this->extractZip($this->filePath, $extractDir);

  //     // Proses file
  //     $files = $this->scanDirectory($extractDir);
  //     $result['total_files'] = count($files);

  //     // $this->processFiles($files, $blobStorage, $result);

  //     // Cleanup
  //     // $this->deleteDirectory($extractDir);
  //     unlink($this->filePath);

  //     // Update status sukses
  //     $result['status'] = 'completed';
  //     $result['completed_at'] = now()->toIso8601String();
  //     $result['duration_seconds'] = round(microtime(true) - $startTime, 2);

  //     $this->updateStatus('completed', 100, $result);

  //     // 🔑 Auto-cleanup jika sync mode atau sudah selesai
  //     if ($this->uploadId) {
  //       // Cache::driver($this->cache_driver)->delete($this->uploadId);
  //       CacheCleanup::driver($this->cache_driver);
  //       CacheCleanup::cleanupUpload($this->uploadId, [
  //         'status' => 'completed',
  //         'upload_id' => $this->uploadId,
  //       ]);
  //       // RedisCleanupService::cleanupUpload($this->uploadId, [
  //       //     'status' => 'completed',
  //       //     'upload_id' => $this->uploadId,
  //       // ]);
  //     }

  //     Log::info("ProcessZipJob completed", $result);
  //     return $result;
  //   } catch (\Exception $e) {
  //     $result['status'] = 'failed';
  //     $result['error'] = $e->getMessage();
  //     $result['completed_at'] = now()->toIso8601String();
  //     $result['duration_seconds'] = round(microtime(true) - $startTime, 2);

  //     $this->updateStatus('failed', 0, $result);

  //     // Cleanup saat error
  //     if ($this->uploadId) {
  //       CacheCleanup::driver($this->cache_driver);
  //       CacheCleanup::cleanupUpload($this->uploadId, [
  //         'status' => 'failed',
  //         'upload_id' => $this->uploadId,
  //         'error' => $e->getMessage()
  //       ]);
  //       // RedisCleanupService::cleanupUpload($this->uploadId, [
  //       //     'status' => 'failed',
  //       //     'upload_id' => $this->uploadId,
  //       //     'error' => $e->getMessage(),
  //       // ]);
  //     }

  //     Log::error("ProcessZipJob failed", [
  //       'upload_id' => $this->uploadId,
  //       'error' => $e->getMessage(),
  //       'trace' => $e->getTraceAsString(),
  //     ]);

  //     throw $e;
  //   }
  // }
}

<?php

namespace Dochub\Job;

use App\Services\RedisCleanupService;
use Dochub\Upload\Cache\NativeCache;
use Dochub\Upload\Services\CacheCleanup;
use Dochub\Workspace\Blob;
use Dochub\Workspace\Enums\ManifestSourceType;
use Dochub\Workspace\Manifest as WorkspaceManifest;
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
use TusPhp\Cache\Cacheable;

// jenis jenis status
// uploading, processing, uploaded => di controller
// processing, completed, failed => di process zip job

/**
 * @deprecated
 * Tidak dipakai ketika ngupload file
 */
class ZipProcessJob extends FileUploadProcessJob implements ShouldQueue
{
  use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
    $dir = Workspace::path('files');
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
   * Proses file ke blob storage
   */
  // private function processFilesToBlobs(array $files, array &$result, bool $unlinkIfSuccess = true): WorkspaceManifest
  // {
  //   $blob = new Blob();
  //   $totalprocessed = 0;
  //   $source = ManifestSourceParser::makeSource(ManifestSourceType::UPLOAD->value, "user-{$this->userId}");
  //   return $blob->store($this->userId, $source, $files, 
  //     function (string $hash, string $relativePath, string $absolutePath, ?\Exception $e, int $processed, int $total) use (&$totalprocessed, $unlinkIfSuccess) {
  //       if ($hash || ($e === null)) {
  //         if ($unlinkIfSuccess) @unlink($absolutePath);
  //         if ($processed % 10 === 0 || $processed === $total) {
  //           $progress = round(($processed / $total) * 100);
  //           $this->updateStatus('processing', $progress, [
  //             'files_processed' => $processed,
  //             'total_files' => $total,
  //           ]);
  //         }
  //       } else {
  //         $result['errors'][] = "Failed to process {$relativePath}: " . $e->getMessage();
  //         Log::warning("File processing failed", [
  //           'file' => $relativePath,
  //           'error' => $e->getMessage(),
  //         ]);
  //         throw new \RuntimeException("File processing failed: {$relativePath}");
  //       }
  //       $totalprocessed = $processed;
  //     }
  //   );
  //   $result['files_processed'] = $totalprocessed;
  // }
  
  /**
   * Execute the job.
   */
  // public function handle(BlobStorage $blobStorage): array
  // public function handle(): array
  public function handle()
  {
    $this->cache = new NativeCache();

    // $this->metadata = json_decode($this->metadata, true);
    $metadata = json_decode($this->metadata, true);
    $fileName = $metadata['file_name'];
    $uploadDir = $metadata['upload_dir'];
    $totalChunk = $metadata['total_chunks'];
    $this->filePath = $uploadDir . "/" . $fileName;
    $this->uploadId = $metadata['upload_id'];
    $startTime = microtime(true);

    $result = [
      'upload_id' => $this->uploadId,
      'file_path' => $this->filePath,
      'status' => 'processing',
      'files_processed' => 0,
      'errors' => [],
      'started_at' => now()->toIso8601String(),
      // ada errors = [string]
    ];

    try {
      // Update status ke Redis
      $this->updateStatus('processing', 0);

      // #1. merge chunked zip
      $this->mergeChunks($totalChunk, $uploadDir, $fileName);

      // #2. Ekstrak zip ke temporary directory
      $extractDir = $this->extractZip($this->filePath);

      // Proses file
      $files = $this->scanDirectory($extractDir);
      $result['total_files'] = count($files);

      // #2. change into blob
      $wsManifest = $this->processFilesToBlobs($files, $result);
      $wsManifest->tags = $metadata['tags'] ?? null;
      
      // #3. create manifest model
      Manifest::create([
        'from_id' => $this->userId,
        'source' => $wsManifest->source,
        'version' => $wsManifest->version,
        'total_files' => $wsManifest->total_files,
        'total_size_bytes' => $wsManifest->total_size_bytes,
        'hash_tree_sha256' => $wsManifest->hash_tree_sha256,
        'storage_path' => $wsManifest->storage_path(),
        'tags' => $wsManifest->tags
      ]);

      // delete directory upload karena sudah menjadi blob
      $this->deleteDirectory($extractDir); // walau sudah di unlink, tetap di delete karena unlink itu hanya filenya, tidak sama foldernya. 

      // Update status sukses
      $result['status'] = 'completed';
      $result['completed_at'] = now()->toIso8601String();
      $result['duration_seconds'] = round(microtime(true) - $startTime, 2);

      $this->updateStatus('completed', 100, $result);

      // 🔑 Auto-cleanup jika sync mode atau sudah selesai
      // $this->cache->delete($this->uploadId);
      // if ($this->uploadId) {
      //   $this->cache->cleanupUpload($this->uploadId, [
      //     'status' => 'completed',
      //     'upload_id' => $this->uploadId,
      //     'uploaded_chunks' => $totalChunk
      //   ]);
      // }

      // Log::info("ZipProcessJob completed", $result);


      return $result;
    } catch (\Exception $e) {
      $result['status'] = 'failed';
      $result['error'] = $e->getMessage();
      $result['completed_at'] = now()->toIso8601String();
      $result['duration_seconds'] = round(microtime(true) - $startTime, 2);

      $this->updateStatus('failed', 0, $result);

      // Cleanup saat error
      // $this->cache->delete($this->uploadId);
      // if ($this->uploadId) {
      //   $this->cache->cleanupUpload($this->uploadId, [
      //     'status' => 'failed',
      //     'upload_id' => $this->uploadId,
      //     'error' => $e->getMessage()          
      //   ]);
      // }

      Log::error("ZipProcessJob failed", [
        'upload_id' => $this->uploadId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);

      throw $e;
    }
  }
}

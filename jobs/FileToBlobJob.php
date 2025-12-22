<?php

namespace Dochub\Job;

use App\Services\RedisCleanupService;
use Dochub\Upload\Cache\NativeCache;
use Dochub\Upload\Services\CacheCleanup;
use Dochub\Workspace\Blob;
use Dochub\Workspace\Enums\ManifestSourceType;
use Dochub\Workspace\File;
use Dochub\Workspace\Manifest as WorkspaceManifest;
use Dochub\Workspace\Models\Blob as ModelsBlob;
use Dochub\Workspace\Models\Manifest as ModelManifest;
use Dochub\Workspace\Models\Merge;
use Dochub\Workspace\Models\MergeSession;
use Dochub\Workspace\Models\Workspace as ModelsWorkspace;
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

class FileToBlobJob extends FileUploadProcessJob implements ShouldQueue
{
  public int $id = 0;
  public string $uuid = ''; // jika fail bisa dicari tahu di table failed_jobs

  // public string $manifestModelSerialized; // string json
  public string $userId;

  protected string $prefixPath = "";


  /**
   * @return \Dochub\Workspace\File
   */
  public function processFileToBlob(array $files, bool $unlinkIfSuccess = true)
  {
    $blob = new Blob();
    $totalprocessed = 0;
    return $blob->justStore(
      $files,
      $total_size_bytes,
      function (string $hash, string $relativePath, string $absolutePath, int $filesize, int $mtime, ?\Exception $e, int $processed, int $total) use (&$totalprocessed, $unlinkIfSuccess) {
        if ($hash || ($e === null)) {
          if ($unlinkIfSuccess) @unlink($absolutePath);
        } else {
          $result['errors'][] = "Failed to process {$relativePath}: " . $e->getMessage();
          Log::warning("File processing failed", [
            'file' => $relativePath,
            'error' => $e->getMessage(),
          ]);
          throw new \RuntimeException("File processing failed: {$relativePath}");
        }
        $totalprocessed = $processed;
      }
    )[0];
  }

  public function handle()
  {
    $this->cache = new NativeCache();
    $this->cache->userId($this->userId);

    $metadata = json_decode($this->metadata, true);
    $fileName = $metadata['file_name'];
    $uploadDir = $metadata['upload_dir'];
    $totalChunk = $metadata['total_chunks'];
    $mtime = $metadata['file_mtime'];
    $this->filePath = $uploadDir . "/" . $fileName;
    $this->processId = $metadata['process_id'];
    $startTime = microtime(true);

    $result = [
      'process_id' => $this->processId,
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
      // Log::info("mtime adalah {$mtime}", []);
      touch($this->filePath, $mtime); // 1764759214 
      // Log::info("setelah touced adalah " . filemtime($this->filePath), []);

      // #2. change into blob (create record of blob)
      $files = $this->scanDirectory($uploadDir);
      $this->processFileToBlob($files, true);

      // #3. create record of manifest
      // if ($this->storeManifestRecord($dhManifest)) {
      //   $dhManifest->store(); // save to local

      //   // #4. create record of files from blob
      //   foreach ($dhManifest->files as $dhFile) {
      //     $this->storeFileRecordFromBlob($dhFile["sha256"], $dhFile["relative_path"], $dhFile["size_bytes"], $dhFile["file_modified_at"]);
      //   }
      // }

      // Update status sukses
      $result['status'] = 'completed';
      $result['completed_at'] = now()->toIso8601String();
      $result['duration_seconds'] = round(microtime(true) - $startTime, 2);

      $this->updateStatus('completed', 100, $result);

      return $result;
    } catch (\Exception $e) {
      $result['status'] = 'failed';
      $result['error'] = $e->getMessage();
      $result['completed_at'] = now()->toIso8601String();
      $result['duration_seconds'] = round(microtime(true) - $startTime, 2);

      $this->updateStatus('failed', 0, $result);

      Log::error("FileToBlobJob failed", [
        'process_id' => $this->processId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);

      throw $e;
    }
  }
}

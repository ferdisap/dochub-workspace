<?php

namespace Dochub\Job;

use App\Services\RedisCleanupService;
use Dochub\Upload\Cache\NativeCache;
use Dochub\Upload\Services\CacheCleanup;
use Dochub\Workspace\Blob;
use Dochub\Workspace\Enums\ManifestSourceType;
use Dochub\Workspace\Manifest as WorkspaceManifest;
use Dochub\Workspace\Models\File;
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
use Illuminate\Support\Str;
use TusPhp\Cache\Cacheable;

// jenis jenis status
// uploading, processing, uploaded => di controller
// processing, completed, failed => di process zip job

class FileUploadProcessJob implements ShouldQueue
{
  use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

  protected $cache_driver = 'file'; // redis or etc

  public int $id = 0;
  public string $uuid = ''; // jika fail bisa dicari tahu di table failed_jobs

  public string $processId; // berbeda dengan $id. $id adalah $event->id
  public string $filePath;

  protected NativeCache $cache;

  public string $metadata; // string json
  public int $userId;

  protected string $prefixPath = "upload";
  /**
   * Create a new job instance.
   * $metadata['file_name']
   * $metadata['upload_dir']
   * $metadata['total_chunks']
   * $metadata['process_id']
   * $metadata['tags']
   * $data["job_id"]
   * $data["job_uuid"]
   * $data['process_id']
   */
  public function __construct(string $metadata, int $userId)
  {
    $this->metadata = $metadata;
    $this->userId = $userId;
  }

  /**
   * cara panggil
   * $job = FileUploadProcessJob::withId('', Auth::id(), 1);
   * dispatch($job)->onQueue('uploads');
   * dd($job->id);
   */
  public static function withId(string $metadata, int $userId): self
  {
    $job = new static($metadata, $userId);
    $cache = new NativeCache();
    Event::listen(JobQueued::class, function (JobQueued $event) use (&$job, $metadata, $cache) {
      // set job id for job class
      $job->id = $event->id;
      $job->uuid = $event->payload()["uuid"];
      // set job id to metadata
      $data = json_decode($metadata, true);
      $data["job_id"] = $job->id;
      $data["job_uuid"] = $job->uuid;
      // save metadata to cache
      $job->processId = $data['process_id'];
      $cache->set($job->processId, $data);
    });
    return $job;
  }

  public function getCache()
  {
    return $this->cache;
  }

  public function changePrefix(string $prefix)
  {
    $this->prefixPath = $prefix;
  }

  public function mergeChunks($totalChunk, $uploadDir, $fileName)
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
    // if (!$this->isValidZip($this->filePath)) {
    //   unlink($this->filePath);
    //   rmdir($uploadDir);
    //   throw new \RuntimeException("Invalid ZIP file", 1);
    // }
  }

  /**
   * Update status ke Redis untuk frontend
   */
  public function updateStatus(string $status, int $progress, array $data = []): void
  {
    if (!$this->processId) return;

    $metadata = $this->cache->getArray($this->processId);

    $update = array_merge($metadata, [
      'status' => $status,
      'progress' => $progress,
      'updated_at' => now()->timestamp,
    ], $data);

    // TTL dinamis
    // $ttl = $status === 'completed' ? 300 : 3600;
    $this->cache->set($this->processId, json_encode($update));
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
   * Scan direktori
   */
  public function scanDirectory(string $dir): array
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
   * Hapus direktori rekursif
   * eg: $this->deleteDirectory($extractDir);
   */
  public function deleteDirectory(string $dir): void
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

  public function storeManifestRecord(WorkspaceManifest $wsManifest, int $workspaceId = 0)
  {
    if(!(Manifest::where('hash_tree_sha256', $wsManifest->hash_tree_sha256)->first(['id']))){
      $fillable = [
        // workspace_id = null, berarti tidak terkait dengan worksapce
        'from_id' => $this->userId,
        'source' => $wsManifest->source,
        'version' => $wsManifest->version,
        'total_files' => $wsManifest->total_files,
        'total_size_bytes' => $wsManifest->total_size_bytes,
        'hash_tree_sha256' => $wsManifest->hash_tree_sha256,
        'storage_path' => $wsManifest->storage_path(),
        'tags' => $wsManifest->tags
      ];
      if($workspaceId || (int) $workspaceId > 0) $fillable['workspace_id'] = $workspaceId;
      Manifest::create($fillable);
      return true;
    }
    return false;
  }

  public function storeFileRecordFromBlob(string $hash, string $relativePath, int $filesize, int $mtime)
  {
    File::updateOrCreate([
      'relative_path' => "upload/{$relativePath}", // unique
      'merge_id' => 0, // walau uuid bisa disi 0
    ],[
      'blob_hash' => $hash,
      'workspace_id' => 0, // zero is nothing. Bisa saja untuk worksapce default
      //'old_blob_hash', // nullable
      'action' => 'upload',
      'size_bytes' => $filesize,
      'file_modified_at' => $mtime
    ]);
  }

  /**
   * Proses file ke blob storage
   */
  public function processFilesToBlobs(array $files, array &$result, bool $unlinkIfSuccess = true): WorkspaceManifest
  {
    $blob = new Blob();
    $totalprocessed = 0;
    $source = ManifestSourceParser::makeSource(ManifestSourceType::UPLOAD->value, "user-{$this->userId}");
    return $blob->store(
      $this->userId,
      $source,
      $files,
      function (string $hash, string $relativePath, string $absolutePath, int $filesize, int $mtime, ?\Exception $e, int $processed, int $total) use (&$totalprocessed, $unlinkIfSuccess) {
        if ($hash || ($e === null)) {
          if ($unlinkIfSuccess) @unlink($absolutePath);
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
          throw new \RuntimeException("File processing failed: {$relativePath}");
        }
        $totalprocessed = $processed;
      }
    );
    $result['files_processed'] = $totalprocessed;
  }

  /**
   * Execute the job.
   */
  // public function handle(BlobStorage $blobStorage): array
  // public function handle(): array
  public function handle()
  {
    $this->cache = new NativeCache();

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
      Log::info("mtime adalah {$mtime}", []);
      touch($this->filePath, $mtime); // 1764759214 
      Log::info("setelah touced adalah " . filemtime($this->filePath), []);

      // #2. change into blob (create record of blob)
      $files = $this->scanDirectory($uploadDir);
      $wsManifest = $this->processFilesToBlobs($files, $result);
      $wsManifest->tags = $metadata['tags'] ?? null;

      // #3. create record of manifest
      if($this->storeManifestRecord($wsManifest)){        
        $wsManifest->store(); // save to local

        // #4. create record of files from blob
        foreach ($wsManifest->files as $wsFile) {
          $this->storeFileRecordFromBlob($wsFile["sha256"], $wsFile["relative_path"], $wsFile["size_bytes"], $wsFile["file_modified_at"]);
        }
      }

      // Update status sukses
      $result['status'] = 'completed';
      $result['completed_at'] = now()->toIso8601String();
      $result['duration_seconds'] = round(microtime(true) - $startTime, 2);

      $this->updateStatus('completed', 100, $result);

      // 🔑 Auto-cleanup jika sync mode atau sudah selesai
      // $this->cache->delete($this->processId);
      // if ($this->processId) {
      //   $this->cache->cleanupUpload($this->processId, [
      //     'status' => 'completed',
      //     'process_id' => $this->processId,
      //     'uploaded_chunks' => $totalChunk
      //   ]);
      // }

      return $result;
    } catch (\Exception $e) {
      $result['status'] = 'failed';
      $result['error'] = $e->getMessage();
      $result['completed_at'] = now()->toIso8601String();
      $result['duration_seconds'] = round(microtime(true) - $startTime, 2);

      $this->updateStatus('failed', 0, $result);

      // Cleanup saat error
      // $this->cache->delete($this->processId);
      // if ($this->processId) {
      //   $this->cache->cleanupUpload($this->processId, [
      //     'status' => 'failed',
      //     'process_id' => $this->processId,
      //     'error' => $e->getMessage()          
      //   ]);
      // }

      Log::error("FileUploadProcessJob failed", [
        'process_id' => $this->processId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);

      throw $e;
    }
  }
}

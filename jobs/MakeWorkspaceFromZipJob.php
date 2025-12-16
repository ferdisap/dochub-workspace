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

class MakeWorkspaceFromZipJob extends FileUploadProcessJob implements ShouldQueue
{
  public int $id = 0;
  public string $uuid = ''; // jika fail bisa dicari tahu di table failed_jobs

  // public string $manifestModelSerialized; // string json
  public int $userId;

  protected string $prefixPath = "";

  public static function withId(string $manifestModelSerialized, int $userId): self
  {
    $dataManifestModelSerialized = json_decode($manifestModelSerialized, true);
    $processId = $dataManifestModelSerialized['hash_tree_sha256'];
    $data['process_id'] = $processId;
    $data['manifest_model'] = $dataManifestModelSerialized;

    $job = new static(json_encode($data), $userId);
    Event::listen(JobQueued::class, function (JobQueued $event) use (&$job, $manifestModelSerialized, $data) {
      $cache = new NativeCache();
      // set job id for job class
      $job->id = $event->id;
      $job->uuid = $event->payload()["uuid"];
      // set job id to manifestModelSerialized
      $data["job_id"] = $job->id;
      $data["job_uuid"] = $job->uuid;
      $data["status"] = 'processing';

      $dataManifestModelSerialized = json_decode($manifestModelSerialized, true);
      $processId = $dataManifestModelSerialized['hash_tree_sha256'];
      $data['process_id'] = $processId;
      $data['manifest_model'] = $dataManifestModelSerialized;

      // save metadata to cache
      $cache->set($processId, json_encode($data));
    });
    return $job;
  }

  public static function getWorkspaceNameFromWsFile(File $wsFile)
  {
    return str_replace(" ", '_', pathinfo($wsFile->relative_path)['filename']);
  }

  public function handle()
  {
    $this->cache = new NativeCache();
    $data = json_decode($this->metadata, true);
    if (!isset($this->processId)) {
      $this->processId = $data['process_id'];
    }

    $manifestModel = new ModelManifest($data['manifest_model']);
    $manifestModel->id = $data['manifest_model']['id'];
    $wsManifest = $manifestModel->content;
    $this->putManifestToMetadata($manifestModel);
    $startTime = microtime(true);
    $now = now();

    $wsFile = $wsManifest->files[0];
    $blobModel = ModelsBlob::findOrFail($wsFile->sha256);
    $this->putBlobToMetadata($blobModel);
    $workspaceName = self::getWorkspaceNameFromWsFile($wsFile);

    $workspaceModel = ModelsWorkspace::firstOrCreate([
      'name' => $workspaceName,
    ], [
      'name' => $workspaceName,
      'visibility' => 'private',
      'owner_id' => $manifestModel->from_id,
    ]);

    $result = [
      'status' => 'processing',
      'files_processed' => 0,
      'errors' => [],
      'started_at' => $now->toIso8601String(),
    ];

    try {
      $this->updateStatus('processing', 0);

      // Ekstrak zip ke temporary directory
      $zipPath = Blob::hashPath($blobModel->hash);
      $extractDir = $this->extractZip($zipPath);

      // Proses file
      $files = $this->scanDirectory($extractDir);
      $result['total_files'] = count($files);

      // #2. change into blob
      $wsManifest = $this->processFilesToBlobs($files, $result);
      $wsManifest->tags = $metadata['tags'] ?? null;

      // #3. create record of manifest
      if ($this->storeManifestRecord($wsManifest, $workspaceModel->id)) {
        $wsManifest->store(); // save to local

        // #4. if manifest_hash is already added to dochub_merges, igonore it. Otherwise assign manifest_hash in dochub_merges
        // untuk menghindari double merge, chek dahulu berdasarkan hash_tree_sha256
        if (!($mergeModel = Merge::where('manifest_hash', $wsManifest->hash_tree_sha256)->first(['id']))) {
          $fillableMerge = [
            'workspace_id' => $workspaceModel->id,
            'manifest_hash' => $wsManifest->hash_tree_sha256,
            // 'label' => 'v1.0.0',
            'merged_at' => $now,
            'message' => 'merge is done by uploading file ' . $wsFile->sha256
          ];
          if ($latestMerge = $workspaceModel->latestMerge()) {
            $fillableMerge['prev_merge_id'] = $latestMerge->id;
          }
          $mergeModel = Merge::create($fillableMerge);
        }

        // #5. create record of files from blob
        foreach ($wsManifest->files as $wsFileinZip) {
          $this->storeFileRecordFromBlob($wsFileinZip["sha256"], $wsFileinZip["relative_path"], $wsFileinZip["size_bytes"], $wsFileinZip["file_modified_at"], (int) $workspaceModel->id, (string) $mergeModel->id);
        }
      }

      // Update status sukses
      $result['status'] = 'completed';
      $result['completed_at'] = now()->toIso8601String();
      $result['duration_seconds'] = round(microtime(true) - $startTime, 2);

      $this->updateStatus('completed', 100, $result);

      // #6. assign completed_at and status to dochub_merge_session
      $mergeSessionModel = MergeSession::create([
        'target_workspace_id' => $workspaceModel->id,
        'initiated_by_user_id' => $this->userId,
        'result_merge_id' => $mergeModel->id,
        'source_identifier' => "upload:" . basename($wsFile->relative_path), // include file extension
        'source_type' => 'upload',
        'started_at' => $now,
        'status' => 'pending',
        // metadata null, berdasarkan db_structure ini bisa dipakai jika ada syncronizing dari third-party
      ]);
      $mergeSessionModel->completed_at = now();
      $mergeSessionModel->status = 'applied';
      $mergeSessionModel->save();
      return $result;
    } catch (\Exception $e) {
      if(isset($mergeModel)) $mergeModel->delete();

      $result['status'] = 'failed';
      $result['error'] = $e->getMessage();
      $result['completed_at'] = now()->toIso8601String();
      $result['duration_seconds'] = round(microtime(true) - $startTime, 2);

      $this->updateStatus('failed', 0, $result);

      Log::error("MakeWorkspaceFromZipJob failed", [
        'upload_id' => $this->processId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);

      throw $e;
    }
  }

  private function putManifestToMetadata(ModelManifest $manifest)
  {
    if (!$this->processId) return;

    $metadata = $this->cache->getArray($this->processId);

    $update = array_merge($metadata, [
      'manifest_model' => $manifest->toArray(),
    ],);

    $this->cache->set($this->processId, json_encode($update));
  }

  private function putBlobToMetadata(ModelsBlob $blob)
  {
    if (!$this->processId) return;

    $metadata = $this->cache->getArray($this->processId);

    $update = array_merge($metadata, [
      'blob_model' => $blob->toArray(),
    ],);

    $this->cache->set($this->processId, json_encode($update));
  }


  /**
   * Ekstrak ZIP file
   * unlink dilakukan manual di setiap user saat hapus uploadan file
   */
  private function extractZip(string $zipPath, bool $unlinkIfSuccess = false): string
  {
    // Validasi file
    if (!file_exists($zipPath)) {
      throw new \RuntimeException("File not found: {$zipPath}");
    }

    if (!$this->isValidZip($zipPath)) {
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

    if ($unlinkIfSuccess && $success) unlink($zipPath);

    return $extractDir;
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
    $dir = Workspace::path('files');
    @mkdir($dir, 0755, true);
    return $dir;
  }
}

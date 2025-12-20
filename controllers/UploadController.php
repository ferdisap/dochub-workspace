<?php

namespace Dochub\Controller;

use Dochub\Job\MakeWorkspaceFromZipJob;
use Dochub\Upload\Cache\NativeCache;
use Dochub\Upload\Cache\RedisCache;
use Dochub\Upload\EnvironmentDetector;
use Dochub\Upload\Models\Resources\Upload as ResourcesUpload;
use Dochub\Upload\Models\Upload;
use Dochub\Workspace\Blob;
use Dochub\Workspace\Enums\ManifestSourceType;
use Dochub\Workspace\Models\Blob as ModelsBlob;
use Dochub\Workspace\Models\File;
use Dochub\Workspace\Models\Manifest;
use Dochub\Workspace\Models\Workspace;
use Dochub\Workspace\Services\BlobLocalStorage;
use Dochub\Workspace\Services\ManifestLocalStorage;
use Exception;
use Illuminate\Container\Attributes\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UploadController
{
  public function formView(Request $request)
  {
    return view('vendor.dochub.upload.app', [
      'user' => $request->user()
    ]);
  }

  public function listView(Request $request)
  {
    return view('vendor.dochub.upload.list.app', [
      'user' => $request->user()
    ]);
  }

  public function validateLimitUpload(mixed $userId)
  {
    // $list = (Upload::where('owner_id', $userId)->limit(env('upload.limit_file'))->count());
    $list = Manifest::whereNull('workspace_id')->where('from_id', $userId)->where('source', 'LIKE', ManifestSourceType::UPLOAD->value . "%")->limit(env('upload.limit_file'))->count();
    if ($list > 100) {
      abort(403, "Upload file limit reached");
    }
  }

  /**
   * Get upload configuration
   */
  public function getConfig()
  {
    $driver = config('upload.default');
    return response()->json([
      'driver' => $driver,
      'environment' => EnvironmentDetector::getEnvironment(),
      'max_size' => config('upload.max_size'),
      'chunk_size' => config('upload.chunk_size'),
      'expiration' => config('upload.expiration'),
      'tus_enabled' => class_exists(\TusPhp\Tus\Server::class) && ($driver === 'tus'),
      'redis_available' => $this->isRedisAvailable(),
    ]);
  }

  public function getManifest(Request $request)
  {
    $manifestModels = Manifest::where('from_id', $request->user()->id);

    // jika ada pencarian berdasarkan tags
    $tags = $request->get('tags');
    if ($tags) $manifestModels->where('tags', 'LIKE', "%{$tags}%");
    $manifestModels = $manifestModels->get(['storage_path']);

    $manifestModels = collect($manifestModels)->map(function ($model) {
      return $model->content;
    });

    return response($manifestModels);
  }

  private function deletingFile(Manifest $manifest, ModelsBlob $blob)
  {
    $files = $blob->files;
    $hash = $blob->hash;

    $fileModelDeleted = [];
    if ($files->count() > 0) {
      $dhBlob = new Blob(); // Ws Blob
      // jika ada di storage
      if ($dhBlob->isExist($hash)) {
        // hapus blob model dan manifest model
        if ($blob->delete()) {
          if ($manifest->delete()) {
            // hapus blob storage
            if ($dhBlob->destroy($hash)) {
              // delete model file
              foreach ($files as $fileModel) {
                if ($fileModel->delete()) {
                  $fileModelDeleted[] = $fileModel;
                }
              }
              return true;
            } else {
              // recreate $manifest model deleted
              // Log::info("Failed to delete dhBlob", []);
            }
          } else {
            // recreate $blob model deleted
            // Log::info("Failed to delete manifest model", []);
          }
        } else {
          // recreate file Model deleted here
          // Log::info("Failed to delete blob model", []);
        }
      }
    }
    return false;
  }

  public function deleteFile(Request $request, Manifest $manifest, ModelsBlob $blob)
  {
    // validate, jika manifest terhubung ke merges, maka tidak bisa dihapus di sini
    if ($manifest->merges()->count() > 1) {
      abort(403, "Forbiden to delete file");
    }

    if ($this->deletingFile($manifest, $blob)) {
      return response()->json([
        'blob' => $blob
      ]);
    }
    abort(500, "Failed to delete file");
  }

  public function list(Request $request)
  {
    $manifest = Manifest::whereNull('workspace_id')->where('from_id', $request->user()->id)->where('source', 'LIKE', ManifestSourceType::UPLOAD->value . "%")->limit(env('upload.limit_file'))->get();
    return response()->json([
      "list" => ResourcesUpload::collection($manifest),
    ]);
  }

  public function makeWorkspace(Request $request, Manifest $manifest)
  {
    // validate the total of files in manifest must be 1
    $dhManifest = ($manifest->content);
    if (count($dhManifest->files) != 1) {
      return response("Files must be one ea, actual " . count($dhManifest->files), 400);
    }

    // validasi mime (harus zip)
    $dhFile = $dhManifest->files[0];
    $blob = ModelsBlob::findOrFail($dhFile->sha256);
    if (!in_array($blob->mime_type, ['application/zip', 'application/gzip'])) {
      return response("Files must be zip formated, actual " . $blob->mime_type, 400);
    }

    // tidak ada validasi workspace name. Jika existing ada maka akan di merge. Meskipun filenya sama tidak akan membuat record apapun kecuali dochub_merge_session record
    // validasi workspace name
    // $workspaceName = MakeWorkspaceFromZipJob::getWorkspaceNameFromWsFile($dhFile);
    // if (Workspace::where('name', $workspaceName)->first('id')) {
    //   return response("Files name exist, {$workspaceName}", 400);
    // }

    $processId = $dhManifest->hash_tree_sha256;
    $filesize = $dhManifest->total_size_bytes;
    if ($filesize > (1 * 1024 * 1024)) {
      $job = MakeWorkspaceFromZipJob::withId($manifest->toJson(), $request->user()->id);
      dispatch($job)->onQueue('making-workspace-from-upload');
      $jobId = $job->id;
    } else {
      $cache = new NativeCache();
      $dataManifestModelSerialized = $manifest->toArray();
      $data['process_id'] = $processId;
      $data['manifest_model'] = $dataManifestModelSerialized;
      // $cache->set($processId, json_encode($data));

      MakeWorkspaceFromZipJob::dispatchSync(json_encode($data), $request->user()->id);
      $processId = $manifest->hash_tree_sha256;
      // set job id to metadata
      $data = $cache->getArray($processId);
      $data["job_id"] = 0;
      // save metadata to cache
      $cache->set($processId, $data);
    }

    return response()->json([
      'process_id' => $processId,
      'job_id' => $jobId ?? $data['job_id'],
      // 'job_uuid' => $jobUuId ?? '', // jika perlu uuid
      'status' => 'processing',
    ]);
  }

  /**
   * GET /upload/{id}/status
   * 
   * 🔑 Tambahkan auto-cleanup jika sudah selesai
   */
  public function getMakeWorkspaceStatus(Request $request, string $id)
  {
    $cache = new NativeCache();
    $data = $cache->getArray($id);

    // dd($data);
    if (count($data) < 1) {
      return response()->json(['error' => 'Process not found'], 404);
    }

    if ($data['status'] !== 'completed') {
      return response()->json(['error' => 'Making workspace still in progress'], 422); // uncompressable content
    }

    $manifestModel = Manifest::find($data['manifest_model']['id']);

    if (isset($data['blob_model']['hash'])) {
      $blobModel = ModelsBlob::find($data['blob_model']['hash']);

      // 🔑 Auto-cleanup dari cache jika sudah selesai
      // $cleaned = true; // untuk debut, sehingga tidak dihapus (overwrite)
      $cleaned = $cache->cleanupIfCompleted($id, $data); // sebenernya ngapus file uploadan (chunk), jadi kita tambahkan script untuk hapus file manual

      $data_return = $data;
      unset($data_return['blob_model']);
      unset($data_return['manifest_model']);

      if ($cleaned) {
        $deleted = $this->deletingFile($manifestModel, $blobModel);
        if ($deleted) {
          return response()->json([
            'process_id' => $id,
            'job_id' => $data['job_id'],
            'status' => $data['status'],
            'data' => $data,
          ]);
        }
      }
    }

    // Return status normal
    return response()->json([
      'process_id' => $id,
      'status' => 'processing',
      'job_id' => $data['job_id'] ?? null,
      'data' => $data,
    ]);
  }

  private function isRedisAvailable(): bool
  {
    return RedisCache::isAvailable();
  }
}

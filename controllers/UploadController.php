<?php

namespace Dochub\Controller;

use Dochub\Upload\Cache\RedisCache;
use Dochub\Upload\EnvironmentDetector;
use Dochub\Upload\Models\Resources\Upload as ResourcesUpload;
use Dochub\Upload\Models\Upload;
use Dochub\Workspace\Blob;
use Dochub\Workspace\Enums\ManifestSourceType;
use Dochub\Workspace\Models\Blob as ModelsBlob;
use Dochub\Workspace\Models\File;
use Dochub\Workspace\Models\Manifest;
use Dochub\Workspace\Services\BlobLocalStorage;
use Dochub\Workspace\Services\ManifestLocalStorage;
use Exception;
use Illuminate\Container\Attributes\Auth;
use Illuminate\Http\Request;

class UploadController
{
  public function formView(Request $request)
  {
    return view('vendor.workspace.upload.app', [
      'user' => $request->user()
    ]);
  }

  public function listView(Request $request)
  {
    return view('vendor.workspace.upload.list.app', [
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

  public function getFile(Request $request, ModelsBlob $blob)
  {
    $wsBlob = new Blob();
    $hash = $blob->hash;
    return response()->stream(
      function () use ($hash, $wsBlob) {
        $wsBlob->readStream(
          $hash,
          function ($stream) {
            while (!feof($stream)) {
              echo fread($stream, 8192);
              flush();
            }
          }
        );
      },
      200,
      [
        'Content-Type' => $blob->mime_type ?: 'application/octet-stream',
        'Content-Length' => $blob->original_size_bytes, // Ukuran asli, bukan terkompresi!
        'Content-Disposition' => 'inline',
        // 'Content-Disposition' => 'attachment; filename="blob"',
        // 'Content-Disposition' => 'inline; filename="blob"',
        // 🔑 JANGAN tambahkan 'Content-Encoding' kecuali benar-benar streaming compressed
      ]
    );
  }

  public function deleteFile(Request $request, Manifest $manifest, ModelsBlob $blob)
  {
    // validate, jika manifest terhubung ke merges, maka tidak bisa dihapus di sini
    if($manifest->merges()->count() > 1){
      abort(403, "Forbiden to delete file");
    }

    $files = $blob->files; // File Model
    $hash = $blob->hash;

    // jika ada model file
    if($files->count() > 0){
      $wsBlob = new Blob(); // Ws Blob
      // jika ada di storage
      if($wsBlob->isExist($hash)){
        // delete model file
        foreach ($files as $fileModel) {
          $fileModel->delete();
        }
        // hapus blob model dan manifest model
        if($blob->delete() && $manifest->delete()){
          // hapus blob storage
          if($wsBlob->destroy($hash)){
            return response()->json([
              'blob' => $blob
            ]);
          }
        }
      }
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

  public function makeWorkspace(Request $request, Manifest $manifest) {
    dd($manifest);
  }

  private function isRedisAvailable(): bool
  {
    return RedisCache::isAvailable();
  }
}

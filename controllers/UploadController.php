<?php

namespace Dochub\Controller;

use Dochub\Upload\Cache\RedisCache;
use Dochub\Upload\EnvironmentDetector;
use Dochub\Workspace\Blob;
use Dochub\Workspace\Models\Blob as ModelsBlob;
use Dochub\Workspace\Models\Manifest;
use Dochub\Workspace\Services\BlobLocalStorage;
use Dochub\Workspace\Services\ManifestLocalStorage;
use Illuminate\Container\Attributes\Auth;
use Illuminate\Http\Request;

class UploadController
{
  public function form(Request $request){
    return view('vendor.workspace.upload', [
      'user' => $request->user()
    ]);
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

  // public function getFile(Request $request, Blob $hash)
  public function getFile(Request $request, ModelsBlob $blob)
  {
    // dd($blob->compression_type);
    // $stream = $blobLocalStorage->getBlobContent($hash, $compressionType, true);
    //   try {
    //     while (!feof($stream)) {
    //       echo fread($stream, 10);
    //     }
    //   } finally {
    //     fclose($stream);
    //   }
    $blobLocalStorage = app(BlobLocalStorage::class);
    $hash = $blob->hash;

    // return response()->stream(
    //   function () use ($hash, $blobLocalStorage, $compressionType) {
    //     $blobLocalStorage->withBlobContent($hash, $compressionType, function ($stream) {
    //       while (!feof($stream)) {
    //         echo fread($stream, 8192);
    //         flush();
    //       }
    //     });
    //   },
    //   200,
    //   [
    //     // 'Content-Type' => $blob->mime_type ?: 'application/octet-stream',
    //     // 'Content-Length' => $blob->original_size_bytes, // Ukuran asli, bukan terkompresi!
    //     // 'Content-Disposition' => 'inline; filename="blob"',
    //     // 🔑 JANGAN tambahkan 'Content-Encoding' kecuali benar-benar streaming compressed
    //   ]
    // );

    return response()->stream(
      function () use ($hash, $blobLocalStorage) {
        $blobLocalStorage->readStream(
          $hash,
          function ($stream) {
            while (!feof($stream)) {
              echo fread($stream, 8192);
              flush();
            }
          }
        );
      },
      200, [
        'Content-Type' => $blob->mime_type ?: 'application/octet-stream',
        'Content-Length' => $blob->original_size_bytes, // Ukuran asli, bukan terkompresi!
        'Content-Disposition' => 'inline; filename="blob"',
        // 🔑 JANGAN tambahkan 'Content-Encoding' kecuali benar-benar streaming compressed
      ]
    );
    dd($blob->original_size_bytes);
    dd($blob->getContent);
    dd($blob->isText);
    dd($blob);
  }

  private function isRedisAvailable(): bool
  {
    return RedisCache::isAvailable();
  }
}

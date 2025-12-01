<?php

namespace Dochub\Controller;

use Dochub\Upload\Cache\RedisCache;
use Dochub\Upload\EnvironmentDetector;
use Dochub\Workspace\Models\Manifest;
use Dochub\Workspace\Services\ManifestLocalStorage;
use Illuminate\Http\Request;

class UploadController
{
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

  public function getManifest(Request $request){
    // $id = $request->id;
    $version = "2025-12-01T14:14:08.611Z";
    $manifestModel = Manifest::where('version', $version)->first();
    $manifest = app(ManifestLocalStorage::class)->get($manifestModel->storage_path);
    dd($manifestModel->storage_path, $manifest);
  }

  private function isRedisAvailable(): bool
  {
    return RedisCache::isAvailable();
  }
}

<?php

namespace Dochub\Controller;

use Dochub\Upload\EnvironmentDetector;
use Dochub\Upload\Services\NativeUploadHandler;
use Dochub\Upload\Services\TusUploadHandler;
use Illuminate\Http\Request;
use Dochub\Upload\Services\RedisClient;


/** @deprecated */
class UploadController
{
  public function __construct(
    private TusUploadHandler $tusHandler,
    private NativeUploadHandler $nativeHandler
  ) {}

  public function createUpload(Request $request)
  {
    return $this->tusHandler->createUpload($request);
  }

  public function headUpload(Request $request, string $id)
  {
    return $this->tusHandler->headUpload($request, $id);
  }

  public function patchUpload(Request $request, string $id)
  {
    return $this->tusHandler->patchUpload($request, $id);
  }

  public function getUploadStatus(Request $request, string $id)
  {
    // Cek apakah ini upload native (prefix "native_")
    if (str_starts_with($id, 'native_')) {
      return $this->nativeHandler->getStatus($id);
    }

    // Untuk Tus, redirect ke headUpload
    return $this->headUpload($request, $id);
  }

  public function deleteUpload(Request $request, string $id)
  {
    if (str_starts_with($id, 'native_')) {
      return $this->nativeHandler->deleteUpload($id);
    }

    // Untuk Tus, pakai tus delete
    return $this->tusHandler->deleteUpload($id);
  }

  /**
   * Single endpoint for all upload methods
   * 
   * @query param string $driver Override driver (tus/native)
   */
  public function upload(Request $request)
  {
    // Tentukan driver
    $driver = $request->query('driver')
      ?? config('upload.default')
      ?? EnvironmentDetector::getUploadStrategy();

    // Validasi
    if (!in_array($driver, ['tus', 'native'])) {
      return response()->json(['error' => 'Invalid driver'], 400);
    }

    // Delegate ke handler spesifik
    return match ($driver) {
      'tus' => $this->tusHandler->handle($request),
      'native' => $this->nativeHandler->handle($request),
    };
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

  private function isRedisAvailable(): bool
  {
    try {
      $redis = $this->getRedisClient();
      return $redis->ping() === '+PONG' || 'PONG';
    } catch (\Exception $e) {
      return false;
    }
  }

  private static function getRedisClient()
  {
    return RedisClient::getInstance();
  }
}

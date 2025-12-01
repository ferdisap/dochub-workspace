<?php

namespace Dochub\Upload\Services;

use Dochub\Controller\UploadController;
use Dochub\Job\ProcessZipJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use TusPhp\Tus\Server;

class TusUploadHandler
{
  private Server $tusServer;

  public function __construct()
  {
    $this->tusServer = new Server();
    $this->configureTus();
  }

  // 🔑 Method baru untuk route Tus
  public function createUpload(Request $request)
  {
    // Set request method ke POST untuk Tus
    $_SERVER['REQUEST_METHOD'] = 'POST';
    return $this->serveTus($request);
  }

  public function headUpload(Request $request, string $id)
  {
    // Set URL path untuk Tus
    $_SERVER['REQUEST_URI'] = "/upload/{$id}";
    $_SERVER['REQUEST_METHOD'] = 'HEAD';
    return $this->serveTus($request);
  }

  public function patchUpload(Request $request, string $id)
  {
    $_SERVER['REQUEST_URI'] = "/upload/{$id}";
    $_SERVER['REQUEST_METHOD'] = 'PATCH';
    return $this->serveTus($request);
  }

  // 🔑 Method inti yang dipakai semua route
  private function serveTus(Request $request)
  {
    try {
      // Simulasi environment Tus
      $this->mockTusEnvironment($request);

      $response = $this->tusServer->serve();

      // Konversi response Tus ke Laravel response
      return response(
        // $response->getBody(),
        $response->getContent(),
        $response->getStatusCode(),
        // $response->getHeaders()
        $response->headers->all()
      );
    } catch (\Exception $e) {
      Log::error("Tus error", [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);

      return response()->json([
        'error' => 'Upload failed',
        'message' => config('app.debug') ? $e->getMessage() : null
      ], 500);
    }
  }

  // 🔑 Simulasi environment untuk Tus PHP
  private function mockTusEnvironment(Request $request)
  {
    // Set global variables yang dibutuhkan Tus PHP
    $_SERVER['HTTP_HOST'] = $request->getHost();
    $_SERVER['REQUEST_SCHEME'] = $request->getScheme();
    $_SERVER['SCRIPT_NAME'] = '/dochub/upload';

    // Copy headers
    foreach ($request->headers->all() as $name => $values) {
      $headerName = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
      $_SERVER[$headerName] = $values[0] ?? '';
    }

    // Set input stream
    if ($request->getContent() !== null) {
      $input = fopen('php://memory', 'r+');
      fwrite($input, $request->getContent());
      rewind($input);
      $_SERVER['REQUEST_BODY'] = $input;
    }
  }

  public function handle(Request $request)
  {
    try {
      return $this->tusServer->serve();
    } catch (\Exception $e) {
      Log::error("Tus upload error", [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);

      return response()->json([
        'error' => 'Upload failed',
        'message' => config('app.debug') ? $e->getMessage() : null
      ], 500);
    }
  }

  private function configureTus()
  {
    $config = config('upload.driver.tus');

    // Set root directory
    $this->tusServer->setUploadDir($config['root']);

    // Konfigurasi Redis jika diperlukan
    if ($config['use_redis']) {
      $this->tusServer->setCache($this->getRedisClient());
    }

    // Set expiration
    $this->tusServer->setExpiration(config('upload.expiration'));

    // Hook handlers
    $this->tusServer->event()->addListener('tus-server.upload.complete', function ($event) {
      $this->onUploadComplete($event['upload']->getKey());
    });
  }

  private function getRedisClient()
  {
    return RedisClient::getInstance();
  }

  private function onUploadComplete(string $uploadKey)
  {
    $upload = $this->tusServer->getUpload($uploadKey);
    $filePath = $upload->getFilePath();

    // Dispatch processing
    ProcessZipJob::dispatch($filePath, Auth::user()->id, $uploadKey)
      ->onQueue('uploads');
  }
}

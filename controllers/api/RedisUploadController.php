<?php

namespace Dochub\Controller;

use Dochub\Job\ZipProcessJob;
use Dochub\Upload\Tus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Response;
// use Illuminate\Support\Facades\Redis;
use TusPhp\Tus\Server;
use Predis\Client as PredisClient; //

/** @deprecated */
class RedisUploadController
{
  protected PredisClient $redis;

  public function __construct()
  {
    // Ambil koneksi Redis default (harus dikonfigurasi sebagai 'predis')
    /** @var PredisConnection $connection */
    // $connection = app('redis')->connection();
    $connection = App::make('redis')->connection();

    // Dapatkan client Predis\Client dari connection wrapper
    $this->redis = $connection->client();
  }

  public function handleUpload(Request $request, Server $server)
  {
    // Deteksi Tus vs Native
    if ($this->isTusRequest($request)) {
      return $this->handleTusUpload($request, $server);
    }

    return $this->handleNativeUpload($request);
  }

  private function isTusRequest(Request $request): bool
  {
    return $request->hasHeader('Upload-Length') ||
      $request->method() === 'PATCH' ||
      $request->method() === 'HEAD';
  }

  private function handleTusUpload(Request $request, Server $server)
  {
    try {
      $response = $server->serve();

      // Hook saat upload selesai
      if ($request->method() === 'PATCH') {
        $upload = $server->getUpload();
        if ($upload->isFinished()) {
          $this->dispatchProcessing($upload->getKey());
        }
      }

      return $response;
    } catch (\Exception $e) {
      Log::error("Tus error: " . $e->getMessage());
      return Response::make(['error' => $e->getMessage()], 500, ["content-type" => 'application/json']);
    }
  }

  private function handleNativeUpload(Request $request)
  {
    $request->validate([
      'file' => 'required|file|max:5000000', // 5 GB max
    ]);

    // Simpan ke temporary
    $tempPath = Tus::path('native_' . uniqid() . '.tmp');
    $handle = fopen($tempPath, 'wb');

    try {
      $input = fopen('php://input', 'rb');
      while (!feof($input)) {
        $chunk = fread($input, 8192);
        if ($chunk === false) break;
        fwrite($handle, $chunk);
      }
      fclose($input);
      fclose($handle);

      // Validasi
      $this->validateZipFile($tempPath);

      // Queue processing
      $uploadKey = 'native_' . uniqid();
      // Redis::setex("upload:{$uploadKey}", 3600, $tempPath);
      $this->redis->setex("upload:{$uploadKey}", 3600, $tempPath);

      return Response::make([
        'upload_id' => $uploadKey,
        'status' => 'uploaded',
      ], 200, ["content-type" => 'application/json']);
    } catch (\Exception $e) {
      @unlink($tempPath);
      throw $e;
    }
  }

  public function uploadComplete(Request $request)
  {
    $request->validate([
      'upload_id' => 'required|string',
    ]);

    // Ambil path dari Redis
    // $tempPath = Redis::get("upload:{$request->upload_id}");
    $tempPath = $this->redis->get("upload:{$request->upload_id}");
    if (!$tempPath || !file_exists($tempPath)) {
      return Response::make(['error' => 'Upload not found'], 404, ["content-type" => 'application/json']);
    }

    // Dispatch job
    ZipProcessJob::dispatch($tempPath, Auth::user()->id)
      ->onQueue('uploads');

    // Hapus dari Redis
    // Redis::del("upload:{$request->upload_id}");
    $this->redis->del("upload:{$request->upload_id}");

    return Response::make([
      'status' => 'processing',
      'job_id' => 'job-' . uniqid(),
    ], 200, ["content-type" => 'application/json']);
  }

  private function dispatchProcessing(string $uploadKey)
  {
    // $upload = app(Server::class)->getUpload($uploadKey);
    $upload = App::make(Server::class)->getUpload($uploadKey);
    $filePath = $upload->getFilePath();

    // Simpan path ke Redis untuk callback
    // Redis::setex(
    $this->redis->setex(
      "tus_upload:{$uploadKey}",
      3600,
      json_encode(['path' => $filePath, 'key' => $uploadKey])
    );

    // Trigger processing via queue
    ZipProcessJob::dispatch($filePath, Auth::user()->id, $uploadKey)
      ->onQueue('uploads');
  }

  private function validateZipFile(string $path)
  {
    if (!file_exists($path) || filesize($path) < 22) {
      throw new \RuntimeException("Invalid file");
    }

    $header = file_get_contents($path, false, null, 0, 4);
    if (!in_array($header, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"])) {
      throw new \RuntimeException("Not a ZIP file");
    }
  }
}

<?php

namespace Dochub\Upload\Services;

use Dochub\Controller\UploadController;
use Dochub\Job\ProcessZipJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use TusPhp\Tus\Server;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class NativeUploadHandler
{
  /**
   * GET /upload/{id}/status
   * Cek status upload native
   */
  public function getStatus(string $uploadId)
  {
    $metadata = Redis::get("upload:{$uploadId}");

    if (!$metadata) {
      return response()->json([
        'error' => 'Upload not found',
        'code' => 'UPLOAD_NOT_FOUND'
      ], 404);
    }

    $data = json_decode($metadata, true);
    $status = $this->determineStatus($data);

    return response()->json([
      'id' => $uploadId,
      'status' => $status,
      'created_at' => date('c', $data['created_at']),
      'expires_at' => date('c', $data['expires_at']),
      'file_size' => $data['size'] ?? 0,
      'processing_job' => $data['job_id'] ?? null,
    ]);
  }

  private function determineStatus(array $data): string
  {
    // Cek apakah sudah diproses
    if (isset($data['job_id'])) {
      // Implementasi status job (contoh sederhana)
      return 'processing';
    }

    // Cek expired
    if ($data['expires_at'] < time()) {
      return 'expired';
    }

    return 'uploaded'; // menunggu pemrosesan
  }

  /**
   * DELETE /upload/{id}
   * Hapus upload native
   */
  public function deleteUpload(string $uploadId)
  {
    $metadata = Redis::get("upload:{$uploadId}");

    if (!$metadata) {
      return response()->json(['message' => 'Upload not found'], 404);
    }

    $data = json_decode($metadata, true);

    // Hapus file fisik
    if (isset($data['path']) && file_exists($data['path'])) {
      unlink($data['path']);
    }

    // Hapus dari Redis
    Redis::del("upload:{$uploadId}");

    return response()->json([
      'message' => 'Upload deleted',
      'id' => $uploadId
    ]);
  }
  public function handle(Request $request)
  {
    $request->validate([
      'file' => 'required|file',
    ]);

    try {
      $uploadId = 'native_' . Str::random(16);
      $tempPath = $this->getTempPath($uploadId);

      // Stream ke disk
      $this->streamToDisk($request, $tempPath);

      // Simpan metadata ke Redis
      $this->storeMetadata($uploadId, $tempPath);
      // ... upload logic ...

      // Simpan metadata dengan placeholder job_id
      $metadata = [
        'path' => $tempPath,
        'created_at' => now()->timestamp,
        'expires_at' => now()->addSeconds(config('upload.expiration'))->timestamp,
        'size' => filesize($tempPath),
      ];

      Redis::setex(
        "upload:{$uploadId}",
        config('upload.expiration'),
        json_encode($metadata)
      );

      // Dispatch job dan simpan job_id
      $job = ProcessZipJob::dispatch($tempPath, Auth::user()->id, $uploadId)
        ->onQueue('uploads');

      // Update metadata dengan job_id
      $metadata['job_id'] = $job->getJobId();
      Redis::setex(
        "upload:{$uploadId}",
        config('upload.expiration'),
        json_encode($metadata)
      );

      return response()->json([
        'upload_id' => $uploadId,
        'status' => 'uploaded',
        'job_id' => $job->getJobId(),
        'expires_at' => now()->addSeconds(config('upload.expiration'))->toIso8601String(),
      ]);
    } catch (\Exception $e) {
      // ... error handling ...
    }
  }

  public function handle_x(Request $request)
  {
    // return response()->json([
    //   'fufu' => 'fafa',
    //   'path' => sys_get_temp_dir()
    // ]);
    $request->validate([
      'file' => 'required|file',
    ]);

    // dd(sys_get_temp_dir());

    // Generate unique ID
    try {
      $uploadId = 'native_' . Str::random(16);
      $tempPath = $this->getTempPath($uploadId);

      // dd($tempPath);

      // Stream ke disk
      $this->streamToDisk($request, $tempPath);

      // Simpan metadata ke Redis
      $this->storeMetadata($uploadId, $tempPath);

      // dd('aa');
      Log::error("Native upload success", ['message' => 'fufufafa']);

      return response()->json([
        'upload_id' => $uploadId,
        'status' => 'uploaded',
        'expires_at' => now()->addSeconds(config('upload.expiration'))->toIso8601String(),
      ]);
    } catch (\Exception $e) {
      Log::error("Native upload error", ['message' => $e->getMessage()]);
      return response()->json(['error' => $e->getMessage()], 500);
    }
  }

  private function streamToDisk(Request $request, string $path)
  {
    $handle = fopen($path, 'wb');
    if (!$handle) {
      throw new \RuntimeException("Cannot create temp file");
    }

    try {
      $input = fopen('php://input', 'rb');
      while (!feof($input)) {
        $chunk = fread($input, config('upload.chunk_size', 1048576));
        if ($chunk === false) break;
        fwrite($handle, $chunk);
      }
      fclose($input);
      fclose($handle);

      // Validasi file
      $this->validateFile($path);
    } catch (\Exception $e) {
      @unlink($path);
      throw $e;
    }
  }

  private function validateFile(string $path)
  {
    $size = filesize($path);
    if ($size > config('upload.max_size')) {
      throw new \RuntimeException("File too large");
    }

    // Minimal ZIP header check
    $header = file_get_contents($path, false, null, 0, 4);
    if (!in_array($header, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
      throw new \RuntimeException("Invalid ZIP file");
    }
  }

  private function storeMetadata(string $uploadId, string $path)
  {
    $metadata = [
      'path' => $path,
      'created_at' => now()->timestamp,
      'expires_at' => now()->addSeconds(config('upload.expiration'))->timestamp,
    ];

    Redis::setex(
      "upload:{$uploadId}",
      config('upload.expiration'),
      json_encode($metadata)
    );
  }

  private function getTempPath(string $uploadId): string
  {
    $root = config('upload.driver.native.root');
    return "{$root}/{$uploadId}.tmp";
  }
}

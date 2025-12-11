<?php

namespace Dochub\Controller;

use Dochub\Job\FileUploadProcessJob;
use Dochub\Job\ZipProcessJob;
use Dochub\Job\UploadCleanupJob;
use Dochub\Upload\Cache\NativeCache;
use Dochub\Workspace\Models\Manifest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;

// jenis jenis status
// uploading, processing, uploaded => di controller
// processing, completed, failed => di process zip job

/**
 * Catatan Penting untuk Produksi
 * Pastikan php://input tersedia
 * Nonaktif di enable_post_data_reading = Off
 * Tapi Laravel default: tersedia
 * 
 * PERBAIKAN
 * selanjutnya tambahakn lock file ke setiap uploaded chunk (chunkId) 
 * ketika frontend retry upload, file tidak di overwrite. Bisa jadi retry karena request time out tapi server berjalan pararel 
 */
class UploadNativeController extends UploadController
{
  protected NativeCache $cache;

  public function __construct()
  {
    $this->cache = new NativeCache();
  }

  public function checkChunk(Request $request)
  {
    $uploadId = $request->header('X-Upload-ID');
    $chunkId = $request->header('X-Chunk-ID');

    if ($this->isChunkHasUploaded($uploadId, $chunkId)) {
      return response(null, 304);
    } else {
      return response(null, 404);
    }
  }

  /**
   * POST /upload/chunk
   * Terima chunk dengan streaming I/O (low memory)
   */
  public function uploadChunk(Request $request)
  {
    // 🔑 Validasi manual (hindari load ke memory)
    $this->validateChunkHeaders($request);

    $uploadId = $request->header('X-Upload-Id');
    $chunkId = $request->header('X-Chunk-Id');
    $chunkIndex = (int) $request->header('X-Chunk-Index');
    // $chunkSize = (int) $request->header('X-Chunk-Size');    
    $totalChunks = (int) $request->header('X-Total-Chunks');
    $fileName = $request->header('X-File-Name');
    $fileSize = (int) $request->header('X-File-Size');

    // Validasi dasar
    if (!$uploadId || $chunkIndex < 0 || $totalChunks <= 0) {
      return response()->json(['error' => 'Invalid headers'], 400);
    }

    // nanti validasi apakah di disk sudah ada atau belum

    // Buat direktori upload
    $driverUpload = config('upload.driver') === 'tus' ? 'tus' : 'native'; // walau auto adalah file
    $uploadDir = config("upload.driver.{$driverUpload}.root") . "/{$uploadId}";
    if (!is_dir($uploadDir)) {
      mkdir($uploadDir, 0755, true);
    }

    // 🔑 Simpan chunk dengan streaming pakai tmp path agar tidak corrupt
    $unique = uniqid();
    $chunkPathTmp = "{$uploadDir}/{$fileName}.part{$chunkIndex}_{$unique}.tmp";
    $success = $this->streamToDisk($chunkPathTmp);

    if (!$success) {
      return response()->json(['error' => 'Failed to save chunk'], 500);
    }

    // change to final path
    $chunkPath = "{$uploadDir}/{$fileName}.part{$chunkIndex}";
    if ($success) rename($chunkPathTmp, $chunkPath);

    $this->updateUploadMetadata($uploadId, $chunkId, [
      'upload_id' => $uploadId,
      'file_name' => $fileName,
      'file_size' => $fileSize,
      'total_chunks' => $totalChunks,
      'last_chunk' => $chunkIndex,
      'status' => 'uploading'
    ]);

    return response()->json([
      'chunk_index' => $chunkIndex,
      'status' => 'uploaded',
      'size' => filesize($chunkPath),
      // 'metadata' => json_decode($metadata) // untuk dump saja
    ]);
  }

  /**
   * POST /upload/process
   * Gabung chunk & proses
   */
  public function processUpload(Request $request)
  {
    // untuk debug
    // return response()->json([
    //   'upload_id' => 'fufafa',
    //   'job_id' => '0',
    //   'status' => 'processing',
    // ]);
    $request->validate([
      'upload_id' => 'required|string',
      'file_name' => 'required|string',
    ]);

    $uploadId = $request->upload_id;

    // Cek metadata
    $data = $this->cache->getArray($uploadId);
    if (count($data) < 1) {
      return response()->json(['error' => 'Upload not found'], 404);
    }

    // validasi apakah chunk sudah selesai di upload semua
    if (!$this->check_progress($data)) {
      return response()->json(['error' => 'Chunk is not completely uploaded'], 422); // uncompressable content
    }

    // set all request param to metadata    
    $data['tags'] = $request->get('tags') ?? null;

    // Gabung chunk
    $driverUpload = config('upload.driver') === 'tus' ? 'tus' : 'native'; // walau auto adalah file
    $uploadDir = config("upload.driver.{$driverUpload}.root") . "/{$uploadId}";
    $data['upload_dir'] = $uploadDir;

    // Update metadata
    $data['job_id'] = 0;
    $data['status'] = 'processing';

    // $filePath = $data['upload_dir'] . "/" . $data['file_name']; // sama seperti di FileUploadProcessJob.php
    $filesize = (int) $data["file_size"];
    // jika lebih dari 1 mb maka pakai worker
    if ($filesize > (1 * 1024 * 1024)) {
      $job = FileUploadProcessJob::withId(json_encode($data), Auth::user()->id); // file di upload dengan namepsace "upload/{$subpath}", bukan "workspace" di File model
      dispatch($job)->onQueue('uploads');
      $jobId = $job->id;
    } else {
      // ZipProcessJob::dispatchSync(json_encode($data), Auth::user()->id);
      FileUploadProcessJob::dispatchSync(json_encode($data), Auth::user()->id);
      // set job id to metadata
      $data = $this->cache->getArray($uploadId);
      $data["job_id"] = 0;
      // save metadata to cache
      $this->cache->set($uploadId, $data);
    }

    // Dispatch job untuk prodcution
    // $job = ZipProcessJob::withId(json_encode($data), Auth::user()->id);
    // $job = FileUploadProcessJob::withId(json_encode($data), Auth::user()->id); // file di upload dengan namepsace "upload/{$subpath}", bukan "workspace" di File model
    // dispatch($job)->onQueue('uploads');
    // $jobId = $job->id;
    // untuk debug
    // try {
    //   // ZipProcessJob::dispatchSync(json_encode($data), Auth::user()->id);
    //   FileUploadProcessJob::dispatchSync(json_encode($data), Auth::user()->id);
    //   // set job id to metadata
    //   $data = $this->cache->getArray($uploadId);
    //   $data["job_id"] = 0;
    //   // save metadata to cache
    //   $this->cache->set($uploadId, $data);
    // } catch (\Throwable $th) {
    //   dd($th);
    //   return;
    // }

    return response()->json([
      'upload_id' => $uploadId,
      'job_id' => $jobId ?? $data['job_id'],
      // 'job_uuid' => $jobUuId ?? '', // jika perlu uuid
      'status' => 'processing',
    ]);
  }

  public function tesCheckChunk(Request $request, string $uploadId, string $chunkId)
  {
    $metadata = $this->cache->getArray($uploadId);
    dd($metadata, $this->cache->driver());
    dd($id, $chunkId);
  }
  public function tesJob()
  {
    // $job = new ZipProcessJob('', Auth::user()->id, 1);
    // dispatch($job->onQueue('uploads'));
    // $job = ZipProcessJob::dispatch('fufufafa')->onQueue('uploads');
    // // $job = ZipProcessJob::dispatch('fufufafa');
    // $job = new ZipProcessJob('asa', Auth::user()->id, 1);
    // Event::listen(JobQueued::class, function (JobQueued $event) use (&$jobId) {
    //   $jobId = $event->id;
    // });
    // dispatch($job)->onQueue('uploads');
    // $job = ZipProcessJob::dispatchWithId('', Auth::user()->id, 1)->onQueue('uploads');

    // $job = ZipProcessJob::withId('', Auth::user()->id, 1);
    // $pending = dispatch($job)->onQueue('uploads');
    // dd($job);
    $job = ZipProcessJob::withId('', Auth::id(), 1);
    dispatch($job);
    dd($job->id); // selalu berisi, baik chaining ataupun tidak
    // dd($job, $job->getJob()->id);
  }

  private function check_progress(array $metadata): bool
  {
    return ($metadata['total_chunks'] ? ($metadata['uploaded_chunks'] ?? 0) / $metadata['total_chunks'] : 0) >= 1.0;
  }

  /**
   * GET /upload/{id}/status
   * 
   * 🔑 Tambahkan auto-cleanup jika sudah selesai
   */
  public function getUploadStatus(string $id)
  {
    // Coba Redis dulu (prioritas tinggi)
    // $cacheDriver = 'redis';
    // $metadata = \Illuminate\Support\Facades\Redis::get($id);

    // Jika tidak ada di Redis, coba cache
    $data = $this->cache->getArray($id);

    if (count($data) < 1) {
      return response()->json(['error' => 'Upload not found'], 404);
    }

    if (!$this->check_progress($data)) {
      return response()->json(['error' => 'Chunk is not completely uploaded'], 422); // uncompressable content
    }

    // 🔑 Auto-cleanup dari cache jika sudah selesai
    // $cleaned = true; // untuk debut, sehingga tidak dihapus (overwrite)
    $cleaned = $this->cache->cleanupIfCompleted($id, $data);

    if ($cleaned) {
      return response()->json([
        'file_name' => $data['file_name'],
        'job_id' => $data['job_id'],
        'status' => $data['status'],
        'file_size' => $data['file_size'],
        'total_chunks' => $data['total_chunks'],
        'message' => 'Upload completed and cleaned up'
      ]);
    }

    // Return status normal
    return response()->json([
      'id' => $id,
      'status' => $data['status'] ?? 'uploaded',
      // 'status' => 'processing', // untuk debug saja
      'file_name' => $data['file_name'] ?? null,
      'file_size' => $data['file_size'] ?? 0,
      'total_chunks' => $data['total_chunks'] ?? 0,
      'uploaded_chunks' => $data['uploaded_chunks'] ?? 0,
      'job_id' => $data['job_id'] ?? null,
      'progress' => $data['total_chunks'] ?
        round(($data['uploaded_chunks'] / $data['total_chunks']) * 100, 2) : 0,
    ]);
  }

  // private function isValidZip(string $path): bool
  // {
  //   if (!file_exists($path) || filesize($path) < 22) return false;

  //   $header = file_get_contents($path, false, null, 0, 4);
  //   return in_array($header, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
  // }

  private function isChunkHasUploaded(string $uploadId, $chunkId): bool
  {
    $metadata = $this->cache->getArray($uploadId);
    return ((isset($metadata['chunks_id']) && in_array($chunkId, $metadata['chunks_id'])));
  }

  /**
   * Update metadata upload
   */
  private function updateUploadMetadata(string $uploadId, string $chunkId, array $data): void
  {
    $metadata = $this->cache->getArray($uploadId) ?? [];

    $uploadedChunk = 0;

    // set chunk
    $metadata['chunks_id'] = isset($metadata['chunks_id']) ? $metadata['chunks_id'] : [];
    if (!in_array($chunkId, $metadata['chunks_id'])) {
      $metadata['chunks_id'][] = $chunkId;
      $uploadedChunk = 1;
    }

    $updated = array_merge($metadata, $data, [
      'uploaded_chunks' => ($metadata['uploaded_chunks'] ?? 0) + $uploadedChunk,
      'updated_at' => now()->timestamp,
    ]);

    // 🔑 TTL dinamis berdasarkan , agar ke hapus otomatis
    $ttl = env('upload.cache.ttl', 86400); // 24jam

    // // jika sudah bernial $isDone = 1;
    $isDone = $updated['uploaded_chunks'] / $updated['total_chunks'];
    $metadata['status'] = $isDone >= 1.0 ? 'uploaded' : 'uploading'; // uploading, processing, uploaded

    // Jika upload sudah 100% dan ada job_id → perpendek TTL
    $progress = $updated['total_chunks'] ?
      ($updated['uploaded_chunks'] / $updated['total_chunks']) : 0;
    // $progress = $updated['total_chunks'] ? ($isDone) : 0;


    if ($progress >= 1.0 && isset($updated['job_id'])) {
      $ttl = 300; // 5 menit
    }
    $this->cache->set($uploadId, json_encode($updated), $ttl);
  }

  /**
   * 🔑 Streaming write ke disk (low memory)
   */
  private function streamToDisk(string $path): bool
  {
    $input = fopen('php://input', 'rb');
    if (!$input) {
      return false;
    }

    $output = fopen($path, 'wb');
    if (!$output) {
      fclose($input);
      return false;
    }

    try {
      // Streaming copy dengan buffer kecil
      $bufferSize = 8192; // 8 KB
      while (!feof($input)) {
        $chunk = fread($input, $bufferSize);
        if ($chunk === false) break;
        fwrite($output, $chunk);
      }

      fclose($input);
      fclose($output);
      return true;
    } catch (\Exception $e) {
      fclose($input);
      fclose($output);
      @unlink($path);
      throw $e;
    }
  }

  /**
   * Validasi header tanpa load file ke memory
   */
  private function validateChunkHeaders(Request $request): void
  {
    // Cek content type

    $contentType = $request->header('Content-Type', '');
    if (
      !str_contains($contentType, 'multipart/form-data') &&
      !str_contains($contentType, 'application/octet-stream')
    ) {
      throw new \InvalidArgumentException('Invalid Content-Type');
    }
    // check chunkId
    $chunkId = $request->header('X-Chunk-Id');
    if ($chunkId === 'null' || $chunkId === '0' || $chunkId === '' || !$chunkId) {
      throw new \InvalidArgumentException('Chunk id must be provided');
    }

    // Cek content length
    $contentLength = (int) $request->header('Content-Length', 0);
    $maxChunkSize = config('upload.chunk_size', 10 * 1024 * 1024); // 10 MB

    if ($contentLength > $maxChunkSize) {
      throw new \InvalidArgumentException('Chunk too large');
    }

    // if (!in_array(pathinfo($fileName, PATHINFO_EXTENSION), ['zip', 'tar', 'gz'])) {
    //   throw new \InvalidArgumentException('Invalid file type');
    // }
  }

  // Monitoring Cleanup
  // Di controller admin
  public function getUploadStats()
  {
    $pattern = 'native_*';
    $cursor = 0;
    $stats = [
      'total' => 0,
      'uploading' => 0,
      'processing' => 0,
      'completed' => 0,
      'expired' => 0,
    ];

    do {
      [$cursor, $keys] = Redis::scan($cursor, 'MATCH', $pattern, 'COUNT', 1000);
      foreach ($keys as $key) {
        $metadata = Redis::get($key);
        if ($metadata) {
          $data = json_decode($metadata, true);
          $stats['total']++;

          $progress = $data['total_chunks'] ?
            ($data['uploaded_chunks'] / $data['total_chunks']) : 0;

          if ($progress < 1.0) {
            $stats['uploading']++;
          } elseif (isset($data['job_id'])) {
            $stats['processing']++;
          } else {
            $stats['completed']++;
          }
        } else {
          $stats['expired']++;
        }
      }
    } while ($cursor !== 0);

    return response()->json($stats);
  }
}

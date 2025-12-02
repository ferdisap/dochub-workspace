<?php

namespace Dochub\Controller;

use Dochub\Job\ProcessZipJob;
use Dochub\Job\UploadCleanupJob;
use Dochub\Upload\Cache\NativeCache;
use Dochub\Workspace\Models\Manifest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;

/**
 * Catatan Penting untuk Produksi
 * Pastikan php://input tersedia
 * Nonaktif di enable_post_data_reading = Off
 * Tapi Laravel default: tersedia
 */
class UploadNativeController extends UploadController
{
  // protected $cacheDriver = 'file';
  protected NativeCache $cache;

  public function __construct()
  {
    $this->cache = new NativeCache();
  }

  /** @deprecated */
  public function checkUpload(Request $request)
  {
    $uploadId = $request->header('X-Upload-ID');
    $metadata = $this->cache->get($uploadId);

    if($metadata && isset($metadata['status'])){
      // uploading, processing, uploaded
      // processing, completed, failed
      if($metadata['status'] === 'uploaded'){
        return response(null,304); // artinya sudah ada file dengan uploadId, bukan chunkId
      } 
      elseif($metadata['status'] === 'processing'){
        return response(null,202); // artinya masih di process. 
      }
      else {
        return response(null,102); // artinya uploadan (zip) masih di sedang upload oleh client. Hati2 dengan net::ERR_EMPTY_RESPONS
      }
    }
    return response(null,404); // artinya tidak ada data di cache dengan uploadId
  }

  public function checkChunk(Request $request)
  {
    $uploadId = $request->header('X-Upload-ID');
    $chunkId = $request->header('X-Chunk-ID');

    if($this->isChunkHasUploaded($uploadId, $chunkId)){
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

    // tes apakah chunk sudah di upload 

    // Validasi dasar
    if (!$uploadId || $chunkIndex < 0 || $totalChunks <= 0) {
      return response()->json(['error' => 'Invalid headers'], 400);
    }

    // Buat direktori upload
    $uploadDir = config('upload.driver.native.root') . "/{$uploadId}";
    if (!is_dir($uploadDir)) {
      mkdir($uploadDir, 0755, true);
    }

    // return response(['dd' => file_exists($uploadDir)],500); // true

    // 🔑 Simpan chunk dengan streaming
    $chunkPath = "{$uploadDir}/{$fileName}.part{$chunkIndex}";
    $success = $this->streamToDisk($chunkPath);

    if (!$success) {
      return response()->json(['error' => 'Failed to save chunk'], 500);
    }

    $this->updateUploadMetadata($uploadId, $chunkId, [
      'upload_id' => $uploadId,
      'file_name' => $fileName,
      'file_size' => $fileSize,
      'total_chunks' => $totalChunks,
      'last_chunk' => $chunkIndex,
      'status' => 'uploading'
    ]);

    // $metadata = $this->cache->get($uploadId);
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
    $request->validate([
      'upload_id' => 'required|string',
      'file_name' => 'required|string',
    ]);

    $uploadId = $request->upload_id;
    
    // check wheter the uploadId hash in place or not
    // $metadata = $this->cache->get($uploadId);
    // if($metadata) $metadata = json_decode($metadata, true);
    // $uploadDir = $metadata['upload_dir'];
    // $fileName = $metadata['file_name'];
    // $filePath = $uploadDir . "/" . $fileName;

    // try{
    //   if(!($final = fopen($filePath, 'wb'))){
    //     dd('fufuafa');
    //   }
    //   fclose($final);
    // } catch(\Throwable $th){
    //   // dd('fufuafa', $th);
    // }

    // Cek metadata
    $metadata = $this->cache->get($uploadId);
    if (!$metadata) {
      return response()->json(['error' => 'Upload not found'], 404);
    }

    $data = json_decode($metadata, true);

    // Gabung chunk
    $uploadDir = config('upload.driver.native.root') . "/{$uploadId}";
    $data['upload_dir'] = $uploadDir;

    // Dispatch job
    // $job = ProcessZipJob::withId(json_encode($data), Auth::user()->id);
    // dispatch($job)->onQueue('uploads');
    // $jobId = $job->id;
    $jobId = 0;
    try {
      ProcessZipJob::dispatchSync(json_encode($data), Auth::user()->id);
      $jobId = 0;
    } catch (\Throwable $th) {
      dd($th);
      return;
    }

    // Update metadata
    $data['job_id'] = $jobId;
    $data['status'] = 'processing';
    // Redis::setex($uploadId, 3600, json_encode($data));
    $this->cache->set($uploadId, json_encode($data), 3600);

    // cleanup
    // dispatch(new UploadCleanupJob())->onQueue('cleanup')->delay(now()->addSeconds(30));

    return response()->json([
      'upload_id' => $uploadId,
      'job_id' => $jobId,
      'status' => 'processing',
    ]);
  }

  public function tesCheckChunk(Request $request, string $uploadId, string $chunkId){
    $metadata = $this->cache->get($uploadId);
    $metadata = json_decode($metadata, true);
    // dd($this->cache->set('fasa0a-a=ufu', 'fafa:asz'));
    // dd($this->cache->keys());
    dd($metadata, $this->cache->driver());
    dd($id, $chunkId);
  }
  public function tesJob()
  {
    // $job = new ProcessZipJob('', Auth::user()->id, 1);
    // dispatch($job->onQueue('uploads'));
    // $job = ProcessZipJob::dispatch('fufufafa')->onQueue('uploads');
    // // $job = ProcessZipJob::dispatch('fufufafa');
    // $job = new ProcessZipJob('asa', Auth::user()->id, 1);
    // Event::listen(JobQueued::class, function (JobQueued $event) use (&$jobId) {
    //   $jobId = $event->id;
    // });
    // dispatch($job)->onQueue('uploads');
    // $job = ProcessZipJob::dispatchWithId('', Auth::user()->id, 1)->onQueue('uploads');

    // $job = ProcessZipJob::withId('', Auth::user()->id, 1);
    // $pending = dispatch($job)->onQueue('uploads');
    // dd($job);
    $job = ProcessZipJob::withId('', Auth::id(), 1);
    dispatch($job);
    dd($job->id); // selalu berisi, baik chaining ataupun tidak
    // dd($job, $job->getJob()->id);
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
    $metadata = $this->cache->get($id);

    if (!$metadata) {
      return response()->json(['error' => 'Upload not found'], 404);
    }

    $data = json_decode($metadata, true);

    // // 🔑 Auto-cleanup dari cache jika sudah selesai
    // $wasCleaned = false;
    $wasCleaned = $this->cache->cleanupIfCompleted($id, $data);

    // // Cek Redis dulu
    // if ($this->cache->driver() === 'redis') {
    // $wasCleaned = RedisCleanup::cleanupIfCompleted($id, $data);
    // }
    // Jika tidak ada di Redis, cek cache
    // else {
    // $wasCleaned = CacheCleanup::cleanupIfCompleted($id, $data);
    // }

    if ($wasCleaned) {
      return response()->json([
        'id' => $id,
        'status' => 'cleaned_up',
        'message' => 'Upload completed and cleaned up'
      ]);
    }

    // Return status normal
    return response()->json([
      'id' => $id,
      'status' => $data['status'] ?? 'uploaded',
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

  private function isChunkHasUploaded(string $uploadId, $chunkId) :bool
  {
    $metadataJson = $this->cache->get($uploadId);
    $metadata = json_decode($metadataJson, true);
    return ((isset($metadata['chunks_id']) && in_array($chunkId, $metadata['chunks_id'])));
  }

  /**
   * Update metadata upload
   */
  private function updateUploadMetadata(string $uploadId, string $chunkId, array $data): void
  {
    // $metadataJson = $this->cache->get($uploadId);
    // $metadata = $metadataJson ? json_decode($metadataJson, true) : [];
    $metadata = $this->cache->get($uploadId);
    $existing = $metadata ? json_decode($metadata, true) : [];

    // set chunk
    $existing['chunks_id'] = isset($existing['chunks_id']) ? $existing['chunks_id'] : [];
    if(!in_array($chunkId, $existing['chunks_id'])) $existing['chunks_id'][] = $chunkId;


    $updated = array_merge($existing, $data, [
      'uploaded_chunks' => ($existing['uploaded_chunks'] ?? 0) + 1,
      'updated_at' => now()->timestamp,
    ]);

    // 🔑 TTL dinamis berdasarkan , agar ke hapus otomatis
    $ttl = env('upload.cache.ttl', 86400); // 24jam

    // // jika sudah bernial $isDone = 1;
    $isDone = $updated['uploaded_chunks'] / $updated['total_chunks'];
    $existing['status'] = $isDone >= 1.0 ? 'uploaded' : 'uploading'; // uploading, processing, uploaded

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
    if($chunkId === 'null' || $chunkId === '0' || $chunkId === '' || !$chunkId){
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

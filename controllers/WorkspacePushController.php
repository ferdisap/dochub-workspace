<?php

namespace Dochub\Controller;

use Dochub\Encryption\EncryptStatic;
use Dochub\Job\FileToBlobJob;
use Dochub\Workspace\Models\Blob;
use Dochub\Workspace\Models\Manifest;
use Dochub\Workspace\Models\Merge;
use Dochub\Workspace\Models\MergeSession;
use Dochub\Workspace\Models\Workspace;
use Dochub\Workspace\Workspace as DochubWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule; // Import the Rule facade

class WorkspacePushController extends UploadNativeController
{
  // get
  private function get_source_file_processed(string $targetManifestHash)
  {
    return $this->cache->getArray("source_file_processed_{$targetManifestHash}");
  }
  private function get_target_manifest(string $targetManifestHash)
  {
    return $this->cache->getArray("target_manifest_{$targetManifestHash}");
  }
  private function get_source_manifest(string $sourceManifestHash)
  {
    return $this->cache->getArray("source_manifest_{$sourceManifestHash}");
  }
  // set
  private function set_source_file_processed(string $targetManifestHash, array $processedFiles)
  {
    $this->cache->set("source_file_processed_{$targetManifestHash}", json_encode($processedFiles)); // save list of processedFiles dari client
  }
  private function set_target_manifest(string $targetManifestHash, array $targetManifestArray)
  {
    $this->cache->set("target_manifest_{$targetManifestHash}", json_encode($targetManifestArray)); // save source manifest
  }
  private function set_source_manifest(string $sourceManifestHash, array $sourceManifestArray)
  {
    $this->cache->set("source_manifest_{$sourceManifestHash}", json_encode($sourceManifestArray)); // save source manifest 
  }

  // $sourceManifest adalah existing workspace! (lama)
  // $targetManifest adalah manifest milik client (baru)
  // server menyimpan manifest target. 
  public function init(Request $request)
  {
    $targetManifest = $request->input('target_manifest');
    $targetManifestArray = json_decode($targetManifest, true);
    $targetManifestHash = $targetManifestArray['hash_tree_sha256'];
    $processedFiles = [];
    foreach ($targetManifestArray['files'] as $file) {
      $blobHash = $file['sha256'];
      if (Blob::where('hash', $blobHash)->count() < 1) {
        $processedFiles[] = $file;
      }
    }

    $sourceManifestHash = $request->header('X-Source-Manifest-Hash');
    $sourceManifestModel = Manifest::where('hash_tree_sha256', $sourceManifestHash)->where('from_id', $request->user()->id)->first();
    $sourceManifestArray = $sourceManifestModel->content;

    // save list file will processed
    $this->set_source_file_processed($targetManifest, $processedFiles);
    // save manifest to cache (File)
    $this->set_target_manifest($targetManifestHash, $targetManifestArray);
    $this->set_source_manifest($sourceManifestHash, $sourceManifestArray);

    return response()->json(null, 200);
  }

  public function checkChunk(Request $request)
  {
    // $sourceManifestHash = $request->header('X-Source-Manifest-Hash'); // hash_blob
    // $sourceManifestArray = $this->get_target_manifest($sourceManifestHash);

    $targetManifestHash = $request->header('X-Target-Manifest-Hash');
    $processedFiles = $this->get_source_file_processed($targetManifestHash);
    if (count($processedFiles) < 1) abort(500); // server error, karena tidak ada file yang perlu di processed

    $hash = $request->header('X-File-Hash'); // hash_blob
    if (!in_array($hash, $processedFiles, true)) abort(403); // forbidden

    $uploadId = $request->header('X-Upload-ID');
    $chunkId = $request->header('X-Chunk-ID');
    $hash = $request->header('X-File-Hash'); // hash_blob

    if (Blob::where('hash', $hash)->count() > 0) {
      return response(null, 422); // file tidak perlu di upload karena sudah ada di db
    } else if ($this->isChunkHasUploaded($uploadId, $chunkId)) {
      return response(null, 304); // file chunk sudah terupload, misalnya di pause trus ulang lagi
    } else {
      return response(null, 404); // file chunk belum ada, continue upload
    }
  }

  // sama seperti parent, uploadNativeController@uploadChunk
  // public function uploadChunk(Request $request){}

  // proses uploaded file menjadi blob dan nanti dibuatkan manifest, merge, merge_session, files, blobs baru
  public function processUpload(Request $request)
  {
    // sama seperti di uploadNativeController@processUpload
    // validate input
    $request->validate([
      'upload_id' => 'required|string',
      'file_name' => 'required|string',
      'file_mtime' => 'required|integer|before_or_equal:now', // diambil dari Math.floor(file.lastModified / 1000); di js
    ]);
    // $mtime = Carbon::createFromTimestamp($request->input('file_mtime'));
    // touch($filePath, $mtime->timestamp);

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

    // set mtime
    $data['file_mtime'] = $this->convertToSecondsIfMilliseconds((int) $request->input('file_mtime'));

    // set all request param to metadata    
    $data['tags'] = $request->get('tags') ?? null;

    // Gabung chunk
    $driverUpload = config('upload.driver') === 'tus' ? 'tus' : 'native'; // walau auto adalah file
    $uploadDir = self::getDirectoryUpload($driverUpload, $request->user()->id, $uploadId);
    $data['upload_dir'] = $uploadDir;

    // Update metadata
    $data['job_id'] = 0;
    $data['status'] = 'processing';

    // $filePath = $data['upload_dir'] . "/" . $data['file_name']; // sama seperti di FileUploadProcessJob.php
    $filesize = (int) $data["file_size"];
    // jika lebih dari 1 mb maka pakai worker
    if ($filesize > (1 * 1024 * 1024)) {
      $job = FileToBlobJob::withId(json_encode($data), (string) (string) $request->user()->id); // file di upload dengan namepsace "upload/{$subpath}", bukan "workspace" di File model
      dispatch($job)->onQueue('uploads');
      $jobId = $job->id;
    } else {
      try {
        FileToBlobJob::dispatchSync(json_encode($data), (string) (string) $request->user()->id);
        // set job id to metadata
        $data = $this->cache->getArray($uploadId);
        $data["job_id"] = 0;
        // save metadata to cache
        $this->cache->set($uploadId, $data);
      } catch (\Throwable $th) {
        $this->cache->cleanupUpload($uploadId, $data);
        throw $th;
      }
    }
    return response()->json([
      'upload_id' => $uploadId,
      'job_id' => $jobId ?? $data['job_id'],
      // 'job_uuid' => $jobUuId ?? '', // jika perlu uuid
      'status' => 'processing',
    ]);
  }

  // jika status processFile() sudah selesai / belum
  // sama seperti parent @getUploadStatus, jika sudah maka lanjut upload file selanjutnya 
  // public function statusFile(string $uploadId){}

  // jika semua file sudah di process, baru di sini akan dibuatkan manifest/merge/file yang final dan update merge_session.
  public function processPush(Request $request) 
  {
    $sourceManifestHash = $request->header('X-Source-Manifest-Hash');
    $targetManifestHash = $request->header('X-Target-Manifest-Hash');
    // #1. check semua file yang di upload berdasarkan processedFile, apakah sudah ada blobnya di database dan di localstorage, atau belum.
    // #2. jika sudah ada semua maka ambil setiap relative path di processedFile. Lalu ganti relativePath yang sama di $sourceManifest['files] dengan yang processedFile.
    // #3. buat baru atau ganti source manifest sesaui dengan target manifest.
    // #4. simpan manifest baru, update merge session, buat merge baru.
    // #5. done
  }

  // karena processPush mungkin memakan waktu besar (karena pakai job), jadi cek job status nya di sini
  public function statusPush() 
  {
    // cara kerja mirip seperti parent@getUploadStatus
  }
}

<?php

namespace Dochub\Controller;

use Dochub\Encryption\EncryptStatic;
use Dochub\Job\FileToBlobJob;
use Dochub\Workspace\Manifest as WorkspaceManifest;
use Dochub\Workspace\Models\Blob;
use Dochub\Workspace\Models\File;
use Dochub\Workspace\Models\Manifest;
use Dochub\Workspace\Models\Merge;
use Dochub\Workspace\Models\MergeSession;
use Dochub\Workspace\Models\Workspace;
use Dochub\Workspace\Workspace as DochubWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule; // Import the Rule facade

use function Illuminate\Support\now;

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
  private function get_id(string $targetManifestHash, string $sourceManifestHash)
  {
    return EncryptStatic::hash($targetManifestHash . $sourceManifestHash);
  }
  private function get_metadata($id)
  {
    return $this->cache->getArray($id);
  }
  private function get_request_host(Request $request)
  {
    $origin = $request->header('Origin'); // $origin will be something like "http://localhost:3000" or "https://example.com"
    // To get just the host name from the origin URL:
    return parse_url($origin, PHP_URL_HOST); // 'allowed-domain.com
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
  private function set_metadata(array $data, string $id)
  {
    if (!$id) return;
    $metadata = $this->get_metadata($id);
    $update = array_merge($metadata, $data);
    $this->cache->set($id, json_encode($update));
  }

  /**
   * $sourceManifest adalah existing workspace! (lama)
   * $targetManifest adalah manifest milik client (baru)
   * server menyimpan manifest target. 
   * Yang diperlukan => 
   * 1. $request->input('target_manifest')
   * 2. $request->header('X-Source-Manifest-Hash')
   */
  public function init(Request $request)
  {
    $targetManifest = $request->input('target_manifest');
    $targetManifestArray = json_decode($targetManifest, true);
    $targetManifestHash = $targetManifestArray['hash_tree_sha256'];
    if (Manifest::where('hash_tree_sha256', $targetManifestHash)->where('from_id', $request->user()->id)->count() > 0) {
      return response()->json(['error' => 'Target manifest already exist'], 403); // forbidden
    }
    $processedFiles = [];
    foreach ($targetManifestArray['files'] as $file) {
      $blobHash = $file['sha256'];
      if (Blob::where('hash', $blobHash)->count() < 1) {
        $processedFiles[] = $file;
      }
    }
    if (count($processedFiles) < 1) return response()->json(['error' => 'No need file to be processed'], 500); // server error, karena tidak ada file yang perlu di processed

    $sourceManifestHash = $request->header('X-Source-Manifest-Hash');
    $sourceManifestModel = Manifest::where('hash_tree_sha256', $sourceManifestHash)->where('from_id', $request->user()->id)->first();
    $sourceManifestArray = $sourceManifestModel->content;

    // save list file will processed
    $this->set_source_file_processed($targetManifest, $processedFiles);
    // save manifest to cache (File)
    $this->set_target_manifest($targetManifestHash, $targetManifestArray);
    $this->set_source_manifest($sourceManifestHash, $sourceManifestArray);
    // set metadata
    $pushId = $this->get_id($targetManifestHash, $sourceManifestHash);
    $this->set_metadata(["started_at" => now()->timestamp], $pushId);

    return response()->json([
      "push_id" => $pushId,
      "processed_files" => $processedFiles
    ], 200);
  }

  /**
   * Yang diperlukan =>
   * 1. $request->header('X-Target-Manifest-Hash');
   * 2. $request->header('X-File-Hash');
   * 3. $request->header('X-Upload-Id');
   * 4. $request->header('X-Chunk-Id');
   * 5. $request->header('X-File-Hash');
   */
  public function checkChunk(Request $request)
  {
    // $sourceManifestHash = $request->header('X-Source-Manifest-Hash'); // hash_blob
    // $sourceManifestArray = $this->get_target_manifest($sourceManifestHash);

    $targetManifestHash = $request->header('X-Target-Manifest-Hash');
    $processedFiles = $this->get_source_file_processed($targetManifestHash);

    $hash = $request->header('X-File-Hash'); // hash_blob
    if (!in_array($hash, $processedFiles, true)) return response(null, 403); // forbidden

    $uploadId = $request->header('X-Upload-Id');
    $chunkId = $request->header('X-Chunk-Id');

    if (Blob::where('hash', $hash)->count() > 0) {
      return response(null, 409); // conflicts means content file tidak perlu di upload karena sudah ada di db
    } else if ($this->isChunkHasUploaded($uploadId, $chunkId)) {
      return response(null, 304); // file chunk sudah terupload, misalnya di pause trus ulang lagi
    } else {
      return response(null, 404); // file chunk belum ada, continue upload
    }
  }
  
  /**
   * proses uploaded file menjadi blob dan nanti dibuatkan manifest, merge, merge_session, files, blobs baru
   * sama seperti parent, uploadNativeController@uploadChunk
   * public function uploadChunk(Request $request){}
   * Yang diperlukan => 
   * 1. $request->input('upload_id')
   * 3. $request->input('file_mtime')
   * 4. $request->header('X-Source-Manifest-Hash')
   * 5. $request->header('X-Target-Manifest-Hash')
   */
  public function processUpload(Request $request)
  {
    // sama seperti di uploadNativeController@processUpload
    // validate input
    $request->validate([
      'upload_id' => 'required|string',
      'file_mtime' => 'required|integer|before_or_equal:now', // diambil dari Math.floor(file.lastModified / 1000); di js
    ]);
    // $mtime = Carbon::createFromTimestamp($request->input('file_mtime'));
    // touch($filePath, $mtime->timestamp);

    if (!($uploadId = $request->input('upload_id'))) {
      $sourceManifestHash = $request->header('X-Source-Manifest-Hash');
      $targetManifestHash = $request->header('X-Target-Manifest-Hash');
      if (!($uploadId = $this->get_id($targetManifestHash, $sourceManifestHash))) {
        return response()->json(['error' => 'Invalid headers'], 400);
      }
    }

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
    // $data['tags'] = $request->get('tags') ?? null; // tidak perlu karena belum buat manifest di sini

    // Gabung chunk
    $driverUpload = config('upload.driver') === 'tus' ? 'tus' : 'native'; // walau auto adalah file
    $uploadDir = self::getDirectoryUpload($driverUpload, $request->user()->id, $uploadId);
    $data['upload_dir'] = $uploadDir;

    // Update metadata
    $data['job_id'] = 0;
    $data['status'] = 'processing';

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

  /**
   * jika status processFile() sudah selesai / belum
   * sama seperti parent @getUploadStatus, jika sudah maka lanjut upload file selanjutnya 
   * public function statusFile(string $uploadId){}
   * jika semua file sudah di process, baru di sini akan dibuatkan manifest/merge/file yang final dan update merge_session.
   * Yang diperlukan =>
   * 1. $request->header('X-Source-Manifest-Hash');
   * 2. $request->header('X-Target-Manifest-Hash');
   * 3. $request->input('label');
   * 4. $request->input('tags'); // string
   */
  public function processPush(Request $request)
  {
    $sourceManifestHash = $request->header('X-Source-Manifest-Hash');
    $targetManifestHash = $request->header('X-Target-Manifest-Hash');
    $label = $request->input('label'); // not required
    $tags = $request->input('tags'); // string
    $userId = $request->user()->id;
    $pushId = $this->get_id($targetManifestHash, $sourceManifestHash);
    $metadata = $this->get_metadata($pushId);
    $this->set_metadata(["updated_at" => now()->timestamp, "status" => 'processing'], $pushId);
    // #1. check semua file yang di upload berdasarkan processedFile, apakah sudah ada blobnya di database dan di localstorage, atau belum.
    $processedFiles = $this->get_source_file_processed($targetManifestHash);
    $isProcessedCount = 0;
    foreach ($processedFiles as $file) {
      $fileHash = $file["sha256"];
      if (Blob::where('hash', $fileHash)->count() > 0) {
        $isProcessedCount++;
      }
    }
    if ($isProcessedCount != count($processedFiles)) return response()->json(['error' => 'File processed not complete'], 422);
    // #2. jika sudah ada semua maka ambil setiap relative path di processedFile. Lalu ganti relativePath yang sama di $sourceManifest['files] dengan yang processedFile.
    // karena target manifest (baru) sudah diberikan di awal saat init() maka pakai itu saja
    $targetManifestArray = $this->get_target_manifest($targetManifestHash);
    // #3. buat baru atau ganti source manifest sesaui dengan target manifest.
    if (Manifest::where('hash_tree_sha256', $targetManifestHash)->where('from_id', $userId)->count() > 0) {
      return response()->json(['error' => 'Target manifest already exist'], 403); // forbidden
    }
    $workspaceModel = Manifest::where('hash_tree_sha256', $sourceManifestHash)->where('from_id', $userId)->first();
    if (!$workspaceModel) return response()->json(['error' => 'Source workspace not found'], 404);
    // #4. simpan manifest baru, update merge session, buat merge baru.
    $dhManifest = WorkspaceManifest::create($targetManifestArray);
    $dhManifest->tags = $tags;
    $dhManifest->store();
    // create manifest
    $manifestModel = Manifest::createByWsManifest($dhManifest, $userId, $workspaceModel->id);
    try {
      // create merge
      $mergeModel = Merge::create([
        'workspace_id' => $workspaceModel->id,
        'manifest_hash' => $targetManifestArray['hash_tree_sha256'],
        'label' => $label,
        'merged_at' => now(),
        'message' => 'merge is done by pushing manifest ' . $targetManifestArray['hash_tree_sha256'],
      ]);
      try {
        // create session
        $mergeSessionModel = MergeSession::create([
          'target_workspace_id' => $workspaceModel->id,
          'initiated_by_user_id' => $userId,
          'result_merge_id' => $mergeModel->id,
          'source_identifier' => "push:client-url-" . $this->get_request_host($request), // include file extension
          'source_type' => 'push',
          'started_at' => Carbon::createFromTimestamp($metadata['started_at']),
          // metadata null, berdasarkan db_structure ini bisa dipakai jika ada syncronizing dari third-party
          'status' => 'applied',
          'completed_at' => now(),
        ]);
        $filesModelCreated = [];
        try {
          foreach ($dhManifest->files as $dhFile) {
            $filesModelCreated[] = File::createFromWsFile($dhFile, $userId, $workspaceModel->id, $mergeModel->id);
          }
        } catch (\Throwable $th) {
          foreach ($filesModelCreated as $fm) {
            $fm->delete();
          }
          $mergeSessionModel->delete();
          $mergeModel->delete();
          $manifestModel->delete();
        }
      } catch (\Throwable $th) {
        $mergeModel->delete();
        $manifestModel->delete();
      }
    } catch (\Throwable $th) {
      $manifestModel->delete();
    }
    // create file
    foreach ($dhManifest->files as $dhFile) {
      File::createFromWsFile($dhFile, $userId, $workspaceModel->id, $mergeModel->id);
    }
    // #5. done
    $this->set_metadata(["updated_at" => now()->timestamp, "status" => 'completed'], $pushId);
    return response()->json([
      'push_id' => $pushId,
      'job_id' => 0, // karena tidak ada job jadi diberi 0
      'status' => 'processing',
    ]);
  }

  /**
   * karena processPush mungkin memakan waktu besar (karena pakai job), jadi cek job status nya di sini
   * cara kerja mirip seperti parent@getUploadStatus
   * Yang diperlukan =>
   */
  public function statusPush(Request $request, string $pushId)
  {
    $data = $this->cache->getArray($pushId);

    if (count($data) < 1) {
      return response()->json(['error' => 'Push data not found'], 404);
    }

    // 🔑 Auto-cleanup dari cache jika sudah selesai
    // $cleaned = true; // untuk debut, sehingga tidak dihapus (overwrite)
    $cleaned = $this->cache->cleanupIfCompleted($pushId, $data);

    if ($cleaned) {
      return response()->json([
        'job_id' => $data['job_id'] ?? 0,
        'status' => $data['status'] ?? 'completed',
        'message' => 'Push completed and cleaned up'
      ]);
    }

    return response()->json([
      'status' => $data['status'] ?? 'processing',
      'job_id' => $data['job_id'] ?? null,
      'message' => 'Push still in processing'
    ]);
  }
}

<?php

namespace Dochub\Controller;

use Dochub\Workspace\Blob;
use Dochub\Workspace\Models\Blob as ModelsBlob;
use Dochub\Workspace\Models\Manifest;
use Illuminate\Http\Request;

class FileController
{
  public function getFile(Request $request, ModelsBlob $blob)
  {
    $dhBlob = new Blob();
    $hash = $blob->hash;
    return response()->stream(
      function () use ($hash, $dhBlob) {
        $dhBlob->readStream($hash, function(string $content){
          echo $content;
        });
      },
      200,
      [
        'Content-Type' => $blob->mime_type ?: 'application/octet-stream',
        'Content-Length' => $blob->original_size_bytes, // Ukuran asli, bukan terkompresi!
        'Content-Disposition' => 'inline',
        // 'Content-Disposition' => 'attachment; filename="blob"',
        // 'Content-Disposition' => 'inline; filename="blob"',
        // 🔑 JANGAN tambahkan 'Content-Encoding' kecuali benar-benar streaming compressed
      ]
    );
  }

  public function deleteFile(Request $request, Manifest $manifest, ModelsBlob $blob)
  {
    // validate, jika manifest terhubung ke merges, maka tidak bisa dihapus di sini
    if ($manifest->merge) {
      abort(403, "Forbiden to delete file");
    }

    if (self::deletingFile($manifest, $blob)) {
      return response()->json([
        'blob' => $blob
      ]);
    }
    abort(500, "Failed to delete file");
  }

  public static function deletingFile(Manifest $manifest, ModelsBlob $blob)
  {
    $files = $blob->files;
    $hash = $blob->hash;

    $fileModelDeleted = [];
    if ($files->count() > 0) {
      $dhBlob = new Blob(); // Ws Blob
      // jika ada di storage
      if ($dhBlob->isExist($hash)) {
        // hapus blob model dan manifest model
        if ($blob->delete()) {
          if ($manifest->delete()) {
            // hapus blob storage
            if ($dhBlob->destroy($hash)) {
              // delete model file
              foreach ($files as $fileModel) {
                if ($fileModel->delete()) {
                  $fileModelDeleted[] = $fileModel;
                }
              }
              return true;
            } else {
              // recreate $manifest model deleted
              // Log::info("Failed to delete dhBlob", []);
            }
          } else {
            // recreate $blob model deleted
            // Log::info("Failed to delete manifest model", []);
          }
        } else {
          // recreate file Model deleted here
          // Log::info("Failed to delete blob model", []);
        }
      }
    }
    return false;
  }
}

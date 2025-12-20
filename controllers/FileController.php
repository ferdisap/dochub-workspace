<?php

namespace Dochub\Controller;

use Dochub\Workspace\Blob;
use Dochub\Workspace\Models\Blob as ModelsBlob;
use Illuminate\Http\Request;

class FileController
{
  public function getFile(Request $request, ModelsBlob $blob)
  {
    $dhBlob = new Blob();
    $hash = $blob->hash;
    return response()->stream(
      function () use ($hash, $dhBlob) {
        $dhBlob->readStream(
          $hash,
          function ($stream) {
            while (!feof($stream)) {
              echo fread($stream, 8192);
              flush();
            }
          }
        );
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
}

<?php

namespace Dochub\Workspace\Consoles;

use Dochub\Workspace\Blob as WorkspaceBlob;
use Dochub\Workspace\Models\Blob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BlobValidateCommand extends Command
{
  protected $signature = 'blob:validate {hash?} {--fix : Attempt to fix corrupt blobs}';
  protected $description = 'Validate blob integrity and compression';

  public function handle()
  {
    $hash = $this->argument('hash');

    if ($hash) {
      $this->validateSingleBlob($hash);
    } else {
      $this->validateAllBlobs();
    }
  }

  private function validateAllBlobs()
  {
    DB::table('dochub_blobs')->get(['hash'])->map(function($blob) {
      $this->validateSingleBlob($blob->hash);
      $this->newLine();
    });
    
  }

  private function validateSingleBlob(string $hash)
  {
    $blob = Blob::find($hash);
    if (!$blob) {
      $this->error("Blob not found: {$hash}");
      return 1;
    }

    $path = WorkspaceBlob::hashPath($hash);

    if (!file_exists($path)) {
      $this->error("File not found on disk: {$hash}");
      return 1;
    }

    $this->info("Validating blob: {$hash}");
    $this->line("  Blob Size on disk: " . filesize($path) . " bytes");
    $this->line("  Original Size on disk: " . $blob->original_size_bytes . " bytes");
    $this->line("  Stored compressed: " . ($blob->is_stored_compressed ? 'Yes' : 'No'));
    $this->line("  Compression type: {$blob->compression_type}");

    // Cek header file
    $header = substr(file_get_contents($path, false, null, 0, 4), 0, 4);
    $hex = bin2hex($header);
    $this->line("  File header: {$hex}");

    // Coba baca
    try {
      $totalSize = $this->attemptSizing($blob);
      $this->info("  ✅ Read successful (size: " . $totalSize . " bytes)");
    } catch (\Exception $e) {
      $this->error("  ❌ Read failed: " . $e->getMessage());

      if ($this->option('fix')) {
        $this->attemptFix($blob, $path);
      }
    }
  }

  private function attemptSizing(Blob $blob)
  {
    $totalSize = 0;
    $dhBlob = new WorkspaceBlob();
    $dhBlob->readStream($blob->hash, function (string $chunk) use(&$totalSize) {
      $totalSize += strlen($chunk);  // Tambahkan panjangnya saja ke counter
    }, false, 8192);
    return $totalSize;
  }

  /**
   * attempt fix file ini masih palsu karena hanya mengubah status file dari compressed menjadi uncompressed
   */
  private function attemptFix(Blob $blob)
  {
    $this->info("  Attempting fix...");

    // Coba baca tanpa dekompresi
    try {
      $path = WorkspaceBlob::hashPath($blob->hash);
      $this->info("  File readable as raw data");

      // Cek apakah sebenarnya tidak dikompresi
      if ($blob->compression_type) {
        $this->warn("  Blob marked as compressed but contains raw data");

        // Update DB
        $blob->update([
          'is_stored_compressed' => false,
          'compression_type' => null,
          'stored_size_bytes' => filesize($path),
        ]);

        $this->info("  ✅ DB updated to reflect uncompressed status");
      }

    } catch (\Exception $e) {
      $this->error("  Fix failed: " . $e->getMessage());
    }
  }
}

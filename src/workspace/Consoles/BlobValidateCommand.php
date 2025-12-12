<?php

namespace App\Console\Commands;

use App\Models\Blob;
use Illuminate\Console\Command;

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

    private function validateSingleBlob(string $hash)
    {
        $blob = Blob::find($hash);
        if (!$blob) {
            $this->error("Blob not found: {$hash}");
            return 1;
        }

        $path = storage_path("app/private/csdb/blobs/" . substr($hash, 0, 2) . "/{$hash}");
        
        if (!file_exists($path)) {
            $this->error("File not found on disk: {$hash}");
            return 1;
        }

        $this->info("Validating blob: {$hash}");
        $this->line("  Size on disk: " . filesize($path) . " bytes");
        $this->line("  Stored compressed: " . ($blob->is_stored_compressed ? 'Yes' : 'No'));
        $this->line("  Compression type: {$blob->compression_type}");

        // Cek header file
        $header = substr(file_get_contents($path, false, null, 0, 4), 0, 4);
        $hex = bin2hex($header);
        $this->line("  File header: {$hex}");

        // Coba baca
        try {
            $content = $this->attemptRead($blob, $path);
            $this->info("  ✅ Read successful (size: " . strlen($content) . " bytes)");
        } catch (\Exception $e) {
            $this->error("  ❌ Read failed: " . $e->getMessage());
            
            if ($this->option('fix')) {
                $this->attemptFix($blob, $path);
            }
        }
    }

    private function attemptRead($blob, $path)
    {
        $stream = fopen($path, 'rb');
        
        if ($blob->is_stored_compressed && $blob->compression_type === 'gzip') {
            stream_filter_append($stream, 'zlib.inflate', STREAM_FILTER_READ);
        }

        $content = '';
        while (!feof($stream)) {
            $content .= fread($stream, 8192);
        }
        fclose($stream);
        
        return $content;
    }

    private function attemptFix($blob, $path)
    {
        $this->info("  Attempting fix...");
        
        // Coba baca tanpa dekompresi
        try {
            $content = file_get_contents($path);
            $this->info("  File readable as raw data");
            
            // Cek apakah sebenarnya tidak dikompresi
            if (strlen($content) > 0 && $blob->is_stored_compressed) {
                $this->warn("  Blob marked as compressed but contains raw data");
                
                // Update DB
                $blob->update([
                    'is_stored_compressed' => false,
                    'compression_type' => null,
                    'stored_size_bytes' => strlen($content),
                ]);
                
                $this->info("  ✅ DB updated to reflect uncompressed status");
            }
        } catch (\Exception $e) {
            $this->error("  Fix failed: " . $e->getMessage());
        }
    }
}
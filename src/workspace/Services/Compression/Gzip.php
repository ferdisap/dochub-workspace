<?php

namespace Dochub\Workspace\Services\Compression;

use RuntimeException;

class Gzip implements Contract
{
  public function type()
  {
    return 'gzip';
  }
  /**
   * Kompresi streaming (tanpa memory overhead)
   * @param resource $source
   * @param resource $dest
   * @param array $metadata
   * @throws \RuntimeException
   */
  public function compressStream(string $sourcePath, string $destinantionPath, &$metadata = []): bool
  {
    // $destination adalah random string
    $source = fopen($sourcePath, 'rb');
    $dest = fopen($destinantionPath, 'wb');

    if (!$source || !$dest) {
      fclose($source);
      fclose($dest);
      throw new RuntimeException("Cannot open streams");
    }

    $context = deflate_init(ZLIB_ENCODING_GZIP, [
      'level' => 6,
    ]);

    if ($context === false) {
      throw new \RuntimeException('Gagal inisialisasi deflate context');
    }

    $bytesProcessed = 0;
    $totalCompressed = 0;

    while (!feof($source)) {
      $chunk = fread($source, 65536); // baca hingga 64KB
      if ($chunk === false) {
        throw new \RuntimeException('Gagal membaca dari source stream');
      }
      if ($chunk === '') {
        break; // EOF
      }

      $bytesProcessed += strlen($chunk);

      // Gunakan ZLIB_SYNC_FLUSH untuk chunk terakhir?
      // Tapi ZLIB_FINISH hanya di akhir — jadi akumulasi saja
      $compressed = deflate_add($context, $chunk, feof($source) ? ZLIB_FINISH : ZLIB_NO_FLUSH);

      if ($compressed === false) {
        throw new \RuntimeException('Gagal memproses kompresi chunk');
      }

      if ($compressed !== '') {
        $written = fwrite($dest, $compressed);
        if ($written === false || $written !== strlen($compressed)) {
          throw new \RuntimeException('Gagal menulis ke destination stream');
        }
        $totalCompressed += $written;
      }
    }

    // Pastikan tidak ada sisa (biasanya sudah di-handle di loop atas via ZLIB_FINISH)
    // Tapi amannya: flush akhir jika konteks belum selesai
    $final = deflate_add($context, '', ZLIB_FINISH);
    if ($final !== '' && $final !== false) {
      $written = fwrite($dest, $final);
      if ($written === false || $written !== strlen($final)) {
        throw new \RuntimeException('Gagal menulis final flush');
      }
      $totalCompressed += $written;
    }

    $metadata['original_size_bytes'] = $bytesProcessed;
    // $metadata['compressed_size_bytes'] = $totalCompressed; // tidak wajib karena akan ada juga "stored_size_bytes"
    $metadata['compression_type'] = 'gzip';
    $metadata['compression_ratio'] = $bytesProcessed > 0 ? round($totalCompressed / $bytesProcessed, 4) : 0;

    // 🔑 Perbaikan #2: Validasi hasil kompresi
    $destPath = stream_get_meta_data($dest)['uri'];
    fflush($dest); // Pastikan semua data ditulis ke disk

    return $this->validateGzipFile($destPath);
    // if (!$this->validateGzipFile($destPath)) {
    //   throw new \RuntimeException("Compressed file is corrupt: {$destPath}");
    // }
  }

  /**
   * Kompresi streaming (tanpa memory overhead)
   * Contoh pakai:
   * return response()->stream(
   *   function () use ($hash, $blobLocalStorage) {
   *     $blobLocalStorage->readStream(
   *       $hash,
   *       function ($stream) {
   *         while (!feof($stream)) {
   *           echo fread($stream, 8192);
   *           flush();
   *         }
   *       }
   *     );
   *   }
   * );
   * @param resource $source
   * @param resource $dest
   * @param array $metadata
   * @throws \RuntimeException
   */
  public function decompressStream(string $path, callable $callback): void
  {
    $gz = gzopen($path, 'rb');
    if (!$gz) {
      fclose($gz);
      throw new RuntimeException("Cannot open compressed blob");
    }
    $callback($gz);
    fclose($gz);
  }

  /**
   * @param string $path
   * @return string file contents
   * @throws \RuntimeException
   */
  public function decompressToString(string $path, string &$output): void
  {
    $gz = gzopen($path, 'rb');
    if (!$gz) {
      throw new RuntimeException("Cannot open compressed blob");
    }

    $output = '';
    while (!gzeof($gz)) {
      $chunk = gzread($gz, 8192); // baca 8KB per iterasi
      if ($chunk === false) {
        break;
      }
      $output .= $chunk;
    }

    gzclose($gz);
  }

  /**
   * @param string $path
   * @param string $outputPath
   * @return string file contents
   * @throws \RuntimeException
   */
  public function decompressToFile(string $path, string $outputPath): void
  {
    $gz = gzopen($path, 'rb');
    if (!$gz) {
      throw new RuntimeException("Cannot open compressed blob");
    }

    $txt = fopen($outputPath, 'wb'); // binary write — aman untuk UTF-8/biner
    if (!$txt) {
      gzclose($gz);
      throw new \RuntimeException("Failed to make path: $outputPath");
    }

    $totalBytes = 0;
    while (!gzeof($gz)) {
      $chunk = gzread($gz, 8192);
      if ($chunk === false) {
        break;
      }
      $written = fwrite($txt, $chunk);
      if ($written === false) {
        gzclose($gz);
        fclose($txt);
        throw new \RuntimeException("Failed to write");
      }
      $totalBytes += $written;
    }

    gzclose($gz);
    fclose($txt);
  }


  /**
   * 🔑 Validasi file gzip sebelum disimpan
   */
  public function validateGzipFile(string $path): bool
  {
    $gz = @gzopen($path, 'rb');
    if (!$gz) {
      return false;
    }

    $test = @gzread($gz, 1); // cukup 1 byte decompressed
    $ok = ($test !== false && $test !== '');
    gzclose($gz);

    return $ok;
  }
}

<?php

namespace Dochub\Workspace\Consoles;

use Dochub\Encryption\EncryptStatic;
use Dochub\Workspace\Services\BlobLocalStorage;
use Illuminate\Console\Command;
// use Illuminate\Support\Facades\Artisan;
// use Symfony\Component\Process\Exception\ProcessFailedException;
// use Symfony\Component\Process\Process;


// # Contoh:
// php artisan blob:benchmark 10MB
// php artisan blob:benchmark 100MB --chunk=8192
// php artisan blob:benchmark 1GB --full

/**
 * Untuk ukur kecepatan & RAM usage hashing di servermu (termasuk Raspberry Pi/low-end). 
 */
class BlobBenchmarkCommand extends Command
{
  protected $signature = 'blob:benchmark
                            {size=10MB : Ukuran file uji (e.g., 1MB, 10MB, 100MB, 1GB)}
                            {--chunk=32768 : Chunk size dalam byte}
                            {--full : Lakukan full hash (abaikan partial verify)}';

  protected $description = 'Benchmark hashing performance & memory usage';

  public function handle()
  {
    $sizeStr = $this->argument('size');
    $sizeBytes = $this->parseSize($sizeStr);
    $chunkSize = (int) $this->option('chunk');

    $this->info("📊 Memulai benchmark...");
    $this->line("   File size: " . $this->formatBytes($sizeBytes));
    $this->line("   Chunk size: " . $this->formatBytes($chunkSize));
    $this->line("   Full verify: " . ($this->option('full') ? '✅' : '❌'));
    $this->newLine();

    // Buat file dummy (hanya di memory, tidak simpan ke disk)
    $this->info("🔧 Membuat data dummy...");
    $data = str_repeat('A', min($sizeBytes, 10_000_000)); // max 10 MB di memory
    if ($sizeBytes > 10_000_000) {
      $this->warn("⚠️  File >10MB: menggunakan pola berulang (bukan file fisik)");
    }

    // Simpan sementara ke file (untuk simulasi I/O)
    $tempFile = sys_get_temp_dir() . '/blob_bench_' . uniqid() . '.bin';
    $handle = fopen($tempFile, 'wb');
    $written = 0;
    while ($written < $sizeBytes) {
      $chunk = substr($data, 0, min(1_000_000, $sizeBytes - $written));
      fwrite($handle, $chunk);
      $written += strlen($chunk);
    }
    fclose($handle);

    try {
      // Ukur memory & waktu
      $startMem = memory_get_usage(true);
      $startTime = microtime(true);

      $blobLocalStorage = new BlobLocalStorage();
      // Override chunk size sementara
      $reflection = new \ReflectionClass($blobLocalStorage);
      $prop = $reflection->getProperty('chunkSize');
      // $prop->setAccessible(true); // tidak diperlukan lagi sejak php 8.3 keatas
      $prop->setValue($blobLocalStorage, $chunkSize);

      // Lakukan hash
      if ($this->option('full') || $sizeBytes <= 50_000_000) {
        $hash = EncryptStatic::hashFileFull($tempFile);
      } else {
        // Simulasi partial verify
        $fakeHash = str_repeat('a', 64);
        $blobLocalStorage->verifyPartialHash($tempFile, $fakeHash, $sizeBytes);
        $hash = substr($fakeHash, 0, 8) . '... (partial)';
      }

      $endTime = microtime(true);
      $endMem = memory_get_usage(true);

      $duration = $endTime - $startTime;
      $memUsed = $endMem - $startMem;

      $this->newLine();
      $this->info("✅ Selesai!");
      $this->table(
        ['Metrik', 'Nilai'],
        [
          ['Hash', $hash],
          ['Waktu', sprintf('%.3f detik', $duration)],
          ['RAM (peak)', $this->formatBytes($memUsed)],
          ['Throughput', sprintf('%.2f MB/detik', ($sizeBytes / 1024 / 1024) / $duration)],
        ]
      );
    } finally {
      @unlink($tempFile);
    }
  }

  private function parseSize(string $size): int
  {
    $size = strtoupper($size);
    if (preg_match('/^(\d+)([KMGT]B?)?$/', $size, $m)) {
      $num = (int) $m[1];
      $unit = $m[2] ?? '';
      return match (substr($unit, 0, 1)) {
        'K' => $num * 1024,
        'M' => $num * 1024 * 1024,
        'G' => $num * 1024 * 1024 * 1024,
        'T' => $num * 1024 * 1024 * 1024 * 1024,
        default => $num,
      };
    }
    throw new \InvalidArgumentException("Invalid size: {$size}");
  }

  private function formatBytes(int $bytes): string
  {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 2) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return round($bytes / 1024 / 1024, 2) . ' MB';
    return round($bytes / 1024 / 1024 / 1024, 2) . ' GB';
  }
}

<?php

namespace Dochub\Job;

use Dochub\Upload\Services\RedisCleanup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Dochub\Workspace\Services\BlobLocalStorage as BlobStorage;
use Predis\Client as PredisClient; //
use Illuminate\Support\Facades\App;

class ProcessZipJob implements ShouldQueue
{
  use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

  protected PredisClient $redis;

  public function __construct(
    public string $tempPath,
    public int $userId,
    public ?string $tusUploadKey = null
  ) {
    // Ambil koneksi Redis default (harus dikonfigurasi sebagai 'predis')
    /** @var PredisConnection $connection */
    // $connection = app('redis')->connection();
    $connection = App::make('redis')->connection();

    // Dapatkan client Predis\Client dari connection wrapper
    $this->redis = $connection->client();
  }

  public function handle(BlobStorage $blobStorage)
  {
    // pastikan sudah ada penggabungan chunk (jika pakai native)
    // RedisCleanup::cleanupUpload($this->uploadId, [
    //         'status' => 'completed',
    //         'upload_id' => $this->uploadId,
    //     ]);

    // Lock untuk hindari duplikat
    $lockKey = 'process:' . md5($this->tempPath);
    $lock =     $this->redis->lock($lockKey, 3600);

    if (!$lock->get()) {
      return; // Sudah diproses
    }

    try {
      // Ekstrak ZIP
      $extractDir = sys_get_temp_dir() . '/extract_' . uniqid();
      mkdir($extractDir);

      $zip = new \ZipArchive;
      if ($zip->open($this->tempPath) !== true) {
        throw new \RuntimeException("Cannot open ZIP");
      }
      $zip->extractTo($extractDir);
      $zip->close();

      // Proses file
      $this->processDirectory($extractDir, $blobStorage);

      // Cleanup
      $this->deleteDirectory($extractDir);
      unlink($this->tempPath);

      // Bersihkan metadata Tus
      if ($this->tusUploadKey) {
        app(\TusPhp\Tus\Server::class)->delete($this->tusUploadKey);
            $this->redis->del("tus_upload:{$this->tusUploadKey}");
      }
    } finally {
      $lock->release();
    }
  }

  private function processDirectory(string $dir, BlobStorage $blobStorage)
  {
    $files = $this->scanDirectory($dir);

    foreach ($files as $relativePath => $filePath) {
      if ($this->isDangerousFile($relativePath)) continue;

      $hash = $blobStorage->store($filePath);

      // Simpan ke DB sesuai konteks
      // (implementasi sesuai kebutuhan)
    }
  }

  private function scanDirectory(string $dir): array
  {
    $files = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
      if ($file->isFile()) {
        $relativePath = str_replace($dir . DIRECTORY_SEPARATOR, '', $file->getPathname());
        $files[$relativePath] = $file->getPathname();
      }
    }

    return $files;
  }

  private function isDangerousFile(string $path): bool
  {
    $dangerous = ['.env', '.git', '.php', '.sh', 'composer.json'];
    return collect($dangerous)->contains(fn($ext) => str_ends_with($path, $ext));
  }

  private function deleteDirectory(string $dir)
  {
    if (!is_dir($dir)) return;

    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
      $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
    rmdir($dir);
  }
}

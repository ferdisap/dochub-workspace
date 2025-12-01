<?php

namespace Dochub\Workspace\Services;

use Dochub\Workspace\Manifest;
use Dochub\Workspace\Models\BlobReference;
use RuntimeException;
use Dochub\Workspace\Services\LockManager as ServicesLockManager;
use Dochub\Workspace\Workspace;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

use function Illuminate\Support\now;

class ManifestLocalStorage
{
  public function store(array $manifest): string
  {
    // 1. Generate path berdasarkan waktu
    // $path = 'manifests/' . now()->format('Y/m/') . Str::uuid() . '.json';
    $path = now()->format('Y/m/') . Str::uuid() . '.json';
    $fullPath = Manifest::path() . "/" . $path;

    // 2. Pastikan folder ada
    @mkdir(dirname($fullPath), 0755, true);

    // 3. Simpan konten lengkap ke disk
    file_put_contents($fullPath, json_encode($manifest, JSON_UNESCAPED_SLASHES));

    // 4. Return path relatif
    return $path;
  }

  public function get(string $path): array
  {
    $fullPath = Manifest::path($path);
    if (!file_exists($fullPath)) {
      throw new \RuntimeException("Manifest not found: {$path}");
    }
    return json_decode(file_get_contents($fullPath), true);
  }

  public function delete(string $path): void
  {
    @unlink(Manifest::path($path));
  }
}

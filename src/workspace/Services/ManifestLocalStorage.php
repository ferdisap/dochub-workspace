<?php

namespace Dochub\Workspace\Services;

use Dochub\Workspace\Manifest;
use Dochub\Workspace\Models\BlobReference;
use RuntimeException;
use Dochub\Workspace\Services\LockManager as ServicesLockManager;
use Dochub\Workspace\Workspace;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use function Illuminate\Support\now;

/**
 * jika ada 5600 file sesuai nama DMC, maka manifest bisa 1.3 mb
 * Kedepan akan dibuat read/write dengan schema hash agar bisa hemat disk
 */
class ManifestLocalStorage
{
  /**
   * Fungsi yang menerima array hanya berisi string
   * @param Dochub\Workspace\Manifest $manifest
   * @return string
   */
  public function store($manifest): string
  {
    // 1. Generate path berdasarkan waktu
    // $path = 'manifests/' . now()->format('Y/m/') . Str::uuid() . '.json';
    // $path = now()->format('Y/m/') . Str::uuid() . '.json';
    // $path = Manifest::hashPath($manifest->hash_tree_sha256)now()->format('Y/m/') . Str::uuid() . '.json';
    // $path = Manifest::hashPathRelative($manifest->hash_tree_sha256) . "/manifest.json";
    $path = self::path($manifest->hash_tree_sha256);
    $fullPath = Manifest::path() . "/" . $path;

    // 2. Pastikan folder ada
    @mkdir(dirname($fullPath), 0755, true);
    // $this->makeFile($path);

    // 3. Simpan konten lengkap ke disk
    file_put_contents($fullPath, json_encode($manifest, JSON_UNESCAPED_SLASHES));

    // 4. Return path relatif
    return $path;
  }

  private static function path(string $hash):string
  {
    return Manifest::hashPathRelative($hash) . "/manifest.json";
  }

  public function get(string $path): Manifest
  {
    $fullPath = Manifest::path($path);
    if (!file_exists($fullPath)) {
      throw new \RuntimeException("Manifest not found: {$path}");
    }
    $manifestArray = json_decode(file_get_contents($fullPath), true);
    return Manifest::create($manifestArray);
  }

  public function delete(string $path): void
  {
    @unlink(Manifest::path($path));
  }
}

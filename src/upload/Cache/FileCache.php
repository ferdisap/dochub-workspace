<?php

namespace Dochub\Upload\Cache;

// use Illuminate\Cache\FileStore;
// use Illuminate\Filesystem\Filesystem;

// {timestamp_expired}s:{length}:{serialized_value};
// eg isi file cacenya >>>1764683752s:1560:"mycache_data";<<<
class FileCache
{
  /**
   * Dapatkan keys dari file cache berdasarkan prefix
   * @param string $cacheKey === $this->_prefix . $key
   */
  // public static function keys(string $prefix): array
  public static function keys(string $cacheKey): array
  {
    // $fs = new FileStore(new Filesystem(), config('config.file.path'), null);
    // $cachePath = $fs->path($cacheKey);
    // $fs->key;

    return [];

    $cachePath = config("cache.stores.file.path");
    // $fs = new FileStore(new Filesystem(), config('config.file.path'), null);
    // $cachePath = $fs->path($prefix);
    dd($cachePath);
    // native_44681f558d6c3e6fb51f394f2b6bfd27c3db1acc76d4829eb4cd5b59da2cb3b8
    // 3d8791d105cea9b10ec2a37fed6ad334a1a570e242c42002df20e6946d7e0276
    $keys = [];

    if (!is_dir($cachePath)) return $keys;

    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($cachePath, \RecursiveDirectoryIterator::SKIP_DOTS)
    );

    // Buat regex prefix untuk pencarian, prefix akan di-escape agar aman untuk regex
    $escapedPrefix = preg_quote($prefix, '/');
    $keyPattern = "/'key'\s*=>\s*'(" . $escapedPrefix . "[^']+)'/";

    foreach ($iterator as $file) {
      if ($file->isFile() && $file->getExtension() === 'php') {
        $content = file_get_contents($file->getPathname());

        // Cari semua key yang ada di file ini
        if (preg_match($keyPattern, $content, $matches)) {
          $keys[] = $matches[1];
        }
      }
    }

    return $keys;
  }
}

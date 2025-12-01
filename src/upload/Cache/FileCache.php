<?php

namespace Dochub\Upload\Cache;

class FileCache
{
  /**
   * Dapatkan keys dari file cache berdasarkan prefix
   */
  public static function keys(string $prefix): array
  {
    $cachePath = config("cache.stores.file.path");
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

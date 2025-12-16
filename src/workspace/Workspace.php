<?php

namespace Dochub\Workspace;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;

/**
 * saat instance buat / check path, cek sudah dilakukan di LockServiceProvider untuk Lock
 * 1. /.blobs
 */
class Workspace
{
  public static function path(?string $path = null): string
  {
    $driver = Config::get("workspace.default"); // string
    return Config::get("workspace.disks.{$driver}.root", App::storagePath('workspace')) . ($path ? "/{$path}" : '');
    // $url = parse_url(Config::get("workspace.disks.{$driver}.root", App::storagePath('workspace')) . ($path ? "/{$path}" : ''));
    // $url
  }

  public static function blobPath()
  {
    return self::path("blobs");
  }
  public static function filePath()
  {
    return self::path("files");
  }

  public static function lockPath()
  {
    return self::path("locks");
  }

  public static function manifestPath()
  {
    return self::path("manifests");
  }

  /**
   * Deteksi file berisiko
   */
  public static function isDangerousFile(string $path): bool
  {
    $dangerous = [
      '.env',
      '.git',
      'composer.json',
      'composer.lock',
      '.php',
      '.sh',
      '.bat',
      '.exe',
      'node_modules/',
      'vendor/',
    ];

    foreach ($dangerous as $pattern) {
      if (str_contains($path, $pattern)) {
        return true;
      }
    }
    return false;
  }

  public static function scanDirectory(string $dir): array
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
}

<?php

namespace Dochub\Encryption;

class EncryptStatic
{
  public static function basePath()
  {
    return storage_path("uploads");
  }

  public static function metaJsonPath(string $fileId)
  {
    return static::baseMetaPath() . "/{$fileId}.json";
  }

  public static function baseMetaPath()
  {
    return static::basePath() . "/meta";
  }

  public static function baseChunkPath()
  {
    return static::basePath() . "/chunks";
  }

  public static function chunkPath(string $fileId, int $index)
  {
    return static::baseChunkPath() . "/" . "{$fileId}-index{$index}.bin";
  }

  public static function encryptedName(string $fileId)
  {
    return "{$fileId}.fnc";
  }

  public static function encryptedPath(string $fileId)
  {
    return static::baseChunkPath() . "/" . static::encryptedName($fileId);
  }

  public static function encryptedPathByFilename(string $filename)
  {
    return static::baseChunkPath() . "/" . "{$filename}";
  }

  public static function mergedChunkPath(string $fileId)
  {
    return static::baseChunkPath() . "/{$fileId}.bin";
  }

  public static function encryptedTmpPath(string $fileId)
  {
    return static::encryptedPath($fileId) . ".tmp";
  }

  /**
   * Mengubah string Base64 menjadi string byte mentah.
   * Fungsi ini setara dengan menggunakan atob() dalam JavaScript/TypeScript.
   *
   * @param string $base64String String yang dienkode Base64.
   * @return string String byte biner mentah.
   */
  public static function base64ToBytes(string $base64String): string
  {
    // base64_decode melakukan pekerjaan yang sama persis dengan atob()
    return base64_decode($base64String); // $length = strlen(base64ToBytes($base64String));
  }
}

<?php

namespace Dochub\Workspace\Services\Compression;

interface Contract
{

  // public const RFC_7231 = 'D, d M Y H:i:s \G\M\T';
  // public function compressStream(string $sourcePath, string $destinantionPath, &$metadata = []): bool

  /**
   * @return string of type compression
   */
  public function type();

  /**
   * Kompresi streaming (tanpa memory overhead)
   * @param resource $source
   * @param resource $dest
   * @param array $metadata
   * @throws \RuntimeException
   */
  public function compressStream(string $sourcePath, string $destinantionPath, array &$metadata);

  /**
   * Kompresi streaming (tanpa memory overhead)
   * Contoh pakai:
   * return response()->stream(
   *     function () use ($hash, $compressionType, $blobLocalStorage) {
   *       $blobLocalStorage->withBlobContent(
   *         $hash,
   *         $compressionType,
   *         function ($stream) {
   *           while (!feof($stream)) {
   *             echo fread($stream, 8192);
   *             flush();
   *           }
   *         }
   *       );
   *     }
   *   );
   * @param resource $source
   * @param resource $dest
   * @param array $metadata
   * @throws \RuntimeException
   */
  public function decompressStream(string $path, callable $callback);

  /**
   * @param string $path
   * @return string file contents
   * @throws \RuntimeException
   */
  public function decompressToString(string $path, string &$output);

  /**
   * @param string $path
   * @param string $outputPath
   * @return string file contents
   * @throws \RuntimeException
   */
  public function decompressToFile(string $path, string $outputPath);
}

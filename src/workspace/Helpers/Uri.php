<?php

namespace Dochub\Workspace\Helpers;

class Uri
{
  public static function normalizePath($path)
  {
    // 1. Replace backslashes with forward slashes (for cross-platform compatibility)
    $path = str_replace('\\', '/', $path);

    // 2. Combine multiple slashes into a single slash
    $path = preg_replace('/\\/+/', '/', $path);

    // 3. Process dot-segments (./ and ../)
    $segments = explode('/', $path);
    $normalized_segments = array();

    foreach ($segments as $segment) {
      if ($segment == '.') {
        continue; // Skip single dots
      } elseif ($segment == '..') {
        // Go up one level, unless we are already at the root or a '..' we can't resolve
        if (count($normalized_segments) > 0 && end($normalized_segments) != '..') {
          array_pop($normalized_segments);
        } else {
          // If it's a relative path starting with '..', keep it
          $normalized_segments[] = $segment;
        }
      } else {
        $normalized_segments[] = $segment;
      }
    }

    // 4. Reconstruct the path
    $normalized_path = implode('/', $normalized_segments);

    // Ensure it starts with a slash if it was an absolute path initially
    if (strpos($path, '/') === 0 && strpos($normalized_path, '/') !== 0) {
      $normalized_path = '/' . $normalized_path;
    }

    return $normalized_path;
  }
}

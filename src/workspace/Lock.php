<?php

namespace Dochub\Workspace;

use Illuminate\Support\Facades\Config;

class Lock
{
  public static function driver()
  {
    return Config::get('lock.driver', 'flock');
  }

  /** relative to lock path */
  public static function path(?string $path = null) : string
  {
    return Workspace::lockPath() . ($path ? "/{$path}" : "");
  }
}

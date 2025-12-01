<?php

namespace Dochub\Workspace\Models;

use Dochub\Workspace\Manifest as WorkspaceManifest;
use Dochub\Workspace\Services\ManifestLocalStorage;
use Dochub\Workspace\Services\ManifestSourceParser;
use Dochub\Workspace\Services\ManifestVersionParser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;

// app/Models/DochubManifest.php
// {
//   "source": "...",
//   "version": "...",
//   "total_files": 128,
//   "total_size_bytes": 4567890, // file asli
//   "hash_tree_sha256": "...",
//   "files": [ 
//      {
//        "path": "config/app.php",
//        "sha256": "a1b2c3...",
//        "size": 2048
//      }
//   ]
// }

// Hitung ukuran:
// Per file entry: ~150 byte (path 50 + metadata 100)
// 128 file: 128 × 150 = 19.200 byte
// Header + overhead JSON: ~1.000 byte
// Total: ~20.2 KB per manifest
// 💡 Kesimpulan:
// < 100 file: ~15 KB → aman di JSON column
// 1.000 file: ~150 KB → mulai berat untuk DB
// 10.000+ file: ~1.5 MB → harus di disk

class Manifest extends Model
{
  protected $table = "dochub_manifests";

  protected $fillable = [
    // 'workspace_id',
    'from_id',
    'source',
    'version',
    'total_files',
    'total_size_bytes',
    'hash_tree_sha256',
    'storage_path' // 🔑 pointer ke file disk
  ];

  protected $casts = [
    'total_files' => 'integer',
    'total_size_bytes' => 'integer',
  ];

  protected static function boot()
  {
    parent::boot();

    static::creating(function ($manifest) {
      if (!ManifestSourceParser::isValid($manifest->source)) {
        throw new \InvalidArgumentException(
          "Invalid source format. Use 'type:identifier' (e.g., 'cms:client-a')"
        );
      }
    });

    static::creating(function ($manifest) {
      // Normalisasi ke UTC ISO 8601
      // if ($manifest->version) {
      if (!ManifestVersionParser::isValid($manifest->version)) {
        throw new \InvalidArgumentException("Invalid version timestamp");
      }
    });
  }

  // Accessor untuk parsing
  public function getSourceTypeAttribute(): string
  {
    return explode(':', $this->source, 2)[0];
  }

  public function getSourceIdentifierAttribute(): string
  {
    $parts = explode(':', $this->source, 2);
    return $parts[1] ?? '';
  }

  // Accessor untuk komparasi
  public function getVersionCarbonAttribute(): Carbon
  {
    return Carbon::parse($this->version)->utc();
  }

  // Scope untuk query
  // $manifests = Manifest::fromType('cms')->latest()->paginate(10);
  public function scopeFromType($query, string $type)
  {
    return $query->where('source', 'like', "{$type}:%");
  }

  public function scopeFromIdentifier($query, string $identifier)
  {
    return $query->where('source', 'like', "%:{$identifier}");
  }

  // Scope untuk query chronologis
  public function scopeAfterVersion($query, string $version)
  {
    return $query->where('version', '>', $version);
  }

  public function scopeBeforeVersion($query, string $version)
  {
    return $query->where('version', '<', $version);
  }

  // Relasi
  public function merges()
  {
    return $this->hasMany(Merge::class, 'manifest_id');
  }

  public function user()
  {
    return $this->belongsTo(User::class, 'from_id');
  }

  // Akses konten lengkap
  public function getContentAttribute(): array
  {
    return WorkspaceManifest::content($this->storage_path);
  }

  public function validateIntegrity(): bool
  {
    $calculated = WorkspaceManifest::hash($this->storage_path);
    return hash_equals($this->hash_tree_sha256, $calculated);
  }

  // Helper: cek apakah ini manifest terbaru dari sumber ini
  public function getIsLatestAttribute(): bool
  {
    return $this->version === Manifest::where('source', $this->source)
      ->latest('version')
      ->value('version');
  }
}

<?php

namespace Dochub\Workspace\Models;

use Dochub\Workspace\File;
use Dochub\Workspace\Manifest as WorkspaceManifest;
use Dochub\Workspace\Services\ManifestLocalStorage;
use Dochub\Workspace\Services\ManifestSourceParser;
use Dochub\Workspace\Services\ManifestVersionParser;
use Illuminate\Database\Eloquent\Builder;
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
//        "size": 2048,
//        "file_modified_at" "2025-11-20T14:00:00Z", // mtime dari file asli sebelum jadi blob
//      }
//   ],
// TAMBAHAN:
// sepertinya history tidak dipakai
// "histories": []
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
    'workspace_id',
    'from_id',
    'source',
    'version',
    'total_files',
    'total_size_bytes',
    'hash_tree_sha256',
    'storage_path', // 🔑 pointer ke file disk
    'tags'
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
      if (!ManifestVersionParser::isValid($manifest->version)) {
        throw new \InvalidArgumentException("Invalid version timestamp");
      }
    });

    // static::creating(function ($manifest) {
    //   // Normalisasi ke UTC ISO 8601
    //   // if ($manifest->version) {
    //   if (!ManifestVersionParser::isValid($manifest->version)) {
    //     throw new \InvalidArgumentException("Invalid version timestamp");
    //   }
    // });

    static::deleting(function ($manifest) {
      $path = WorkspaceManifest::path($manifest->storage_path);
      WorkspaceManifest::unlink($path);
    });
  }

  public static function createByWsManifest(WorkspaceManifest $dhManifest, string $userId, string | null $workspaceId)
  {
    $fillable = [
      'workspace_id' => $workspaceId,
      'from_id' => $userId,
      'source' => $dhManifest->source,
      'version' => $dhManifest->version,
      'total_files' => $dhManifest->total_files,
      'total_size_bytes' => $dhManifest->total_size_bytes,
      'hash_tree_sha256' => $dhManifest->hash_tree_sha256,
      'storage_path' => $dhManifest->storage_path(),
      'tags' => $dhManifest->tags
    ];
    return static::create($fillable);
  }

  // public static function createByManifestJson(array $manifest, string $userId, string | null $workspaceId, string | null $tags)
  // {
  //   $dhManifest = WorkspaceManifest::create($manifest);
  //   $dhManifest->tags = $tags;
  //   $dhManifest->store();
  //   return self::createByWsManifest($dhManifest, $userId, $workspaceId);
  // }

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

  public function scopeWorkspace(Builder $query): void
  {
    // $query->where('from_id', '1');
    // $query->whereNotNull('dochub_workspaces.deleted_at');
    // $query->whereBelongsTo(Workspace::class, 'workspace');
    // $query->whereBelongsTo('workspace', function(Builder $q) {
    //   dd($q);
    //   $q->whereNotNull('dochub_workspaces.deleted_at');
    // });
  }

  // Relasi
  public function merge()
  {
    return $this->hasOne(Merge::class, 'manifest_hash', 'hash_tree_sha256');
  }

  public function user()
  {
    return $this->belongsTo(User::class, 'from_id');
  }

  public function workspace()
  {
    return $this->belongsTo(Workspace::class, 'workspace_id')->whereNull('deleted_at');
  }

  // Akses konten lengkap $this->content
  public function getContentAttribute(): WorkspaceManifest
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

  public function toArray()
  {
    return [
      'id' => $this->id,
      'workspace_id' => $this->workspace_id,
      'from_id' => $this->from_id,
      'source' => $this->source,
      'version' => $this->version,
      'total_files' => $this->total_files,
      'total_size_bytes' => $this->total_size_bytes,
      'hash_tree_sha256' => $this->hash_tree_sha256,
      'storage_path' => $this->storage_path,
      'tags' => $this->tags,
      'created_at' => $this->created_at,
      'updated_at' => $this->updated_at,
    ];
  }
}

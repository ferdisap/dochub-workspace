<?php

namespace Dochub\Workspace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Merge extends Model
{
  protected $table = 'dochub_merges';

  protected $keyType = 'string'; // UUID

  public $incrementing = false;

  protected $fillable = [
    'id', // UUID
    'prev_merge_id',
    'workspace_id',
    'manifest_hash',
    'label',
    'message',
    'merged_at'
  ];

  protected $dates = ['merged_at'];

  /**
   * Override boot() method untuk secara otomatis menghasilkan UUID.
   * Metode ini akan dipanggil sebelum Model dibuat.
   */
  protected static function boot()
  {
    parent::boot();

    static::creating(function ($model) {
      // Jika ID belum ada, isi dengan UUID.
      if (! $model->getKey()) {
        $model->{$model->getKeyName()} = (string) Str::uuid();
      }
    });
  }

  // Relasi
  public function workspace(): BelongsTo
  {
    return $this->belongsTo(Workspace::class, 'workspace_id');
  }

  public function previousMerge(): BelongsTo
  {
    return $this->belongsTo(Merge::class, 'prev_merge_id', 'id');
  }

  public function manifest(): BelongsTo
  {
    return $this->belongsTo(Manifest::class, 'manifest_hash', 'hash_tree_sha256');
  }

  public function files(): HasMany
  {
    // setiap merge, memiliki file yang workspace id nya sama
    return $this->hasMany(File::class, 'merge_id');
  }

  public function prevMerges(): HasMany
  {
    // public function nextMerges(): HasMany
    return $this->hasMany(Merge::class, 'prev_merge_id', 'id');
  }

  // Scopes
  public function scopeForWorkspace($query, $workspaceId)
  {
    return $query->where('workspace_id', $workspaceId);
  }

  public function scopeWithFiles($query)
  {
    return $query->with('files');
  }

  // Helper
  public function getChangeStatsAttribute(): array
  {
    return [
      'total_files' => $this->files()->count(),
      'added' => $this->files()->where('action', 'added')->count(),
      'updated' => $this->files()->where('action', 'updated')->count(),
      'deleted' => $this->files()->where('action', 'deleted')->count(),
    ];
  }
}

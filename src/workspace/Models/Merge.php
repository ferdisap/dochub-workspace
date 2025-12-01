<?php

namespace Dochub\Workspace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class Merge extends Model
{
  protected $table = 'dochub_merges';

  protected $keyType = 'string'; // UUID

  public $incrementing = false;

  protected $fillable = [
    'id', // UUID
    'prev_merge_id',
    'workspace_id',
    'manifest_id',
    'label',
    'message',
    'merged_at'
  ];

  protected $dates = ['merged_at'];

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
    return $this->belongsTo(Manifest::class, 'manifest_id');
  }

  public function files(): HasMany
  {
    // return $this->hasMany(File::class, 'merge_id');
    // setiap merge, memiliki file yang workspace id nya sama
    return $this->hasMany(File::class, 'workspace_id');
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

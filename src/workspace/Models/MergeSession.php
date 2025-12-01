<?php

namespace Dochub\Workspace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;

class MergeSession extends Model
{
  protected $table = 'dochub_merge_sessions';

  protected $fillable = [
    'target_workspace_id',
    'source_identifier',
    'source_type',
    'started_at',
    'completed_at',
    'status',
    'metadata',
    'initiated_by_user_id',
    'result_merge_id' // 🔑 tambahan kunci
  ];

  protected $casts = [
    'metadata' => 'array',
    'started_at' => 'datetime',
    'completed_at' => 'datetime',
  ];

  // Relasi
  public function workspace(): BelongsTo
  {
    return $this->belongsTo(Workspace::class, 'target_workspace_id');
  }

  public function user(): BelongsTo
  {
    return $this->belongsTo(User::class, 'initiated_by_user_id');
  }

  public function resultMerge(): BelongsTo
  {
    return $this->belongsTo(Merge::class, 'result_merge_id');
  }

  // Scopes
  public function scopeSuccessful($query)
  {
    return $query->where('status', 'applied');
  }

  public function scopeForWorkspace($query, $workspaceId)
  {
    return $query->where('target_workspace_id', $workspaceId);
  }

  // Helper
  public function getDurationAttribute(): ?int
  {
    if ($this->completed_at) {
      return $this->completed_at->diffInMilliseconds($this->started_at);
    }
    return null;
  }
}

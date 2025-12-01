<?php

namespace Dochub\Workspace\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User;

use function Illuminate\Support\now;

class Workspace extends Model
{
  use HasFactory, SoftDeletes;

  protected $table = 'dochub_workspaces';

  protected $fillable = [
    'owner_id',
    'name',
    'visibility'
  ];

  /** user */
  public function owner()
  {
    return $this->belongsTo(User::class, 'owner_id');
  }

  /** mengambil semua file */
  public function files()
  {
    // return $this->hasMany(File::class, 'workspace_id');
    return $this->hasManyThrough(
      File::class,
      Merge::class,
      'workspace_id',
      'merge_id',
      'id',
      'id'
    )->whereNotNull('merge_id');
  }
  /** mengambil semua merge */
  public function merges()
  {
    return $this->hasMany(File::class, 'workspace_id');
  }
  /** latest merge */
  public function latestMerge()
  {
    return $this->merges()->latest('merged_at')->first();
  }


  /**
   * Scope: Cari workspace yang merupakan hasil rollback
   * 
   * @param \Illuminate\Database\Eloquent\Builder $query
   * @return \Illuminate\Database\Eloquent\Builder
   */
  public function scopeRollbackWorkspaces($query)
  {
    return $query->where(function ($q) {
      $q->where('name', 'like', '%-rollback-%')
        ->orWhere('name', 'like', '%-restore-%')
        ->orWhere('name', 'like', '%-copy-%');
    });
  }

  /**
   * Scope: Filter berdasarkan umur (lebih dari N hari)
   * 
   * @param \Illuminate\Database\Eloquent\Builder $query
   * @param int $days
   * @return \Illuminate\Database\Eloquent\Builder
   */
  public function scopeOlderThanDays($query, int $days)
  {
    return $query->where('created_at', '<', now()->subDays($days));
  }


  /**
   * Dapatkan daftar workspace rollback yang valid (ada session rollback)
   * 
   * @param int $days
   * @return \Illuminate\Database\Eloquent\Collection
   */
  public static function getValidRollbackWorkspaces(int $days = 7)
  {
    return self::withCount('files as file_count')
      ->rollbackWorkspaces()
      ->olderThanDays($days)
      ->get()
      ->filter(function ($workspace) {
        return MergeSession::where([
          'target_workspace_id' => $workspace->id,
          'source_type' => 'rollback'
        ])->exists();
      });
  }

  /**
   * Cek apakah workspace ini merupakan hasil rollback
   * 
   * @return bool
   */
  public function isRollbackWorkspace(): bool
  {
    return MergeSession::where([
      'target_workspace_id' => $this->id,
      'source_type' => 'rollback'
    ])->exists();
  }

  // Helper
  public function duplicateFromMerge(string $mergeId, ?string $newName = null): self
  {
    $merge = $this->merges()->findOrFail($mergeId);

    $newWorkspace = $this->replicate();
    $newWorkspace->name = $newName ?? "{$this->name}-rollback-" . now()->format('YmdHis');
    $newWorkspace->save();

    // Salin file dari merge target
    foreach ($merge->files as $file) {
      $file->replicate()->fill([
        'workspace_id' => $newWorkspace->id,
      ])->save();
    }

    return $newWorkspace;
  }
}

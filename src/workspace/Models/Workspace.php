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

  /** menghapus semua file (bukan hanya aktif file) di db*/
  public function deleteAllFiles()
  {
    File::where('workspace_id', $this->id)->delete();
  }

  /** user */
  public function owner()
  {
    return $this->belongsTo(User::class, 'owner_id');
  }

  /** mengambil semua file */
  public function allFiles()
  {
    return $this->hasMany(File::class, 'workspace_id');
  }

  /** mengambil files yang aktif berdasarkan merge. eg: jika file rollback maka tidak ada mergenya*/
  public function files()
  {
    return $this->hasManyThrough(
      File::class,
      Merge::class,
      'workspace_id',
      'merge_id',
      'id',
      'id'
    )->whereNotNull('merge_id');
  }

  public function sessions()
  {
    return $this->hasMany(MergeSession::class, 'target_workspace_id');
  }

  /** 
   * mengambil semua merge 
   * relasi merge belum tentu didapat karena seperti rolllback, yang membuat workspace baru, tidak ada merge nya. 
   * Jadi mengakses merge harus menggunakan MergeSession dan ambil result_merge_id nya
   */
  public function merges()
  {
    // return $this->hasMany(Merge::class, 'workspace_id');
    return $this->hasManyThrough(
      Merge::class,       // Model Akhir yang ingin dituju
      MergeSession::class,     // Model Perantara
      'target_workspace_id',     // Foreign key pada tabel Session (merujuk ke Workspace)
      'id',               // Foreign key pada tabel Merge (biasanya id) dalam context workspace atau PK di tabel merges (yang ditunjuk oleh result_merge_id)
      'id',               // Local key pada tabel Workspace
      'result_merge_id'   // Local key pada tabel Session (yang menyimpan ID Merge)
    );
  }
  /** latest and olderst merge */
  public function oldestMerge()
  {
    // return $this->hasOne(Merge::class, 'workspace_id')->oldest('merged_at');
    return $this->hasOneThrough(
      Merge::class,           // Model Tujuan
      MergeSession::class,         // Model Perantara
      'target_workspace_id',  // FK di tabel sessions (menunjuk ke workspaces)
      'id',                   // PK di tabel merges (ditunjuk oleh sessions)
      'id',                   // PK di tabel workspaces
      'result_merge_id'       // FK di tabel sessions (menunjuk ke merges)
    )
      ->oldest('dochub_merges.merged_at'); // Mengambil yang paling baru berdasarkan created_at
  }

  public function latestMerge()
  {
    // return $this->hasOne(Merge::class, 'workspace_id')->latest('merged_at');
    return $this->hasOneThrough(
      Merge::class,           // Model Tujuan
      MergeSession::class,         // Model Perantara
      'target_workspace_id',  // FK di tabel sessions (menunjuk ke workspaces)
      'id',                   // PK di tabel merges (ditunjuk oleh sessions)
      'id',                   // PK di tabel workspaces
      'result_merge_id'       // FK di tabel sessions (menunjuk ke merges)
    )
      ->latest('dochub_merges.merged_at'); // Mengambil yang paling baru berdasarkan created_at
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

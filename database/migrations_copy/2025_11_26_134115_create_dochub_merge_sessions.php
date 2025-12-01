<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  /**
   * Run the migrations.
   */
  public function up(): void
  {
    Schema::create('dochub_merge_sessions', function (Blueprint $table) {
      $table->id();
      // $table->foreignId('target_workspace_id')->constrained('workspaces'); // tujuan merge
      $table->foreignId('target_workspace_id'); // tujuan merge
      // $table->foreignUuid('result_merge_id')->constrained('dochub_merges'); // ← tambahkan!
      $table->foreignUuid('result_merge_id'); // ← tambahkan!
      // $table->foreignUuid('result_merge_id')->nullable()->constrained('dochub_merges'); // ← tambahkan!
      $table->string('source_identifier'); // e.g., 'remote:client-abc', 'upload:20251126-abc.zip', 'rollback:user_id:merge_id
      $table->string('source_type')->default('remote'); // 'remote', 'upload', 'manual', 'rollback

      $table->timestamp('started_at');
      $table->timestamp('completed_at')->nullable();
      $table->string('status')->default('pending'); // pending, scanning, conflicts, resolved, applied, failed

      $table->json('metadata')->nullable(); // info dari third-party: version, timestamp, etc (// wajib ada is_binary).
      $table->foreignId('initiated_by_user_id')->constrained('users');

      $table->timestamps();
    });

    // Schema::create('dochub_merge_sessions', function (Blueprint $table) {
    //   $table->id();
    //   $table->string('source');          // 'thirdparty:client-xyz', 'upload:user-123'
    //   $table->timestamp('applied_at');   // kapan perubahan diterapkan
    //   $table->string('status')->default('applied'); // applied, rolled_back
    //   $table->string('backup_archive_path')->nullable(); // e.g., 'backups/merge-42.tar.gz'
    //   $table->unsignedInteger('files_changed');
    //   $table->unsignedInteger('files_added');
    //   $table->unsignedInteger('files_deleted');
    //   $table->json('summary')->nullable(); // opsional: daftar file berubah
    //   $table->timestamps();
    // });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('dochub_merge_sessions');
  }
};

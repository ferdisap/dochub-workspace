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
    Schema::create('dochub_merges', function (Blueprint $table) {
      $table->uuid()->primary(); // ← gunakan UUID sebagai ID (lebih aman untuk sync/distribusi)
      $table->foreignUuid('prev_merge_id')->nullable();
      // $table->foreignId('workspace_id')->constrained('dochub_workspaces');
      $table->foreignId('workspace_id');
      // $table->foreignId('manifest_id')->constrained('dochub_manifests')->nullable();
      $table->foreignId('manifest_id')->nullable();
      $table->string('label')->nullable(); // 'v1.2.3'
      $table->text('message')->nullable();
      $table->timestamp('merged_at'); // kapan state ini direkam (immutable)
      $table->timestamps();
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('dochub_merges');
  }
};

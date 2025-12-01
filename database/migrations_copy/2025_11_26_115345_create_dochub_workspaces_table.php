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
    Schema::create('dochub_workspaces', function (Blueprint $table) {
      $table->id();
      $table->foreignId('owner_id')->constrained('users');
      $table->string('name')->unique();
      $table->string('visibility')->default('private'); // 'public', 'private'
      $table->softDeletes(); // untuk archive tanpa hapus history
      $table->timestamps();
    });
  }

  /**
   * Reverse the migrations.
   */
  public function down(): void
  {
    Schema::dropIfExists('dochub_workspaces');
  }
};

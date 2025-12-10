<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up()
  {
    // Workspace
    Schema::create('encryption_keys', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id'); //->constrained('users');
      $table->string('public_key'); // base64 string
      $table->timestamps();
    });
  }

  public function down()
  {
    Schema::dropIfExists('encryption_keys');
  }
};

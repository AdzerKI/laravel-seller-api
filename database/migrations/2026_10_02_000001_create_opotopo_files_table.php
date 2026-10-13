<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opotopo_files', function (Blueprint $table) {
            $table->id();
            $table->text('source');
            $table->char('source_hash', 64);
            $table->string('gallery', 32);
            $table->uuid('category_id');
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('modified_at');
            $table->char('checksum', 64);
            $table->uuid('file_id')->unique();
            $table->timestamps();
            $table->unique(['source_hash', 'gallery', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opotopo_files');
    }
};

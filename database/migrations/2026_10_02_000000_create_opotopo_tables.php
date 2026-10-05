<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opotopo_items', function (Blueprint $table) {
            $table->id();
            $table->string('catalog');
            $table->string('model_key');
            $table->string('sku');
            $table->uuid('advertisement_id')->nullable();
            $table->char('fingerprint', 64)->nullable();
            $table->string('error')->nullable();
            $table->json('error_details')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['catalog', 'model_key']);
        });

        Schema::create('opotopo_files', function (Blueprint $table) {
            $table->id();
            $table->string('source', 1024);
            $table->string('gallery', 32);
            $table->uuid('category_id');
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('modified_at');
            $table->char('checksum', 64);
            $table->uuid('file_id')->unique();
            $table->timestamps();
            $table->unique(['source', 'gallery', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opotopo_files');
        Schema::dropIfExists('opotopo_items');
    }
};

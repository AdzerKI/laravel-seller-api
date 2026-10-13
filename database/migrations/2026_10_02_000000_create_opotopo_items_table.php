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
    }

    public function down(): void
    {
        Schema::dropIfExists('opotopo_items');
    }
};

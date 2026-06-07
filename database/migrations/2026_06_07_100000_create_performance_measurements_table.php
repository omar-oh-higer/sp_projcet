<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_measurements', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 16);
            $table->string('name');
            $table->decimal('duration_ms', 12, 3);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_measurements');
    }
};

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
        Schema::create('benchmark_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('trace_id')->unique();
            $table->string('mode', 20);
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('total_duration_ms', 12, 3);
            $table->unsignedInteger('db_queries');
            $table->string('bottleneck_span')->nullable();
            $table->json('spans');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('benchmark_runs');
    }
};

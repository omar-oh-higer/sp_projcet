<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('load_distribution_hits', function (Blueprint $table) {
            $table->id();
            $table->string('target_server', 40);
            $table->string('distribution_mode', 30);
            $table->unsignedInteger('request_index')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('load_distribution_hits');
    }
};

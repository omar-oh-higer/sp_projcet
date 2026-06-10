<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('load_distribution_hits', function (Blueprint $table) {
            $table->unsignedSmallInteger('target_port')->nullable()->after('target_server');
        });
    }

    public function down(): void
    {
        Schema::table('load_distribution_hits', function (Blueprint $table) {
            $table->dropColumn('target_port');
        });
    }
};

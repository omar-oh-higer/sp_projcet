<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_sales_tally_chunks', function (Blueprint $table) {
            $table->unsignedTinyInteger('worker_terminal')->nullable()->after('worker_pid');
        });
    }

    public function down(): void
    {
        Schema::table('daily_sales_tally_chunks', function (Blueprint $table) {
            $table->dropColumn('worker_terminal');
        });
    }
};

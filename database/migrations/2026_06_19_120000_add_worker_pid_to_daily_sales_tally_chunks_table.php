<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_sales_tally_chunks', function (Blueprint $table) {
            $table->unsignedBigInteger('worker_pid')->nullable()->after('total_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('daily_sales_tally_chunks', function (Blueprint $table) {
            $table->dropColumn('worker_pid');
        });
    }
};

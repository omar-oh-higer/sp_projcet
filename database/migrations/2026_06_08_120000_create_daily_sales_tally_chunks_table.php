<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_sales_tally_chunks', function (Blueprint $table) {
            $table->id();
            $table->date('sale_date');
            $table->string('batch_id', 36);
            $table->unsignedInteger('chunk_index');
            $table->unsignedInteger('order_count')->default(0);
            $table->unsignedBigInteger('total_quantity')->default(0);
            $table->timestamps();

            $table->unique(['batch_id', 'chunk_index']);
            $table->index('sale_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_sales_tally_chunks');
    }
};

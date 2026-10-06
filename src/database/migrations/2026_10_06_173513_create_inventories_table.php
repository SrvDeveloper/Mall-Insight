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
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->date('stock_date')->comment('在庫基準日（ZeroStockViewの在庫調査日、日本時間）');
            $table->foreignId('sku_id')->constrained()->cascadeOnDelete();
            $table->string('warehouse', 16)->comment('在庫の区分（amazon_own / amazon_fba / boss_own / boss_rfc / free_stock / ec_stock）');
            $table->integer('quantity')->comment('在庫数');
            $table->timestamps();

            $table->unique(['stock_date', 'sku_id', 'warehouse']);
            $table->index(['sku_id', 'stock_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};

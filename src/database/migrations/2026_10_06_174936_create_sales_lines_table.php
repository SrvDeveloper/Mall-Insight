<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 販売実績（注文明細）。データ量が多くなるため、使い道のある列だけを持つ（決定記録 K-024）。
 * 注文の情報（注文ID・販売日・モール）は明細に持たせ、注文のテーブルは作らない。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sales_lines', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16)->comment('取込元（boss）');
            $table->string('source_order_id', 32)->comment('取込元の注文ID。取り込み直すときに、この注文の明細をまるごと置き換える');
            $table->date('sales_date')->comment('販売日（日本時間）');
            $table->string('mall', 16)->comment('モール（rakuten / yahoo / au_pay）');
            $table->foreignId('sku_id')->constrained()->restrictOnDelete();
            $table->string('warehouse', 16)->comment('出荷倉庫（在庫の区分。BOSSは boss_own / boss_rfc）');
            $table->unsignedSmallInteger('quantity')->comment('販売数量');
            $table->unsignedInteger('amount')->comment('商品金額（税込、円）');

            $table->index(['source', 'source_order_id']);
            $table->index(['sku_id', 'sales_date']);
            $table->index('sales_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_lines');
    }
};

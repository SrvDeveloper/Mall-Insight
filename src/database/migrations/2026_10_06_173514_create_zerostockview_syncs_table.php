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
        Schema::create('zerostockview_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 16)->comment('結果（succeeded / failed）');
            $table->string('triggered_by', 16)->comment('実行のきっかけ（schedule / manual）');
            $table->timestamp('started_at')->comment('開始日時');
            $table->timestamp('finished_at')->comment('終了日時');
            $table->date('from_date')->nullable()->comment('取得した期間の開始日');
            $table->date('to_date')->nullable()->comment('取得した期間の終了日');
            $table->unsignedInteger('stock_date_count')->nullable()->comment('取得できた在庫基準日の数');
            $table->unsignedInteger('sku_row_count')->nullable()->comment('取得した行数（在庫基準日×SKU）');
            $table->unsignedInteger('created_sku_count')->nullable()->comment('CrossWalkerに無く、未登録SKUとして新しく保存したSKU数');
            $table->date('latest_stock_date')->nullable()->comment('取得後の最新の在庫基準日');
            $table->text('error_message')->nullable()->comment('失敗した理由（利用者向けの文言）');
            $table->timestamps();

            $table->index(['status', 'finished_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zerostockview_syncs');
    }
};

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
        Schema::create('forecast_snapshot_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('item_no')->comment('品番コード（品番が消えても残すためコードで持つ）');
            $table->unsignedBigInteger('sku_id')->comment('SKU ID（SKUが消えても残すため外部キーにしない）');
            $table->string('sku_code');
            $table->string('channel', 20);
            $table->date('month')->comment('予測した月（月の1日）');
            $table->decimal('quantity', 12, 4)->nullable()->comment('需要予測（補正の後）。予測できなければ null');
            $table->decimal('unadjusted_quantity', 12, 4)->nullable()->comment('手動での補正を掛ける前の予測');
            $table->decimal('event_quantity', 12, 4)->nullable()->comment('そのうちイベントで増やした数（補正の前）');
            $table->decimal('adjustment_rate', 8, 4)->nullable()->comment('掛けた補正の倍率。補正していなければ null');
            $table->decimal('base_average', 12, 4)->nullable()->comment('基準平均日販');
            $table->string('unpredictable_reason', 32)->nullable()->comment('予測できなかった理由');

            $table->index(['forecast_snapshot_id', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forecast_snapshot_values');
    }
};

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
        Schema::create('forecast_snapshots', function (Blueprint $table) {
            $table->id();
            $table->timestamp('taken_at')->comment('予測を計算して保存した日時');
            $table->date('forecast_from')->comment('予測の開始日（保存した日）。この月から12か月を予測している');
            $table->string('trigger', 16)->comment('保存したきっかけ（schedule：毎月1日の自動保存、manual：画面から保存）');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete()->comment('画面から保存した利用者');
            $table->unsignedSmallInteger('check_month_offset')->comment('保存したときの、欠品を判定する月（何か月後か）');
            $table->json('channels')->comment('チャネルごとの、予測に使った販売実績の期間');
            $table->json('target_items')->comment('保存したときの全体・Amazon・BOSS の対象品番');
            $table->json('adjustments')->comment('掛けた手動での補正（B-109）');
            $table->json('seasonal_item_nos')->comment('季節係数を登録していた品番');
            $table->unsignedInteger('item_count');
            $table->unsignedInteger('sku_count');
            $table->timestamps();

            $table->index('forecast_from');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forecast_snapshots');
    }
};

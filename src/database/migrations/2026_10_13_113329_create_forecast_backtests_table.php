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
        Schema::create('forecast_backtests', function (Blueprint $table) {
            $table->id();
            $table->dateTime('measured_at')->comment('測った日時（この日の前日までの販売実績で答え合わせした）');
            $table->foreignId('measured_by_user_id')->nullable()->constrained('users')->nullOnDelete()->comment('測った利用者');
            $table->unsignedTinyInteger('check_month_offset')->comment('判定する月（測ったときの在庫推移の設定）');
            $table->json('settings')->comment('測ったときの予測の設定（期間の重み・必要な販売日数など）と、何か月先ごとの起点');
            $table->unsignedInteger('item_count')->comment('対象品番の数');
            $table->unsignedInteger('sku_count')->comment('対象品番の有効なSKUの数');
            $table->unsignedInteger('duration_ms')->comment('測るのにかかった時間（ミリ秒）');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forecast_backtests');
    }
};

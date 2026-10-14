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
        // イベントの日と普段の日に分けた結果（B-133、K-125）。分ける前に測った記録は null
        Schema::table('forecast_backtest_results', function (Blueprint $table) {
            $table->decimal('event_actual', 12, 2)->nullable()->after('abs_error')->comment('比べたSKUの、イベントの日の販売実績の合計');
            $table->decimal('event_forecast', 14, 4)->nullable()->after('event_actual')->comment('比べたSKUの、イベントの日の予測の合計');
            $table->decimal('event_abs_error', 14, 4)->nullable()->after('event_forecast')->comment('比べたSKUの、イベントの日の予測と販売実績の差の絶対値の合計');
            $table->decimal('normal_actual', 12, 2)->nullable()->after('event_abs_error')->comment('比べたSKUの、普段の日の販売実績の合計');
            $table->decimal('normal_forecast', 14, 4)->nullable()->after('normal_actual')->comment('比べたSKUの、普段の日の予測の合計');
            $table->decimal('normal_abs_error', 14, 4)->nullable()->after('normal_forecast')->comment('比べたSKUの、普段の日の予測と販売実績の差の絶対値の合計');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('forecast_backtest_results', function (Blueprint $table) {
            $table->dropColumn(['event_actual', 'event_forecast', 'event_abs_error', 'normal_actual', 'normal_forecast', 'normal_abs_error']);
        });
    }
};

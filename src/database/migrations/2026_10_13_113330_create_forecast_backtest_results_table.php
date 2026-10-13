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
        Schema::create('forecast_backtest_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('forecast_backtest_id')->constrained()->cascadeOnDelete();
            $table->string('horizon', 16)->comment('何か月先（month_1 / months_3 / check_month）');
            $table->string('method', 16)->comment('方式（base_average / recent_30 / last_year）');
            $table->string('item_no')->comment('品番');
            $table->string('channel', 8)->comment('チャネル（boss / amazon）');
            $table->date('origin')->comment('起点（月の1日）');
            $table->unsignedInteger('evaluated')->comment('比べたSKUの数');
            $table->unsignedInteger('skipped')->comment('比べられなかったSKUの数（いずれかの方式で予測できない、販売実績を取り込んでいない）');
            $table->decimal('actual', 12, 2)->comment('比べたSKUの販売実績の合計');
            $table->decimal('forecast', 14, 4)->comment('比べたSKUの予測の合計');
            $table->decimal('abs_error', 14, 4)->comment('比べたSKUの、予測と販売実績の差の絶対値の合計');
            $table->index(['forecast_backtest_id', 'horizon']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forecast_backtest_results');
    }
};

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
        Schema::create('sales_targets', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year')->comment('年度（4月始まり。2026 = 2026年4月～2027年3月）');
            $table->string('item_no')->comment('品番。CrossWalker から品番が消えても残す');
            $table->unsignedInteger('annual_quantity')->comment('品番の年間販売目標（数量）');
            $table->json('monthly_quantities')->nullable()->comment('4月～翌3月の品番の月間販売目標を手で直した値。直していない月は null（年間×月別販売比率を使う）');
            $table->json('sku_quantities')->nullable()->comment('SKUの年間の数を手で上書きした値（SKU ID => 数量）。上書きしていないSKUは構成比で割り振る');
            $table->string('note', 500)->nullable()->comment('変更の理由などのメモ');
            $table->string('source', 16)->comment('screen：画面で登録、excel：現行Excelから取り込み、carry_over：前年度から引き継ぎ');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete()->comment('変更した利用者');
            $table->timestamps();
            $table->index(['fiscal_year', 'item_no', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_targets');
    }
};

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
        Schema::create('monthly_sales_ratios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ratio_category_id')->constrained()->comment('月別販売比率の区分');
            $table->json('ratios')->comment('4月～翌3月の12か月の比率。1万分率（7.09% = 709）で、合計は10000');
            $table->string('source', 16)->comment('screen：画面で登録、excel：現行Excelから取り込み');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete()->comment('変更した利用者');
            $table->timestamps();
            $table->index(['ratio_category_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('monthly_sales_ratios');
    }
};

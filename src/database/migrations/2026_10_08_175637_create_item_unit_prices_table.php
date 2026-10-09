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
        Schema::create('item_unit_prices', function (Blueprint $table) {
            $table->id();
            $table->string('item_no')->unique()->comment('品番。CrossWalker から品番が消えても残す');
            $table->unsignedInteger('unit_price')->nullable()->comment('全体の単価（税込、円）。モールを分けない金額（販売目標の概算など）に使う');
            $table->unsignedInteger('amazon_unit_price')->nullable()->comment('Amazonの単価（税込、円）');
            $table->unsignedInteger('boss_unit_price')->nullable()->comment('BOSS（楽天市場・Yahoo!ショッピング・au PAY マーケット）の単価（税込、円）');
            $table->string('source', 16)->comment('screen：画面で登録、excel：現行Excelから取り込み');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete()->comment('最後に変更した利用者');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_unit_prices');
    }
};

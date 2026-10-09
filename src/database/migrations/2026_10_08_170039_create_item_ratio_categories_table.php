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
        Schema::create('item_ratio_categories', function (Blueprint $table) {
            $table->id();
            $table->string('item_no')->unique()->comment('品番。CrossWalker から品番が消えても残す');
            $table->foreignId('ratio_category_id')->constrained()->comment('使う月別販売比率の区分');
            $table->string('source', 16)->comment('screen：画面で登録、excel：現行Excelから取り込み');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete()->comment('変更した利用者');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_ratio_categories');
    }
};

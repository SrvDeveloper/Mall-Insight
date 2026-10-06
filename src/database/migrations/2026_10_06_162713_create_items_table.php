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
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('item_no')->unique()->comment('品番コード（商品名として扱う）');
            $table->string('brand')->comment('ブランド名称');
            $table->string('category')->comment('カテゴリー名称');
            $table->string('parent_asin')->nullable()->comment('Amazon親ASIN');
            $table->string('status', 16)->comment('有効状態（active / inactive）');
            $table->timestamp('crosswalker_updated_at')->nullable()->comment('CrossWalker側の更新日時');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};

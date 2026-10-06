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
        Schema::create('skus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->nullable()->comment('所属品番。品番に未登録のSKUはnull')->constrained()->nullOnDelete();
            $table->string('sku_code')->unique()->comment('SKUコード');
            $table->string('child_asin')->nullable()->index()->comment('Amazon子ASIN');
            $table->string('status', 16)->nullable()->comment('実効状態（active / inactive）。CrossWalker未登録はnull');
            $table->string('tq_item_no')->nullable()->comment('TQ品番');
            $table->string('tq_color_no')->nullable()->comment('TQカラーNo');
            $table->string('tq_size')->nullable()->comment('TQサイズ');
            $table->unsignedSmallInteger('position')->nullable()->comment('品番内の表示順');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('skus');
    }
};

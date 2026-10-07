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
        Schema::create('sales_import_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_import_id')->constrained()->cascadeOnDelete();
            $table->string('level', 16)->comment('error（登録しなかった）/ warning（登録したが確認が必要）');
            $table->unsignedInteger('row_number')->nullable()->comment('ファイルの行番号（見出し行を1行目とする）');
            $table->string('source_order_id')->nullable()->comment('対象の注文ID');
            $table->string('sku_code')->nullable()->comment('対象のSKUコード');
            $table->text('message')->comment('指摘の内容（利用者向けの文言）');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_import_issues');
    }
};

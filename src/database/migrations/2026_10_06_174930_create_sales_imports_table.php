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
        Schema::create('sales_imports', function (Blueprint $table) {
            $table->id();
            $table->string('source', 16)->comment('取込元（boss）');
            $table->string('file_name')->comment('取り込んだファイル名');
            $table->string('status', 16)->comment('結果（succeeded / failed）。failed はファイル全体を取り込めなかったとき');
            $table->timestamp('started_at')->comment('開始日時');
            $table->timestamp('finished_at')->comment('終了日時');
            $table->unsignedInteger('row_count')->nullable()->comment('ファイルのデータ行数');
            $table->unsignedInteger('created_line_count')->nullable()->comment('新しく登録した注文明細数');
            $table->unsignedInteger('updated_line_count')->nullable()->comment('登録済みで上書きした注文明細数');
            $table->unsignedInteger('skipped_line_count')->nullable()->comment('重複のため対象外にした行数');
            $table->unsignedInteger('error_row_count')->nullable()->comment('取込エラーで登録しなかった行数（金額不一致の注文の行を含む）');
            $table->unsignedInteger('created_sku_count')->nullable()->comment('CrossWalkerに無く、未登録SKUとして新しく保存したSKU数');
            $table->date('sales_date_from')->nullable()->comment('登録した販売実績の最も古い販売日');
            $table->date('sales_date_to')->nullable()->comment('登録した販売実績の最も新しい販売日');
            $table->text('error_message')->nullable()->comment('ファイル全体を取り込めなかった理由');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_imports');
    }
};

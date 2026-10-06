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
        Schema::create('crosswalker_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 16)->comment('結果（succeeded / failed）');
            $table->string('triggered_by', 16)->comment('実行のきっかけ（schedule / manual）');
            $table->timestamp('started_at')->comment('開始日時');
            $table->timestamp('finished_at')->comment('終了日時');
            $table->unsignedInteger('item_count')->nullable()->comment('取得した品番数');
            $table->unsignedInteger('sku_count')->nullable()->comment('取得したSKU数');
            $table->unsignedInteger('added_item_count')->nullable()->comment('新しく追加した品番数');
            $table->unsignedInteger('removed_item_count')->nullable()->comment('CrossWalkerから消えたため削除した品番数');
            $table->unsignedInteger('detached_sku_count')->nullable()->comment('品番から外れ、未登録SKUになったSKU数');
            $table->text('error_message')->nullable()->comment('失敗した理由（利用者向けの文言）');
            $table->timestamps();

            $table->index(['status', 'finished_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crosswalker_syncs');
    }
};

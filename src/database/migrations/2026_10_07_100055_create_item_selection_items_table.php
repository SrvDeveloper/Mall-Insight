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
        Schema::create('item_selection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_selection_id')->constrained()->cascadeOnDelete();
            $table->string('item_no')->comment('品番コード。CrossWalkerから品番が消えても選定の記録を残すため、品番IDではなくコードで持つ');
            $table->unsignedSmallInteger('sales_rank')->nullable()->comment('確定時の売上順位。期間内に販売実績が無い品番は null');
            $table->unsignedBigInteger('sales_amount')->nullable()->comment('確定時の直近12か月の売上金額（税込、円）。販売実績が無い品番は null');
            $table->boolean('is_candidate')->comment('確定時に売上順位20位以内の候補だったか');
            $table->timestamps();

            $table->unique(['item_selection_id', 'item_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_selection_items');
    }
};

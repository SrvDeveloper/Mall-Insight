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
        Schema::create('inbound_plans', function (Blueprint $table) {
            $table->id();
            $table->string('item_no')->comment('品番コード。CrossWalkerから品番が消えても入荷予定を残すため、品番IDではなくコードで持つ');
            $table->date('arrival_month')->comment('入荷予定月（その月の1日）');
            $table->unsignedInteger('quantity')->comment('品番全体の入荷予定数');
            $table->string('note', 500)->nullable()->comment('発注先・発注番号などのメモ');
            $table->timestamp('received_at')->nullable()->comment('入荷済みにした日時。入荷済みは在庫推移に入れない（在庫に反映済みのため）');
            $table->timestamps();

            $table->index(['arrival_month', 'item_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbound_plans');
    }
};

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
        Schema::create('sales_event_multipliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_event_id')->constrained()->cascadeOnDelete();
            $table->string('item_no')->comment('品番コード。CrossWalkerから品番が消えても残すため、品番IDではなくコードで持つ');
            $table->string('channel', 20)->comment('販売チャネル（boss・amazon）');
            $table->decimal('multiplier', 6, 2)->comment('担当者が入れた、イベント中の売れ方の倍率（普段の平均日販の何倍か）。入れていない品番は、システムの候補を使う');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete()->comment('倍率を入れた利用者');
            $table->timestamps();

            $table->unique(['sales_event_id', 'item_no', 'channel']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_event_multipliers');
    }
};

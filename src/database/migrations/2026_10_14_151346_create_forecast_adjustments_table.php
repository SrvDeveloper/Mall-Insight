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
        Schema::create('forecast_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('item_no')->comment('品番コード。CrossWalkerから品番が消えても残すため、品番IDではなくコードで持つ');
            $table->string('channel', 20)->comment('補正するチャネル（boss・amazon）');
            $table->date('starts_on')->comment('補正を始める月（月の1日）');
            $table->date('ends_on')->nullable()->comment('補正を終える月（月の1日、この月を含む）。null は取り消すまで続ける');
            $table->unsignedSmallInteger('rate_percent')->comment('システムの予測に掛ける倍率（%）。100 で補正なし');
            $table->string('reason', 200)->comment('補正の理由');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete()->comment('登録した利用者');
            $table->timestamp('cancelled_at')->nullable()->comment('取り消した日時。取り消した補正も記録として残す');
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete()->comment('取り消した利用者');
            $table->timestamps();

            $table->index(['item_no', 'channel']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('forecast_adjustments');
    }
};

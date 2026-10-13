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
        Schema::create('item_seasonal_factors', function (Blueprint $table) {
            $table->id();
            $table->string('item_no')->comment('品番コード。CrossWalkerから品番が消えても残すため、品番IDではなくコードで持つ');
            $table->json('factors')->nullable()->comment('1月～12月の季節係数（普段の月を1として何倍売れるか）。null は登録をやめた（季節係数を使わない）記録');
            $table->json('initial_factors')->nullable()->comment('登録したときに初期値として出した、販売実績から求めた係数（根拠として残す）');
            $table->string('initial_source', 20)->nullable()->comment('初期値を求めた単位（item・category・overall）');
            $table->string('note', 500)->nullable()->comment('メモ（直した理由など）');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete()->comment('登録・変更した利用者');
            $table->timestamps();

            $table->index(['item_no', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_seasonal_factors');
    }
};

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
        Schema::create('inventory_trend_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('check_month_offset')->comment('欠品を判定する月（今月から何か月後か）');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete()->comment('変更した利用者。ログイン（B-009）より前の変更は null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_trend_settings');
    }
};

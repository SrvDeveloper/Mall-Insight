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
        Schema::create('item_selections', function (Blueprint $table) {
            $table->id();
            $table->timestamp('confirmed_at')->comment('確定日時');
            $table->date('ranking_from')->comment('売上順位の集計期間の初日');
            $table->date('ranking_to')->comment('売上順位の集計期間の末日（確定した日）');
            $table->string('note', 500)->nullable()->comment('見直しの理由などのメモ');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_selections');
    }
};

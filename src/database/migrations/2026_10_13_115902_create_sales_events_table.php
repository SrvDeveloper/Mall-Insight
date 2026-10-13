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
        Schema::create('sales_events', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('イベントの名前。同じ名前の過去のイベントから、倍率の候補を求める');
            $table->date('starts_on')->comment('初日');
            $table->date('ends_on')->comment('最終日（この日を含む）');
            $table->json('malls')->comment('イベントを行うモール（楽天市場・Yahoo!ショッピング・au PAY マーケット・Amazon のどれか1つ以上）');
            $table->string('note', 500)->nullable()->comment('メモ');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete()->comment('登録した利用者');
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete()->comment('最後に変更した利用者');
            $table->timestamps();

            $table->index(['starts_on', 'ends_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_events');
    }
};

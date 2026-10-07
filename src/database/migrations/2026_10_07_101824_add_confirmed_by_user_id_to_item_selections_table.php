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
        Schema::table('item_selections', function (Blueprint $table) {
            $table->foreignId('confirmed_by_user_id')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete()->comment('確定した利用者。ログイン（B-009）より前に確定した選定は null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('item_selections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by_user_id');
        });
    }
};

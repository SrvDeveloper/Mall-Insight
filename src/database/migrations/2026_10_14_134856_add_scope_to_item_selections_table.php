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
        // 対象品番を全体・Amazon・BOSS に分けて選ぶ（B-136、K-129）。これまでの選定は全体の選定とする
        Schema::table('item_selections', function (Blueprint $table) {
            $table->string('scope', 8)->default('total')->after('id')->comment('どの対象品番か（total：全体 / amazon / boss）');
            $table->index(['scope', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('item_selections', function (Blueprint $table) {
            $table->dropIndex(['scope', 'id']);
            $table->dropColumn('scope');
        });
    }
};

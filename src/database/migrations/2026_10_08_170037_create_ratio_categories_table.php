<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ratio_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->nullable()->unique()->comment('最初からある4区分の印（RatioCategoryCode）。画面で足した区分は null');
            $table->string('name', 50)->unique()->comment('区分の名前。画面で変えられる');
            $table->unsignedSmallInteger('position')->comment('並び順');
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete()->comment('最後に変更した利用者');
            $table->timestamps();
        });

        // 現行Excelの「月別販売比率」シートの4区分（K-049②）
        $now = now();
        DB::table('ratio_categories')->insert([
            ['code' => 'reading', 'name' => '老眼', 'position' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'pc_sunglasses', 'name' => 'PCサングラス', 'position' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'photochromic_reading', 'name' => '調光老眼', 'position' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'ready_made_myopia', 'name' => '既成近眼', 'position' => 4, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ratio_categories');
    }
};

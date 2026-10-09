<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 在庫取得は、前回取得した日時を表示できるよう最後に成功した記録を残す
        $lastSucceededSyncId = DB::table('zerostockview_syncs')->where('status', 'succeeded')->orderByDesc('finished_at')->orderByDesc('id')->value('id');

        foreach (['sales_imports' => null, 'zerostockview_syncs' => $lastSucceededSyncId] as $table => $keptId) {
            $oldestRetainedId = DB::table($table)->orderByDesc('id')->offset(19)->value('id');
            if ($oldestRetainedId !== null) {
                DB::table($table)->where('id', '<', $oldestRetainedId)->when($keptId !== null, fn ($query) => $query->where('id', '!=', $keptId))->delete();
            }
        }
    }

    /**
     * 削除した履歴は復元できない。販売実績・在庫実績は削除しない。
     */
    public function down(): void {}
};

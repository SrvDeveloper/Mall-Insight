<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * イベント（B-129）を、名前・モール・メモのイベントと、初日・最終日の期間に分ける（決定記録 K-114）。
 * 登録済みのイベントは1件ずつ期間に移し、名前（全角・半角などの違いを除く）とモールが同じイベントは1つにまとめる。
 * 担当者が入れた倍率は、移した期間に付け替える。
 */
return new class extends Migration
{
    private const UNIQUE_INDEX = 'sales_event_multipliers_period_item_channel_unique';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MySQL ではテーブルの変更が途中で失敗しても戻らないため、途中から再実行できるようにする
        if (! Schema::hasTable('sales_event_periods')) {
            Schema::create('sales_event_periods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('sales_event_id')->constrained()->cascadeOnDelete();
                $table->date('starts_on')->comment('初日');
                $table->date('ends_on')->comment('最終日（この日を含む）');
                $table->string('note', 500)->nullable()->comment('その回のメモ（ポイント倍率など）');
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete()->comment('登録した利用者');
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete()->comment('最後に変更した利用者');
                $table->timestamps();

                $table->index(['starts_on', 'ends_on']);
            });
        }

        if (! Schema::hasColumn('sales_event_multipliers', 'sales_event_period_id')) {
            Schema::table('sales_event_multipliers', function (Blueprint $table) {
                $table->foreignId('sales_event_period_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            });
        }

        // 名前とモールが同じイベントを1つにまとめ、イベントごとに期間を作る（すでに期間があれば、移し終えている）
        $keep = [];
        $events = DB::table('sales_event_periods')->exists() ? collect() : DB::table('sales_events')->orderBy('id')->get();
        foreach ($events as $event) {
            $malls = json_decode((string) $event->malls, true) ?: [];
            sort($malls);
            $key = mb_strtolower((string) preg_replace('/\s+/u', ' ', trim(mb_convert_kana((string) $event->name, 'as')))).'|'.implode(',', $malls);
            $eventId = $keep[$key] ??= $event->id;
            $periodId = DB::table('sales_event_periods')->insertGetId([
                'sales_event_id' => $eventId,
                'starts_on' => substr((string) $event->starts_on, 0, 10),
                'ends_on' => substr((string) $event->ends_on, 0, 10),
                'created_by_user_id' => $event->created_by_user_id,
                'updated_by_user_id' => $event->updated_by_user_id,
                'created_at' => $event->created_at,
                'updated_at' => $event->updated_at,
            ]);
            DB::table('sales_event_multipliers')->where('sales_event_id', $event->id)->update(['sales_event_period_id' => $periodId]);
            if ($eventId !== $event->id) {
                DB::table('sales_event_multipliers')->where('sales_event_id', $event->id)->update(['sales_event_id' => $eventId]);
                DB::table('sales_events')->where('id', $event->id)->delete();
            }
        }

        // 外部キーが使っている索引は先に消せないため、外部キー → 一意の索引 → 列の順に消す
        if (Schema::hasColumn('sales_event_multipliers', 'sales_event_id')) {
            Schema::table('sales_event_multipliers', function (Blueprint $table) {
                $table->dropForeign(['sales_event_id']);
            });
            Schema::table('sales_event_multipliers', function (Blueprint $table) {
                $table->dropUnique(['sales_event_id', 'item_no', 'channel']);
                $table->dropColumn('sales_event_id');
            });
        }
        // 既定の索引の名前は MySQL の上限（64文字）を超えるため、短い名前を付ける
        if (! Schema::hasIndex('sales_event_multipliers', self::UNIQUE_INDEX)) {
            Schema::table('sales_event_multipliers', function (Blueprint $table) {
                $table->unique(['sales_event_period_id', 'item_no', 'channel'], self::UNIQUE_INDEX);
            });
        }

        if (Schema::hasColumn('sales_events', 'starts_on')) {
            Schema::table('sales_events', function (Blueprint $table) {
                $table->dropIndex(['starts_on', 'ends_on']);
                $table->dropColumn(['starts_on', 'ends_on']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales_events', function (Blueprint $table) {
            $table->date('starts_on')->nullable()->comment('初日');
            $table->date('ends_on')->nullable()->comment('最終日（この日を含む）');
        });
        Schema::table('sales_event_multipliers', function (Blueprint $table) {
            $table->foreignId('sales_event_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // 期間ごとにイベントを分け直す（最初の期間は元のイベントに戻す）
        foreach (DB::table('sales_events')->orderBy('id')->get() as $event) {
            $periods = DB::table('sales_event_periods')->where('sales_event_id', $event->id)->orderBy('starts_on')->get();
            foreach ($periods as $index => $period) {
                $eventId = $event->id;
                if ($index === 0) {
                    DB::table('sales_events')->where('id', $event->id)->update(['starts_on' => $period->starts_on, 'ends_on' => $period->ends_on]);
                } else {
                    $eventId = DB::table('sales_events')->insertGetId([...array_diff_key((array) $event, ['id' => true]), 'starts_on' => $period->starts_on, 'ends_on' => $period->ends_on]);
                }
                DB::table('sales_event_multipliers')->where('sales_event_period_id', $period->id)->update(['sales_event_id' => $eventId]);
            }
        }
        DB::table('sales_events')->whereNull('starts_on')->delete();

        Schema::table('sales_event_multipliers', function (Blueprint $table) {
            $table->dropForeign(['sales_event_period_id']);
        });
        Schema::table('sales_event_multipliers', function (Blueprint $table) {
            $table->dropUnique(self::UNIQUE_INDEX);
            $table->dropColumn('sales_event_period_id');
            $table->unique(['sales_event_id', 'item_no', 'channel']);
        });
        Schema::table('sales_events', function (Blueprint $table) {
            $table->index(['starts_on', 'ends_on']);
        });
        Schema::dropIfExists('sales_event_periods');
    }
};

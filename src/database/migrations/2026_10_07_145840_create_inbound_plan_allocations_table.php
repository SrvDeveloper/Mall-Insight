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
        Schema::create('inbound_plan_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbound_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->comment('SKUに割り振った入荷予定数');
            $table->timestamps();

            $table->unique(['inbound_plan_id', 'sku_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inbound_plan_allocations');
    }
};

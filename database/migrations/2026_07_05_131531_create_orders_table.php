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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('g_number')->nullable();
            $table->dateTime('date');
            $table->date('last_change_date')->nullable();
            $table->string('supplier_article')->nullable();
            $table->string('tech_size')->nullable();
            $table->bigInteger('barcode');
            $table->decimal('total_price', 12, 2)->nullable();
            $table->integer('discount_percent')->nullable();
            $table->string('warehouse_name');
            $table->string('oblast')->nullable();
            $table->unsignedBigInteger('income_id')->nullable()->default(0);
            $table->unsignedBigInteger('odid')->nullable()->default(0);
            $table->bigInteger('nm_id');
            $table->string('subject')->nullable();
            $table->string('category')->nullable();
            $table->string('brand')->nullable();
            $table->boolean('is_cancel')->default(false);
            $table->date('cancel_dt')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

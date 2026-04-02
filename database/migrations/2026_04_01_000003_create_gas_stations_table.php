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
        Schema::create('gas_stations', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->string('street')->nullable();
            $table->string('postal_code', 10)->nullable();
            $table->string('city', 100)->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->boolean('is_open')->default(true);
            $table->decimal('price_diesel', 5, 3)->nullable();
            $table->decimal('price_super', 5, 3)->nullable();
            $table->unsignedTinyInteger('price_tier_diesel')->nullable();
            $table->unsignedTinyInteger('price_tier_super')->nullable();
            $table->timestamp('last_updated')->nullable();
            $table->timestamps();

            $table->index(['latitude', 'longitude']);
            $table->index('price_diesel');
            $table->index('price_super');
            $table->index('last_updated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gas_stations');
    }
};

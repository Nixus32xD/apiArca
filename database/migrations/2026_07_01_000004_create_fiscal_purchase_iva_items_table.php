<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_purchase_iva_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fiscal_purchase_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('iva_id');
            $table->decimal('rate', 5, 2)->nullable();
            $table->decimal('base_imp', 15, 2);
            $table->decimal('importe', 15, 2);
            $table->timestamps();

            $table->unique(['fiscal_purchase_id', 'iva_id'], 'fpurchase_iva_purchase_rate_unique');
            $table->index('iva_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_purchase_iva_items');
    }
};

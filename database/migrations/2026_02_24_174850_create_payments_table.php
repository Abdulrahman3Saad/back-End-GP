<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('cascade');
            // ✅ swap_id هنا من البداية — مش محتاج migration منفصل
            $table->unsignedBigInteger('swap_id')->nullable();
            $table->foreign('swap_id')->references('id')->on('car_swaps')->nullOnDelete();
            $table->foreignId('car_id')->nullable()->constrained()->onDelete('cascade');
            $table->decimal('amount', 10, 2);
            $table->enum('payment_type', ['service_fees', 'order_payment']);
            $table->enum('payment_method', ['cash', 'card', 'installment']);
            $table->enum('payment_provider', ['visa', 'mastercard', 'valu', 'nbe', 'cib', 'banquemisr'])->nullable();
            $table->integer('installment_number')->nullable();
            $table->integer('total_installments')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed', 'refunded'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('car_id')->constrained()->onDelete('cascade');
            $table->string('full_name');
            $table->string('phone');
            $table->string('governorate');
            $table->string('address');
            $table->date('expected_delivery_date');
            $table->date('pickup_date')->nullable();
            // ✅ return_date هنا مباشرةً — بدل migration منفصل يضيفها تاني
            $table->date('return_date')->nullable();
            $table->boolean('with_driver')->default(false);
            $table->decimal('car_price', 10, 2);
            // ✅ service_fees اتحذفت من orders — بتتسجل في payments تلقائياً
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('total_amount', 10, 2);
            $table->enum('order_status', [
                'pending',      // العميل حجز - بيستنى موافقة صاحب السيارة
                'confirmed',    // صاحب السيارة وافق
                'in_progress',  // السيارة بحوزة العميل (تأجير جاري)
                'rejected',     // صاحب السيارة رفض
                'canceled',     // العميل أو صاحب السيارة لغى
                'completed',    // تم التسليم والاستلام
            ])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

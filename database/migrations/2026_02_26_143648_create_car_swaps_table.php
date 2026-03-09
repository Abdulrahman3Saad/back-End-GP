<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('car_swaps', function (Blueprint $table) {
            $table->id();

            // صاحب الطلب (اللي عايز يبدل)
            $table->foreignId('requester_id')->constrained('users')->onDelete('cascade');
            // سيارة صاحب الطلب
            $table->foreignId('requester_car_id')->constrained('cars')->onDelete('cascade');
            // صاحب السيارة التانية
            $table->foreignId('receiver_id')->constrained('users')->onDelete('cascade');
            // السيارة التانية
            $table->foreignId('receiver_car_id')->constrained('cars')->onDelete('cascade');

            // بيانات التواصل
            $table->string('requester_phone');
            $table->string('requester_governorate');
            $table->string('requester_address');

            // ملاحظات
            $table->text('notes')->nullable();

            // فرق السعر
            $table->decimal('price_difference', 10, 2)->default(0);
            $table->enum('who_pays_difference', ['requester', 'receiver', 'none'])->default('none');

            // ✅ ENUM كامل من البداية — مفيش حاجة لـ ALTER migrations بعدين
            $table->enum('status', [
                'pending',         // في انتظار موافقة الأدمن
                'admin_approved',  // الأدمن وافق — في انتظار صاحب السيارة
                'accepted',        // صاحب السيارة قبل
                'rejected',        // رفض (أدمن أو صاحب السيارة)
                'completed',       // اتم التبديل فعلياً
                'canceled',        // اتلغى من صاحب الطلب
            ])->default('pending');

            $table->date('swap_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_swaps');
    }
};

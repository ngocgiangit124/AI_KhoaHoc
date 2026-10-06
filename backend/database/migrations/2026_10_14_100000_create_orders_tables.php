<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Đơn hàng (T18, US-005, data-model §3.5). Chứng từ kế toán: FK đều restrict, không cascade từ bảng cha.
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('status_reason', 100)->nullable();
            $table->unsignedInteger('subtotal_amount');
            $table->unsignedInteger('discount_amount')->default(0);
            $table->unsignedInteger('total_amount');
            $table->foreignId('coupon_id')->nullable()->constrained('coupons')->restrictOnDelete();
            $table->string('coupon_code', 50)->nullable();
            $table->dateTime('coupon_hold_until')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->boolean('needs_review')->default(false);
            $table->dateTime('expires_at');
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->dateTime('refunded_at')->nullable();
            $table->foreignId('refunded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('refund_note', 1000)->nullable();
            // 1 khi đang chờ thanh toán, NULL khi khác → unique (user_id, pending_flag): mỗi HS tối đa 1 đơn pending.
            $table->unsignedTinyInteger('pending_flag')->nullable()->storedAs("CASE WHEN status = 'pending' THEN 1 END");
            $table->timestamps();

            $table->unique(['user_id', 'pending_flag'], 'orders_user_pending_unique');
            $table->index(['status', 'created_at']);
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['coupon_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index(['needs_review', 'created_at']);
        });

        DB::statement("ALTER TABLE orders ADD CONSTRAINT chk_orders_status CHECK (status IN ('pending', 'paid', 'failed', 'cancelled', 'refunded'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT chk_orders_amounts CHECK (subtotal_amount = discount_amount + total_amount)');

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->string('course_title', 255);
            $table->unsignedInteger('unit_price');
            $table->unsignedInteger('discount_amount')->default(0);
            $table->unsignedInteger('final_amount');

            $table->unique(['order_id', 'course_id']);
            $table->index('course_id');
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT chk_order_items_amounts CHECK (unit_price = discount_amount + final_amount)');

        Schema::create('order_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('reason', 100)->nullable();
            $table->string('actor_type', 10);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('meta')->nullable();
            $table->dateTime('created_at');

            $table->index(['order_id', 'id']);
        });

        // Mỗi lần tạo giao dịch với cổng (ADR-001). `gateway_order_id` = `{order.code}-{n}`.
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('gateway', 20);
            $table->string('gateway_order_id', 64);
            $table->string('request_id', 64);
            $table->unsignedInteger('amount');
            $table->string('status', 20)->default('created');
            $table->dateTime('next_check_at')->nullable();
            $table->unsignedSmallInteger('check_count')->default(0);
            $table->dateTime('last_checked_at')->nullable();
            $table->string('pay_url', 1000)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->string('gateway_trans_id', 64)->nullable();
            $table->string('result_code', 20)->nullable();
            $table->string('result_message', 255)->nullable();
            $table->json('create_response')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'gateway_order_id']);
            $table->unique(['gateway', 'gateway_trans_id']);
            $table->index(['order_id', 'id']);
            $table->index(['status', 'created_at']);
            $table->index(['status', 'next_check_at']);
        });

        // Lượt dùng mã (ghi khi đơn paid — T19/markPaid). U (coupon_id, user_id): mỗi HS dùng mỗi mã 1 lần (US-013 BR3).
        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->dateTime('used_at');

            $table->unique('order_id');
            $table->unique(['coupon_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_usages');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('order_status_logs');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module quản lý dự án: khách hàng, tag, dự án, hosting, thanh toán, audit log.
 * Cấu trúc giữ đúng như nhánh main để import dữ liệu cũ (php artisan import:projects).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_customer_id')->nullable()->comment('KH đã giới thiệu KH này')
                ->constrained('customers')->nullOnDelete();
            $table->string('name', 255);
            $table->string('company', 255)->nullable();
            $table->string('email', 191)->nullable()->index();
            $table->string('phone', 50)->nullable()->index();
            $table->string('address', 500)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->string('color', 20)->nullable()->comment('Hex, vd: #ff8800');
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->unsignedBigInteger('cat_id')->nullable()->index()->comment('Danh mục dự án (chưa dùng)');
            $table->string('name', 255);
            $table->string('slug', 255)->unique();
            $table->string('primary_domain', 255)->nullable()->index();
            $table->string('site_key', 255)->nullable()->unique()->comment('primary_domain đã chuẩn hoá');
            $table->boolean('is_promo_active')->default(false)->index()->comment('Đã bật quảng bá tính năng (chưa dùng)');
            $table->text('domain_note')->nullable()->comment('Ghi chú đăng nhập domain');
            $table->string('tech_stack', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 30)->default('in_progress');
            $table->date('start_date')->nullable();
            $table->date('deliver_date')->nullable();
            $table->date('next_renewal_date')->nullable()->index();
            $table->decimal('price', 15, 2)->default(0);
            $table->string('currency', 10)->default('VND');
            $table->decimal('renewal_hosting_amount', 15, 2)->default(0)->comment('Giá gia hạn hosting/năm');
            $table->decimal('renewal_domain_amount', 15, 2)->default(0)->comment('Giá gia hạn domain/năm');
            $table->string('payment_status', 20)->default('unpaid');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'payment_status']);
        });

        Schema::create('project_tag', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->primary(['project_id', 'tag_id']);
        });

        Schema::create('hostings', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('provider', 255)->nullable();
            $table->string('plan_name', 255)->nullable();
            $table->string('ownership', 20)->default('mine')->index()->comment('mine = VPS của mình, customer = của khách');
            $table->string('ip', 64)->nullable();
            $table->json('specs')->nullable();
            $table->date('register_date')->nullable();
            $table->date('expire_date')->nullable()->index();
            $table->string('alert_days', 100)->default('30,15,7,1');
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('hosting_project', function (Blueprint $table) {
            $table->foreignId('hosting_id')->constrained('hostings')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->text('note')->nullable();
            $table->primary(['hosting_id', 'project_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('type', 20)->default('new')->index()->comment('new = thu mới, renewal = gia hạn');
            $table->date('paid_at');
            $table->string('method', 50)->nullable();
            $table->text('note')->nullable();
            $table->string('invoice_file_path', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('project_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('auditable_type', 100);
            $table->unsignedBigInteger('auditable_id');
            $table->unsignedBigInteger('project_id')->nullable()->index()->comment('Dự án liên quan, để xem timeline');
            $table->string('action', 30)->comment('created/updated/deleted/restored');
            $table->json('changes')->nullable()->comment('{"old":{},"new":{}}');
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name', 255)->nullable()->comment('Tên user lúc ghi log');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id'], 'idx_auditable');
        });
    }

    public function down(): void
    {
        foreach (['project_audit_logs', 'payments', 'hosting_project', 'hostings', 'project_tag', 'projects', 'tags', 'customers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

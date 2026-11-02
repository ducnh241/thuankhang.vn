<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Domain alias của dự án: cùng website (chung source code, chung database) chạy trên nhiều domain. Domain chính vẫn là
 * projects.primary_domain. site_key = domain đã chuẩn hoá (Project::normalizeSiteKey), duy nhất trong bảng này; không
 * trùng domain chính của dự án nào (kiểm tra ở ProjectRequest). Không đổi bảng projects / dữ liệu cũ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('domain', 255);
            $table->string('site_key', 255)->unique()->comment('domain đã chuẩn hoá');
            $table->string('note', 255)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['project_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_domains');
    }
};

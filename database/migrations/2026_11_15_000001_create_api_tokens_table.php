<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token API (Bearer) của tài khoản quản trị: chỉ lưu SHA-256 của token (bản gốc hiện một lần khi tạo). Token kế thừa
 * quyền của tài khoản (nhóm quyền); khóa tài khoản / mất quyền backend thì token hết tác dụng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->char('token_hash', 64)->unique();
            $table->string('prefix', 12)->comment('Vài ký tự đầu để nhận diện token');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};

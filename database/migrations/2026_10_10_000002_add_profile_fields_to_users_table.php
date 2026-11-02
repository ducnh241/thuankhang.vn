<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('avatar')->nullable()->after('phone');
            $table->boolean('is_super_admin')->default(false)->after('password');
            $table->boolean('status')->default(true)->after('is_super_admin');
            $table->foreignId('group_id')->nullable()->after('status')->constrained('groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
            $table->dropColumn(['phone', 'avatar', 'is_super_admin', 'status']);
        });
    }
};

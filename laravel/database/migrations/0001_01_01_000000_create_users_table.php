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
        if (!Schema::hasTable('User')) {
            Schema::create('User', function (Blueprint $table): void {
                $table->increments('id');
                $table->timestamp('createdAt')->useCurrent();
                $table->timestamp('updatedAt')->useCurrent();
                $table->string('username')->unique();
                $table->text('name')->nullable();
                $table->text('grade')->nullable();
                $table->text('class')->nullable();
                $table->text('avatar')->nullable();
                $table->text('role')->default('USER');
                $table->text('password');
                $table->text('email')->nullable();
                $table->boolean('emailVerified')->default(false);
                $table->timestamp('lastLogin')->nullable();
                $table->text('lastLoginIp')->nullable();
                $table->timestamp('passwordChangedAt')->nullable();
                $table->boolean('forcePasswordChange')->default(true);
                $table->string('status', 20)->default('active');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('User');
    }
};

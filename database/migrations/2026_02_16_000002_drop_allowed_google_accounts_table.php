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
        Schema::dropIfExists('allowed_google_accounts');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('allowed_google_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('default_role_slug')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['email', 'is_active']);
        });
    }
};

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
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_subject')->nullable()->unique()->after('email');
            $table->string('google_avatar_url')->nullable()->after('avatar_url');
            $table->timestamp('last_google_login_at')->nullable()->after('email_verified_at');
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_subject']);
            $table->dropColumn([
                'google_subject',
                'google_avatar_url',
                'last_google_login_at',
            ]);
            $table->string('password')->nullable(false)->change();
        });
    }
};

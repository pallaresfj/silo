<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'auth_subject')) {
                $table->string('auth_subject')->nullable()->after('email');
                $table->unique('auth_subject');
            }

            if (! Schema::hasColumn('users', 'institution_code')) {
                $table->string('institution_code', 100)->nullable()->after('auth_subject');
                $table->index('institution_code');
            }

            if (! Schema::hasColumn('users', 'last_sso_login_at')) {
                $table->timestamp('last_sso_login_at')->nullable()->after('last_google_login_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'last_sso_login_at')) {
                $table->dropColumn('last_sso_login_at');
            }

            if (Schema::hasColumn('users', 'institution_code')) {
                $table->dropIndex(['institution_code']);
                $table->dropColumn('institution_code');
            }

            if (Schema::hasColumn('users', 'auth_subject')) {
                $table->dropUnique(['auth_subject']);
                $table->dropColumn('auth_subject');
            }
        });
    }
};

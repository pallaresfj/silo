<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('documents', 'storage_scope')) {
            return;
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->string('storage_scope', 32)
                ->default('yearly')
                ->after('year')
                ->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('documents', 'storage_scope')) {
            return;
        }

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex(['storage_scope']);
            $table->dropColumn('storage_scope');
        });
    }
};

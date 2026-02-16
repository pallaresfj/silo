<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')
            ->where('code', 'whitelist.manage')
            ->value('id');

        if (! $permissionId) {
            return;
        }

        if (Schema::hasTable('permission_role')) {
            DB::table('permission_role')
                ->where('permission_id', $permissionId)
                ->delete();
        }

        DB::table('permissions')
            ->where('id', $permissionId)
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $exists = DB::table('permissions')
            ->where('code', 'whitelist.manage')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('permissions')->insert([
            'code' => 'whitelist.manage',
            'description' => 'Gestionar lista blanca de Google',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};

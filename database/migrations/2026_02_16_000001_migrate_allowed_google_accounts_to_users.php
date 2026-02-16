<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('allowed_google_accounts')) {
            return;
        }

        if (! Schema::hasTable('users') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_user')) {
            throw new RuntimeException('Cannot migrate allowed Google accounts: required RBAC tables are missing.');
        }

        $now = now();

        $fallbackRoleId = $this->ensureFallbackReaderRole($now);

        DB::table('allowed_google_accounts')
            ->where('is_active', true)
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use ($fallbackRoleId, $now): void {
                foreach ($accounts as $account) {
                    $email = Str::lower(trim((string) ($account->email ?? '')));

                    if ($email === '') {
                        continue;
                    }

                    $role = $this->resolveTargetRole((string) ($account->default_role_slug ?? ''), $fallbackRoleId);

                    if (! $role) {
                        continue;
                    }

                    $user = DB::table('users')->where('email', $email)->first();

                    if (! $user) {
                        $userId = DB::table('users')->insertGetId([
                            'name' => $this->displayNameFromEmail($email),
                            'email' => $email,
                            'role' => '',
                            'password' => null,
                            'avatar_url' => null,
                            'email_verified_at' => null,
                            'remember_token' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        $hasAnyRole = false;
                    } else {
                        $userId = (int) $user->id;
                        $hasAnyRole = DB::table('role_user')->where('user_id', $userId)->exists()
                            || trim((string) ($user->role ?? '')) !== '';
                    }

                    if ($hasAnyRole) {
                        continue;
                    }

                    $alreadyLinked = DB::table('role_user')
                        ->where('role_id', $role->id)
                        ->where('user_id', $userId)
                        ->exists();

                    if (! $alreadyLinked) {
                        DB::table('role_user')->insert([
                            'role_id' => $role->id,
                            'user_id' => $userId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    DB::table('users')
                        ->where('id', $userId)
                        ->update([
                            'role' => $role->slug,
                            'updated_at' => $now,
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op: this migration only copies access data forward.
    }

    protected function ensureFallbackReaderRole($now): int
    {
        $readerRoleId = DB::table('roles')->where('slug', 'lector')->value('id');

        if ($readerRoleId) {
            return (int) $readerRoleId;
        }

        $readerRoleId = (int) DB::table('roles')->insertGetId([
            'slug' => 'lector',
            'name' => 'Lector',
            'is_system' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (Schema::hasTable('permissions') && Schema::hasTable('permission_role')) {
            $documentsViewPermissionId = DB::table('permissions')
                ->where('code', 'documents.view')
                ->value('id');

            if ($documentsViewPermissionId) {
                $alreadyAttached = DB::table('permission_role')
                    ->where('permission_id', $documentsViewPermissionId)
                    ->where('role_id', $readerRoleId)
                    ->exists();

                if (! $alreadyAttached) {
                    DB::table('permission_role')->insert([
                        'permission_id' => $documentsViewPermissionId,
                        'role_id' => $readerRoleId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        return $readerRoleId;
    }

    protected function resolveTargetRole(string $defaultRoleSlug, int $fallbackRoleId): ?object
    {
        $defaultRoleSlug = Str::lower(trim($defaultRoleSlug));

        if ($defaultRoleSlug !== '') {
            $matchedRole = DB::table('roles')->where('slug', $defaultRoleSlug)->first();

            if ($matchedRole) {
                return $matchedRole;
            }
        }

        return DB::table('roles')->where('id', $fallbackRoleId)->first();
    }

    protected function displayNameFromEmail(string $email): string
    {
        $localPart = Str::before($email, '@');
        $candidate = Str::of($localPart)
            ->replace(['.', '_', '-'], ' ')
            ->squish()
            ->title()
            ->value();

        return $candidate !== '' ? $candidate : $email;
    }
};

<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            'documents.view' => 'Ver documentos publicados',
            'documents.view_all_states' => 'Ver documentos en cualquier estado',
            'documents.create' => 'Crear documentos',
            'documents.update' => 'Editar documentos',
            'documents.delete' => 'Eliminar documentos',
            'categories.view' => 'Ver categorías',
            'categories.manage' => 'Gestionar categorías',
            'entities.view' => 'Ver entidades',
            'entities.manage' => 'Gestionar entidades',
            'users.manage' => 'Gestionar usuarios',
            'roles.manage' => 'Gestionar roles',
        ];

        foreach ($permissions as $code => $description) {
            Permission::query()->updateOrCreate(
                ['code' => $code],
                ['description' => $description]
            );
        }

        $allPermissionIds = Permission::query()->pluck('id')->all();

        $rolePermissions = [
            'rector' => $allPermissionIds,
            'administrador' => $allPermissionIds,
            'editor' => Permission::query()
                ->whereIn('code', [
                    'documents.view',
                    'documents.create',
                    'documents.update',
                    'categories.view',
                    'entities.view',
                ])
                ->pluck('id')
                ->all(),
            'lector' => Permission::query()
                ->whereIn('code', ['documents.view'])
                ->pluck('id')
                ->all(),
        ];

        $roleLabels = [
            'rector' => 'Rector',
            'administrador' => 'Administrador',
            'editor' => 'Editor',
            'lector' => 'Lector',
        ];

        foreach ($rolePermissions as $slug => $permissionIds) {
            $role = Role::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $roleLabels[$slug],
                    'is_system' => true,
                ]
            );

            $role->permissions()->sync($permissionIds);
        }

        $legacyMap = [
            'rector' => 'rector',
            'administrador' => 'administrador',
            'editor' => 'editor',
            'docente' => 'lector',
            'lector' => 'lector',
        ];

        $rolesBySlug = Role::query()
            ->whereIn('slug', array_values($legacyMap))
            ->get()
            ->keyBy('slug');

        User::query()
            ->whereNotNull('role')
            ->chunkById(100, function ($users) use ($legacyMap, $rolesBySlug): void {
                foreach ($users as $user) {
                    $slug = $legacyMap[Str::lower((string) $user->role)] ?? null;

                    if (! $slug || ! isset($rolesBySlug[$slug])) {
                        continue;
                    }

                    $user->roles()->syncWithoutDetaching([$rolesBySlug[$slug]->id]);
                }
            });
    }
}

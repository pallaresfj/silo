<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'auth_subject',
        'institution_code',
        'google_subject',
        'password',
        'avatar_url',
        'google_avatar_url',
        'role',
        'last_google_login_at',
        'last_sso_login_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_google_login_at' => 'datetime',
            'last_sso_login_at' => 'datetime',
        ];
    }

    /**
     * Determine if the user can access the Filament panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->hasRbacRoleTables() && $this->roles()->exists()) {
            return true;
        }

        return filled($this->role);
    }

    /**
     * Get the avatar URL for Filament sidebar.
     */
    public function getFilamentAvatarUrl(): ?string
    {
        $googleAvatarUrl = trim((string) $this->google_avatar_url);

        if ($googleAvatarUrl === '' || ! filter_var($googleAvatarUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $googleAvatarUrl;
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function hasRole(string $slug): bool
    {
        return $this->hasAnyRole([$slug]);
    }

    /**
     * @param  array<int, string>  $slugs
     */
    public function hasAnyRole(array $slugs): bool
    {
        $slugs = array_map(fn (string $slug): string => Str::lower($slug), $slugs);

        if ($this->hasRbacRoleTables() && $this->relationLoaded('roles') && $this->roles->isNotEmpty()) {
            return $this->roles
                ->pluck('slug')
                ->map(fn (string $slug): string => Str::lower($slug))
                ->intersect($slugs)
                ->isNotEmpty();
        }

        if ($this->hasRbacRoleTables() && $this->roles()->exists()) {
            return $this->roles()
                ->whereIn('slug', $slugs)
                ->exists();
        }

        if (blank($this->role)) {
            return false;
        }

        return in_array(Str::lower((string) $this->role), $slugs, true);
    }

    public function hasPermission(string $code): bool
    {
        $code = Str::lower($code);

        if (
            $this->hasRbacPermissionTables() &&
            $this->roles()->whereHas('permissions', fn ($query) => $query->where('code', $code))->exists()
        ) {
            return true;
        }

        $legacyRole = Str::lower((string) $this->role);
        $legacyPermissions = static::legacyRolePermissions()[$legacyRole] ?? [];

        return in_array($code, $legacyPermissions, true);
    }

    protected function hasRbacRoleTables(): bool
    {
        return Schema::hasTable('roles') && Schema::hasTable('role_user');
    }

    protected function hasRbacPermissionTables(): bool
    {
        return $this->hasRbacRoleTables()
            && Schema::hasTable('permissions')
            && Schema::hasTable('permission_role');
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function legacyRolePermissions(): array
    {
        return [
            'rector' => [
                'documents.view',
                'documents.view_all_states',
                'documents.create',
                'documents.update',
                'documents.delete',
                'categories.view',
                'categories.manage',
                'entities.view',
                'entities.manage',
                'users.manage',
                'roles.manage',
            ],
            'administrador' => [
                'documents.view',
                'documents.view_all_states',
                'documents.create',
                'documents.update',
                'documents.delete',
                'categories.view',
                'categories.manage',
                'entities.view',
                'entities.manage',
                'users.manage',
                'roles.manage',
            ],
            'editor' => [
                'documents.view',
                'documents.create',
                'documents.update',
                'categories.view',
                'entities.view',
            ],
            'docente' => [
                'documents.view',
            ],
            'lector' => [
                'documents.view',
            ],
        ];
    }
}

<?php

namespace Agroista\Core\Auth;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LocalUserProvisioner
{
    /**
     * @param  class-string<Model>  $userModel
     * @param  array<string, mixed>  $claims
     */
    public function upsert(string $userModel, array $claims): Model
    {
        $email = Str::lower(trim((string) ($claims['email'] ?? '')));

        if ($email === '') {
            throw new \InvalidArgumentException('Missing email claim.');
        }

        $subject = trim((string) ($claims['sub'] ?? ''));
        $name = trim((string) ($claims['name'] ?? $email));
        $institutionCode = trim((string) ($claims['institution_code'] ?? config('agroista-core.institution.default_code', 'default')));
        $avatar = trim((string) ($claims['picture'] ?? $claims['avatar'] ?? $claims['google_avatar_url'] ?? ''));
        $avatarUrl = filter_var($avatar, FILTER_VALIDATE_URL) ? $avatar : null;

        /** @var Model|null $existing */
        $existing = $userModel::query()
            ->when($subject !== '' && $this->supportsColumn($userModel, 'auth_subject'), fn ($q) => $q->where('auth_subject', $subject))
            ->orWhere('email', $email)
            ->first();

        $attributes = ['email' => $email];

        if ($existing && $subject !== '' && $this->supportsColumn($userModel, 'auth_subject')) {
            $attributes = ['id' => $existing->getKey()];
        }

        $payload = array_filter([
            'name' => $name === '' ? $email : $name,
            'auth_subject' => $subject !== '' ? $subject : null,
            'institution_code' => $institutionCode !== '' ? $institutionCode : null,
            'google_avatar_url' => $avatarUrl,
            'last_sso_login_at' => now(),
            'password' => $existing ? null : Hash::make(Str::password(40)),
            'email_verified_at' => $existing ? null : now(),
        ], static fn (mixed $value): bool => $value !== null);

        /** @var Model $user */
        $user = $userModel::query()->updateOrCreate($attributes, $payload);

        return $user;
    }

    private function supportsColumn(string $userModel, string $column): bool
    {
        /** @var Model $instance */
        $instance = new $userModel();

        return in_array($column, $instance->getFillable(), true);
    }
}

<?php

namespace App\Support\Institution;

use Agroista\Core\Institution\InstitutionContext;
use Throwable;

class InstitutionTheme
{
    /**
     * @return array<string, string>
     */
    public static function filamentColors(): array
    {
        $defaults = [
            'primary' => '#1d6362',
            'success' => '#6b9a34',
            'info' => '#99ce93',
            'warning' => '#f8c508',
            'danger' => '#f50404',
        ];

        try {
            /** @var InstitutionContext $context */
            $context = app(InstitutionContext::class);
            $institution = $context->institution();
        } catch (Throwable) {
            return $defaults;
        }

        $primary = trim((string) ($institution['primary_color'] ?? ''));
        $secondary = trim((string) ($institution['secondary_color'] ?? ''));

        if ($primary !== '') {
            $defaults['primary'] = $primary;
        }

        if ($secondary !== '') {
            $defaults['success'] = $secondary;
        }

        return $defaults;
    }
}

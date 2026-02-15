<?php

namespace App\Support;

final class GoogleDriveUrl
{
    public static function resolve(?string $storedUrl, ?string $fileId, ?string $email = null): ?string
    {
        $url = trim((string) $storedUrl);

        if ($url === '') {
            $normalizedId = trim((string) $fileId);

            if ($normalizedId === '') {
                return null;
            }

            $url = "https://drive.google.com/file/d/{$normalizedId}/view";
        }

        $url = static::stripAccountBoundParams($url);

        $normalizedEmail = trim((string) $email);

        if ($normalizedEmail === '') {
            return $url;
        }

        return static::replaceQueryParam($url, 'authuser', $normalizedEmail);
    }

    protected static function stripAccountBoundParams(string $url): string
    {
        return static::replaceQueryParam($url, 'ouid', null);
    }

    protected static function replaceQueryParam(string $url, string $param, ?string $value): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return $url;
        }

        $query = [];

        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
        }

        if ($value === null) {
            unset($query[$param]);
        } else {
            $query[$param] = $value;
        }

        $rebuiltQuery = http_build_query($query);

        $result = '';

        if (isset($parts['scheme'])) {
            $result .= $parts['scheme'] . '://';
        }

        if (isset($parts['user'])) {
            $result .= $parts['user'];

            if (isset($parts['pass'])) {
                $result .= ':' . $parts['pass'];
            }

            $result .= '@';
        }

        $result .= $parts['host'] ?? '';

        if (isset($parts['port'])) {
            $result .= ':' . $parts['port'];
        }

        $result .= $parts['path'] ?? '';

        if ($rebuiltQuery !== '') {
            $result .= '?' . $rebuiltQuery;
        }

        if (isset($parts['fragment'])) {
            $result .= '#' . $parts['fragment'];
        }

        return $result;
    }
}


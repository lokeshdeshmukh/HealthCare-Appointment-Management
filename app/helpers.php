<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Session;

function env(string $key, mixed $default = null): mixed
{
    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

function base_path(string $path = ''): string
{
    $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);

    return $path ? $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $base;
}

function storage_path(string $path = ''): string
{
    $base = base_path('storage');

    return $path ? $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $base;
}

function public_path(string $path = ''): string
{
    $base = base_path('public');

    return $path ? $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $base;
}

function view_path(string $path = ''): string
{
    $base = base_path('resources/views');

    return $path ? $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $base;
}

function url(string $path = ''): string
{
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }

    $root = rtrim(request_origin(), '/');
    $path = ltrim($path, '/');

    return $path === '' ? $root : $root . '/' . $path;
}

function asset(string $path): string
{
    $assetPath = 'assets/' . ltrim($path, '/');
    $assetUrl = url($assetPath);
    $version = trim((string) (config('app.build.commit') ?: config('app.build.version') ?: ''));

    if ($version === '') {
        $fullPath = public_path($assetPath);
        if (is_file($fullPath)) {
            $version = (string) filemtime($fullPath);
        }
    }

    if ($version === '') {
        return $assetUrl;
    }

    return $assetUrl . (str_contains($assetUrl, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
}

function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    if ($value instanceof Stringable || is_scalar($value)) {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return htmlspecialchars($encoded === false ? '' : $encoded, ENT_QUOTES, 'UTF-8');
}

function old(string $key, mixed $default = ''): mixed
{
    return Session::getFlashInput($key, $default);
}

function flash(string $key, string $message): void
{
    Session::flash($key, $message);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
}

function selected(mixed $left, mixed $right): string
{
    return (string) $left === (string) $right ? 'selected' : '';
}

function checked(mixed $left, mixed $right): string
{
    return (string) $left === (string) $right ? 'checked' : '';
}

function normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function normalize_phone(string $phone): string
{
    return preg_replace('/\D+/', '', trim($phone)) ?? '';
}

function split_name(string $value, string $fallback = 'Patient'): array
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if ($value === '') {
        $value = $fallback;
    }

    $parts = preg_split('/\s+/', $value) ?: [$fallback];
    $first = trim((string) ($parts[0] ?? $fallback));
    $last = trim(implode(' ', array_slice($parts, 1)));

    return [
        'first_name' => $first !== '' ? $first : $fallback,
        'last_name' => $last !== '' ? $last : 'User',
    ];
}

function request_base_path(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = rtrim(dirname($scriptName), '/');

    return $basePath === '/' ? '' : $basePath;
}

function request_origin(): string
{
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    if ($host === '') {
        return (string) config('app.url', 'http://localhost');
    }

    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $basePath = request_base_path();

    return rtrim($scheme . $host . $basePath, '/');
}

function current_clinic(): ?array
{
    return \App\Core\ClinicContext::current();
}

function clinic_is_scoped(): bool
{
    return \App\Core\ClinicContext::isScoped();
}

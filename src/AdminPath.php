<?php

declare(strict_types=1);

namespace WgPanel;

use RuntimeException;

final class AdminPath
{
    /** @var list<string> */
    private const RESERVED_SLUGS = [
        'api', 'assets', 'create', 'download', 'edit', 'index', 'login', 'logout',
        'online-status', 'panel-qr', 'qr', 's', 'settings', 'subscribe',
        'subscribe-download', 'subscribe-qr', 'view',
    ];

    public static function slug(array $config): string
    {
        $raw = trim((string) ($config['admin']['login_path'] ?? ''));

        return $raw === '' ? '' : self::normalizeSlug($raw);
    }

    public static function isCustom(array $config): bool
    {
        return self::slug($config) !== '';
    }

    /** Public URL path, e.g. /login.php or /mandooli-x7k9 */
    public static function url(array $config): string
    {
        $slug = self::slug($config);

        return $slug === '' ? '/login.php' : '/' . $slug;
    }

    public static function normalizeSlug(string $input): string
    {
        $slug = trim($input, "/ \t\n\r\0\x0B");

        if ($slug === '') {
            return '';
        }

        if (!preg_match('/^[a-zA-Z0-9_-]{4,48}$/', $slug)) {
            throw new RuntimeException(
                'مسیر ورود باید ۴ تا ۴۸ کاراکتر و فقط شامل حروف، اعداد، خط تیره و زیرخط باشد.'
            );
        }

        if (in_array(strtolower($slug), self::RESERVED_SLUGS, true)) {
            throw new RuntimeException('این مسیر رزرو شده است و قابل استفاده نیست.');
        }

        return $slug;
    }

    public static function requestPath(): string
    {
        $uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        if (!is_string($uri) || $uri === '') {
            return '/';
        }

        return $uri;
    }

    public static function isLoginRequest(array $config): bool
    {
        $path = self::requestPath();
        $loginUrl = self::url($config);

        return $path === $loginUrl || $path === $loginUrl . '/';
    }

    public static function blockDirectLogin(array $config): void
    {
        if (!self::isCustom($config)) {
            return;
        }

        $path = self::requestPath();

        if ($path === '/login.php') {
            self::notFound();
        }
    }

    public static function notFound(): never
    {
        $page = dirname(__DIR__) . '/public/404.php';

        if (is_file($page)) {
            require $page;
        } else {
            http_response_code(404);
        }

        exit;
    }

    public static function generateSlug(): string
    {
        return 'adm-' . bin2hex(random_bytes(4));
    }
}

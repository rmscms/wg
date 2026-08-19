<?php

declare(strict_types=1);

if (defined('WG_PANEL_BOOTSTRAP')) {
    return;
}

define('WG_PANEL_BOOTSTRAP', true);

if (session_status() === PHP_SESSION_NONE) {
    $sessionDir = dirname(__DIR__) . '/storage/sessions';

    if (!is_dir($sessionDir)) {
        mkdir($sessionDir, 0750, true);
    }

    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

$configPath = dirname(__DIR__) . '/config/config.php';

if (!is_file($configPath)) {
    http_response_code(500);
    exit('Configuration file not found. Copy config/config.example.php to config/config.php');
}

$config = require $configPath;
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('Dependencies not installed. Run: composer install --no-dev -o');
}
require_once $autoload;

$db = WgPanel\Database::connect($config);
$seededGroups = WgPanel\SettingsStore::ensureSeeded($db, $config);
$config = WgPanel\SettingsStore::overlay($db, $config);
date_default_timezone_set($config['app']['timezone'] ?? 'UTC');
$wgManager = new WgPanel\WireGuardManager($db, $config);
queueDatabaseUpgradeNotice(
    WgPanel\Database::consumeUpgradeNotes(),
    $seededGroups
);

function isLoggedIn(): bool
{
    return !empty($_SESSION['wg_admin']);
}

function adminLoginPath(?array $cfg = null): string
{
    global $config;

    return WgPanel\AdminPath::url($cfg ?? $config);
}

function redirectToAdminLogin(): void
{
    redirect(adminLoginPath());
}

function loginThrottle(): WgPanel\LoginThrottle
{
    static $throttle = null;

    if ($throttle === null) {
        $throttle = new WgPanel\LoginThrottle(dirname(__DIR__) . '/storage/login-throttle');
    }

    return $throttle;
}

function backupManager(): WgPanel\BackupManager
{
    static $manager = null;
    static $dir = null;

    global $config;

    $resolved = WgPanel\BackupManager::resolveStorageDir($config, dirname(__DIR__));

    if ($manager === null || $dir !== $resolved) {
        $manager = new WgPanel\BackupManager($resolved);
        $dir = $resolved;
    }

    return $manager;
}

function savePanelSettings(array $changes): void
{
    global $db, $config;

    $configPath = dirname(__DIR__) . '/config/config.php';
    $config = WgPanel\SettingsStore::update($db, $config, $changes, $configPath);
}

function requireLogin(): void
{
    global $config;

    if (isLoggedIn()) {
        return;
    }

    $loginPath = adminLoginPath($config);
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $uriPath = WgPanel\AdminPath::requestPath();

    if ($uri !== '' && $uriPath !== $loginPath && $uriPath !== '/login.php') {
        $_SESSION['login_redirect'] = $uri;
    }

    if (WgPanel\AdminPath::isCustom($config)) {
        WgPanel\AdminPath::notFound();
    }

    header('Location: ' . $loginPath);
    exit;
}

function consumeLoginRedirect(string $fallback = '/'): string
{
    global $config;

    $loginPath = adminLoginPath($config);
    $target = $_SESSION['login_redirect'] ?? $fallback;
    unset($_SESSION['login_redirect']);

    if (!is_string($target) || $target === '' || $target === '/login.php' || $target === $loginPath) {
        return $fallback;
    }

    if (!str_starts_with($target, '/')) {
        return $fallback;
    }

    return $target;
}

function verifyLogin(array $config, string $username, string $password): bool
{
    return hash_equals($config['admin']['username'], $username)
        && password_verify($password, $config['admin']['password_hash']);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(?string $token): bool
{
    return is_string($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** @return array<string, string> */
function dashboardStatusFilterOptions(): array
{
    return [
        '' => 'همه',
        'active' => 'فعال',
        'inactive' => 'غیرفعال',
        'expired' => 'منقضی',
        'volume' => 'حجم تمام',
        'expiring' => 'انقضای ۲۴ ساعت',
        'pending' => 'در انتظار اتصال',
    ];
}

function dashboardParseDateParam(mixed $value): string
{
    $value = trim((string) $value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return '';
    }

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($dt === false || $dt->format('Y-m-d') !== $value) {
        return '';
    }

    return $value;
}

/** @param array<string, mixed> $state */
function dashboardAccountFilters(array $state): array
{
    return [
        'status' => (string) ($state['status'] ?? ''),
        'created_from' => (string) ($state['created_from'] ?? ''),
        'created_to' => (string) ($state['created_to'] ?? ''),
        'expires_from' => (string) ($state['expires_from'] ?? ''),
        'expires_to' => (string) ($state['expires_to'] ?? ''),
    ];
}

/** @param array<string, mixed> $state */
function dashboardListIsFiltered(array $state, bool $includeSearch = true): bool
{
    if ($includeSearch && trim((string) ($state['search'] ?? '')) !== '') {
        return true;
    }

    foreach (['status', 'created_from', 'created_to', 'expires_from', 'expires_to'] as $key) {
        if (trim((string) ($state[$key] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

/** Build dashboard URL with search / filters / pagination query params. */
function dashboardUrl(array $state, ?int $page = null): string
{
    if ($page !== null) {
        $state['page'] = max(1, $page);
    }

    $params = [];
    $search = trim((string) ($state['search'] ?? ''));
    if ($search !== '') {
        $params['q'] = $search;
    }

    $pageNum = max(1, (int) ($state['page'] ?? 1));
    if ($pageNum > 1) {
        $params['page'] = $pageNum;
    }

    $perPage = max(5, min(100, (int) ($state['per_page'] ?? 20)));
    if ($perPage !== 20) {
        $params['per_page'] = $perPage;
    }

    $status = (string) ($state['status'] ?? '');
    if ($status !== '' && array_key_exists($status, dashboardStatusFilterOptions())) {
        $params['status'] = $status;
    }

    foreach (['created_from', 'created_to', 'expires_from', 'expires_to'] as $key) {
        $date = dashboardParseDateParam($state[$key] ?? '');
        if ($date !== '') {
            $params[$key] = $date;
        }
    }

    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    return '/' . ($query !== '' ? '?' . $query : '');
}

function dashboardStateFromRequest(): array
{
    $search = trim((string) ($_GET['q'] ?? $_POST['list_q'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? $_POST['list_page'] ?? 1));
    $perPage = max(5, min(100, (int) ($_GET['per_page'] ?? $_POST['list_per_page'] ?? 20)));
    $status = (string) ($_GET['status'] ?? $_POST['list_status'] ?? '');
    if (!array_key_exists($status, dashboardStatusFilterOptions())) {
        $status = '';
    }

    return [
        'search' => $search,
        'page' => $page,
        'per_page' => $perPage,
        'status' => $status,
        'created_from' => dashboardParseDateParam($_GET['created_from'] ?? $_POST['list_created_from'] ?? ''),
        'created_to' => dashboardParseDateParam($_GET['created_to'] ?? $_POST['list_created_to'] ?? ''),
        'expires_from' => dashboardParseDateParam($_GET['expires_from'] ?? $_POST['list_expires_from'] ?? ''),
        'expires_to' => dashboardParseDateParam($_GET['expires_to'] ?? $_POST['list_expires_to'] ?? ''),
    ];
}

function dashboardListFields(array $state): string
{
    return implode('', [
        '<input type="hidden" name="list_q" value="' . e((string) ($state['search'] ?? '')) . '">',
        '<input type="hidden" name="list_page" value="' . (int) ($state['page'] ?? 1) . '">',
        '<input type="hidden" name="list_per_page" value="' . (int) ($state['per_page'] ?? 20) . '">',
        '<input type="hidden" name="list_status" value="' . e((string) ($state['status'] ?? '')) . '">',
        '<input type="hidden" name="list_created_from" value="' . e((string) ($state['created_from'] ?? '')) . '">',
        '<input type="hidden" name="list_created_to" value="' . e((string) ($state['created_to'] ?? '')) . '">',
        '<input type="hidden" name="list_expires_from" value="' . e((string) ($state['expires_from'] ?? '')) . '">',
        '<input type="hidden" name="list_expires_to" value="' . e((string) ($state['expires_to'] ?? '')) . '">',
    ]);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * @param list<string> $migrationNotes
 * @param list<string> $seededGroups
 */
function queueDatabaseUpgradeNotice(array $migrationNotes, array $seededGroups): void
{
    $parts = $migrationNotes;

    if ($seededGroups !== []) {
        $parts[] = 'کپی تنظیمات از فایل به دیتابیس';
    }

    if ($parts === []) {
        consumePendingUpgradeNotice();

        return;
    }

    $message = 'به‌روزرسانی دیتابیس انجام شد: ' . implode('، ', $parts) . '.';

    if (isLoggedIn()) {
        flash('success', $message);

        return;
    }

    $_SESSION['pending_upgrade_notice'] = $message;
}

function consumePendingUpgradeNotice(): void
{
    if (!isLoggedIn() || empty($_SESSION['pending_upgrade_notice'])) {
        return;
    }

    if (empty($_SESSION['flash'])) {
        flash('success', (string) $_SESSION['pending_upgrade_notice']);
    }

    unset($_SESSION['pending_upgrade_notice']);
}

consumePendingUpgradeNotice();


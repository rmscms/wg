<?php

declare(strict_types=1);

if (!defined('WG_PANEL_BOOTSTRAP')) {
    require_once __DIR__ . '/bootstrap.php';
}

http_response_code(404);

$pageTitle     = 'صفحه پیدا نشد';
$bodyClass     = 'error-body';
$pageStyles    = ['/assets/error.css'];
$suppressFlash = true;

require __DIR__ . '/includes/header.php';
?>

<div class="error-wrap">
    <div class="error-card">
        <div class="error-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="9"/>
                <path d="M12 8v5"/>
                <circle cx="12" cy="16.5" r=".8" fill="currentColor" stroke="none"/>
            </svg>
        </div>

        <p class="error-code" aria-hidden="true">404</p>
        <h1 class="error-title">صفحه مورد نظر یافت نشد</h1>
        <p class="error-text">
            آدرسی که وارد کرده‌اید وجود ندارد یا دیگر در دسترس نیست.
        </p>

        <div class="error-divider" aria-hidden="true"></div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>

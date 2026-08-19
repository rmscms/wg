<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

WgPanel\AdminPath::blockDirectLogin($config);

if (!empty($_GET['return']) && is_string($_GET['return']) && str_starts_with($_GET['return'], '/')) {
    $_SESSION['login_redirect'] = $_GET['return'];
}

$throttle = loginThrottle();
$loginLocked = $throttle->isBlocked();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($loginLocked) {
        flash('danger', $throttle->lockMessage());
    } elseif (verifyLogin($config, $username, $password)) {
        $throttle->clear();
        session_regenerate_id(true);
        $_SESSION['wg_admin'] = true;
        redirect(consumeLoginRedirect('/'));
    } else {
        $throttle->recordFailure();
        flash('danger', $throttle->failureMessage());
    }

    $loginLocked = $throttle->isBlocked();
}

if (isLoggedIn()) {
    redirect(consumeLoginRedirect('/'));
}

$pageTitle     = 'ورود';
$bodyClass     = 'login-body';
$pageStyles    = ['/assets/login.css'];
$suppressFlash = true;
require __DIR__ . '/includes/header.php';
?>

<div class="login-wrap">
    <div class="login-card">

        <!-- Logo -->
        <div class="login-logo" aria-hidden="true">
            <svg viewBox="0 0 48 48" fill="none">
                <path d="M24 4L6 12v14c0 9.4 7.7 18.2 18 20 10.3-1.8 18-10.6 18-20V12L24 4Z"
                      fill="url(#lgg)" opacity=".18"/>
                <path d="M24 4L6 12v14c0 9.4 7.7 18.2 18 20 10.3-1.8 18-10.6 18-20V12L24 4Z"
                      stroke="url(#lgg)" stroke-width="1.8" stroke-linejoin="round"/>
                <circle cx="24" cy="22" r="4.5" stroke="url(#lgg)" stroke-width="1.8"/>
                <path d="M24 26.5v7" stroke="url(#lgg)" stroke-width="1.8" stroke-linecap="round"/>
                <defs>
                    <linearGradient id="lgg" x1="6" y1="4" x2="42" y2="44" gradientUnits="userSpaceOnUse">
                        <stop offset="0%" stop-color="#60a5fa"/>
                        <stop offset="100%" stop-color="#818cf8"/>
                    </linearGradient>
                </defs>
            </svg>
        </div>

        <h1 class="login-title">WireGuard Panel</h1>
        <p class="login-sub">ورود به پنل مدیریت</p>

        <?php if ($flash = getFlash()): ?>
            <div class="login-alert login-alert-<?= e($flash['type']) ?>">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                    <circle cx="8" cy="8" r="6.5"/>
                    <line x1="8" y1="5" x2="8" y2="8.5"/>
                    <circle cx="8" cy="11" r=".7" fill="currentColor" stroke="none"/>
                </svg>
                <?= e($flash['message']) ?>
            </div>
        <?php elseif ($loginLocked): ?>
            <div class="login-alert login-alert-danger">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                    <circle cx="8" cy="8" r="6.5"/>
                    <line x1="8" y1="5" x2="8" y2="8.5"/>
                    <circle cx="8" cy="11" r=".7" fill="currentColor" stroke="none"/>
                </svg>
                <?= e($throttle->lockMessage()) ?>
            </div>
        <?php endif; ?>

        <form method="post" class="login-form">

            <!-- Username -->
            <div class="login-field">
                <label for="lf-user">نام کاربری</label>
                <div class="login-input-wrap">
                    <svg class="login-input-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                        <circle cx="8" cy="5.5" r="2.5"/>
                        <path d="M2.5 13.5c0-3.04 2.46-5.5 5.5-5.5s5.5 2.46 5.5 5.5"/>
                    </svg>
                    <input type="text" id="lf-user" name="username" required autofocus
                           autocomplete="username" placeholder="نام کاربری"
                           <?= $loginLocked ? 'disabled' : '' ?>>
                </div>
            </div>

            <!-- Password -->
            <div class="login-field">
                <label for="lf-pass">رمز عبور</label>
                <div class="login-input-wrap">
                    <svg class="login-input-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="7" width="10" height="7" rx="2"/>
                        <path d="M5.5 7V5a2.5 2.5 0 0 1 5 0v2"/>
                    </svg>
                    <input type="password" id="lf-pass" name="password" required
                           autocomplete="current-password" placeholder="رمز عبور"
                           <?= $loginLocked ? 'disabled' : '' ?>>
                </div>
            </div>

            <button type="submit" class="login-btn" <?= $loginLocked ? 'disabled' : '' ?>>
                ورود
                <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 8h10M9 4l4 4-4 4"/>
                </svg>
            </button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>

<?php

declare(strict_types=1);

require __DIR__ . '/subscribe-bootstrap.php';

$account = resolveSubscribeAccount($wgManager);

if ($account === null) {
    http_response_code(404);
    $pageTitle = 'یافت نشد';
    require __DIR__ . '/includes/subscribe-header.php';
    ?>
    <div class="sub-empty-card">
        <div class="sub-empty-icon">🔗</div>
        <h2>لینک نامعتبر</h2>
        <p>اشتراک یافت نشد. لینک را از پشتیبانی دریافت کنید.</p>
    </div>
    <?php
    require __DIR__ . '/includes/subscribe-footer.php';
    exit;
}

$token         = (string) $account['subscribe_token'];
$badge         = WgPanel\Helpers::statusBadge($account);
$online        = $wgManager->getAccountOnlineStatus($account);
$volumePercent = WgPanel\Helpers::volumePercent($account);
$daysLeft      = WgPanel\Helpers::daysUntilExpiryForAccount($account);
$volumeLimit   = (int) $account['volume_limit_bytes'];
$volumeUsed    = (int) $account['volume_used_bytes'];

$pageTitle = $account['name'];
require __DIR__ . '/includes/subscribe-header.php';
?>

<!-- ══════════════════════════════════════════
     Profile card — name · status · expiry · volume
     ══════════════════════════════════════════ -->
<div
    class="sub-profile-card"
    id="live-subscribe-root"
    data-token="<?= e($token) ?>"
>
    <!-- Row 1: avatar + name + online badge -->
    <div class="sub-profile-top">
        <div class="sub-avatar" aria-hidden="true"><?= mb_substr($account['name'], 0, 1) ?></div>

        <div class="sub-profile-name-wrap">
            <h1 class="sub-profile-name"><?= e($account['name']) ?></h1>
        </div>

        <div class="sub-online-badge">
            <span class="online-dot sub-online-dot <?= $online['online'] ? 'is-online' : (($online['label'] ?? '') === 'قطع' ? 'is-disconnected' : 'is-offline') ?>"></span>
            <span class="online-label sub-online-label"><?= e($online['label']) ?></span>
        </div>
    </div>

    <!-- Divider -->
    <div class="sub-profile-divider"></div>

    <!-- Row 2: expiry + volume in one row -->
    <div class="sub-stats-row">

        <!-- Expiry -->
        <div class="sub-stat-block">
            <div class="sub-stat-icon">📅</div>
            <div class="sub-stat-content">
                <div class="sub-stat-title">تاریخ انقضا</div>
                <div class="sub-stat-value" id="live-expiry-display">
                    <?= WgPanel\Helpers::formatExpiryDisplayHtml($account) ?>
                </div>
                <?php if (WgPanel\Helpers::isFirstConnectExpiry($account) && empty($account['first_connected_at'])): ?>
                    <div class="sub-stat-hint hint-info">پس از اولین اتصال</div>
                <?php elseif ($daysLeft !== null): ?>
                    <div class="sub-stat-hint <?= $daysLeft <= 3 ? 'hint-warn' : '' ?>">
                        <?= WgPanel\Helpers::formatDaysLeftHintHtml($daysLeft) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="sub-stat-sep"></div>

        <!-- Volume -->
        <div class="sub-stat-block sub-stat-volume">
            <div class="sub-stat-icon">📦</div>
            <div class="sub-stat-content" style="flex:1;min-width:0">
                <div class="sub-stat-title">مصرف حجم</div>
                <div class="sub-volume-row">
                    <span class="sub-stat-value" id="live-volume-text">
                        <?= WgPanel\Helpers::formatVolumeRangeHtml($volumeUsed, $volumeLimit) ?>
                    </span>
                </div>
                <?php if ($volumePercent !== null): ?>
                    <div class="sub-progress-track">
                        <div
                            id="live-volume-bar"
                            class="sub-progress-fill <?= $volumePercent >= 90 ? 'progress-danger' : ($volumePercent >= 70 ? 'progress-warning' : '') ?>"
                            style="width:<?= e((string) $volumePercent) ?>%"
                        ></div>
                    </div>
                    <div class="sub-stat-hint" id="live-volume-meta">
                        <?= WgPanel\Helpers::formatVolumePercentHtml($volumePercent) ?>
                    </div>
                <?php else: ?>
                    <div class="sub-stat-hint">♾ نامحدود</div>
                <?php endif; ?>
            </div>
        </div>

    </div>

</div>

<!-- ══════════════════════════════════════════
     WireGuard Config
     ══════════════════════════════════════════ -->
<div class="sub-wg-card">
    <div class="sub-wg-header">
        <span class="sub-wg-icon">🔒</span>
        <div>
            <div class="sub-wg-title">اتصال WireGuard</div>
            <div class="sub-wg-desc">فایل کانفیگ را دانلود کرده یا QR را اسکن کنید</div>
        </div>
    </div>
    <div class="sub-wg-body">
        <a href="/subscribe-download.php?token=<?= e(urlencode($token)) ?>" class="sub-download-btn">
            <svg width="15" height="15" viewBox="0 0 16 16" fill="none">
                <path d="M8 2v8M5 7l3 3 3-3M3 13h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            دانلود فایل .conf
        </a>
        <div class="sub-qr-wrap">
            <img src="/subscribe-qr.php?token=<?= e(urlencode($token)) ?>" alt="WireGuard QR" loading="lazy">
            <p class="sub-qr-label">اپ رسمی WireGuard را اسکن کنید</p>

            <!-- App download links -->
            <div class="sub-app-links">
                <a href="https://play.google.com/store/apps/details?id=com.wireguard.android&hl=en"
                   target="_blank" rel="noopener" class="sub-app-btn sub-app-android" title="Android">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                        <path d="M17.523 15.341 19.8 11.25a.5.5 0 0 0-.43-.75H4.63a.5.5 0 0 0-.43.75l2.277 4.091A5 5 0 0 0 3 20v1h18v-1a5 5 0 0 0-3.477-4.659ZM14.5 5.5l1.293-1.293a.5.5 0 0 0-.707-.707L13.5 5.086A5.47 5.47 0 0 0 12 4.75c-.528 0-1.036.073-1.5.207L8.914 3.5a.5.5 0 0 0-.707.707L9.5 5.5A5.5 5.5 0 0 0 6.5 10.5h11A5.5 5.5 0 0 0 14.5 5.5Zm-4 2a.75.75 0 1 1 0 1.5.75.75 0 0 1 0-1.5Zm3 0a.75.75 0 1 1 0 1.5.75.75 0 0 1 0-1.5Z"/>
                    </svg>
                    <span>Android</span>
                </a>
                <a href="https://apps.apple.com/us/app/wireguard/id1441195209"
                   target="_blank" rel="noopener" class="sub-app-btn sub-app-ios" title="iOS">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                        <path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11z"/>
                    </svg>
                    <span>iPhone</span>
                </a>
                <a href="https://download.wireguard.com/windows-client/wireguard-amd64-1.1.msi"
                   target="_blank" rel="noopener" class="sub-app-btn sub-app-win" title="Windows">
                    <svg viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                        <path d="M0 3.449 9.75 2.1v9.451H0m10.949-9.602L24 0v11.551H10.949M0 12.6h9.75v9.451L0 20.699M10.949 12.6H24V24l-12.9-1.801"/>
                    </svg>
                    <span>Windows</span>
                </a>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/subscribe-footer.php'; ?>

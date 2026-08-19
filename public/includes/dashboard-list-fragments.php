<?php

declare(strict_types=1);

use WgPanel\Helpers;
use WgPanel\WireGuardManager;

/** @return array<string, mixed> */
function dashboardLoadList(WireGuardManager $wgManager, array $listState): array
{
    $search = $listState['search'];
    $page = $listState['page'];
    $perPage = $listState['per_page'];
    $searchFilter = $search !== '' ? $search : null;

    $totalAccounts = $wgManager->countAccounts($searchFilter);
    $statusCounts = $wgManager->countAccountsStatus($searchFilter);
    $totalPages = max(1, (int) ceil($totalAccounts / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $accounts = $wgManager->listAccountsPaginated($page, $perPage, $searchFilter);
    $onlineById = $wgManager->getOnlineStatusesForAccounts($accounts);

    $rangeFrom = $totalAccounts === 0 ? 0 : (($page - 1) * $perPage) + 1;
    $rangeTo = min($totalAccounts, $page * $perPage);

    return [
        'search' => $search,
        'page' => $page,
        'per_page' => $perPage,
        'total_accounts' => $totalAccounts,
        'active_accounts' => $statusCounts['active'],
        'inactive_accounts' => $statusCounts['inactive'],
        'total_pages' => $totalPages,
        'range_from' => $rangeFrom,
        'range_to' => $rangeTo,
        'accounts' => $accounts,
        'online_by_id' => $onlineById,
        'list_state' => [
            'search' => $search,
            'page' => $page,
            'per_page' => $perPage,
        ],
    ];
}

function dashboardRenderAccountsTbody(array $list, WireGuardManager $wgManager): string
{
    $accounts = $list['accounts'];
    $search = $list['search'];
    $onlineById = $list['online_by_id'];
    $listState = $list['list_state'];

    ob_start();

    if ($accounts === []) {
        ?>
        <tr>
            <td colspan="6" class="empty">
                <?= $search !== '' ? 'نتیجه‌ای برای «' . e($search) . '» یافت نشد.' : 'هنوز اکانتی ایجاد نشده.' ?>
            </td>
        </tr>
        <?php
    } else {
        foreach ($accounts as $account) {
            $badge = Helpers::statusBadge($account);
            $online = $onlineById[(int) $account['id']] ?? $wgManager->getAccountOnlineStatus($account);
            $onlineState = (string) ($online['state'] ?? ($online['online'] ? 'online' : 'offline'));
            $dotClass = match ($onlineState) {
                'online' => 'is-online',
                'disabled' => 'is-disconnected',
                'unknown', 'pending' => 'online-dot-pending',
                default => 'is-offline',
            };
            $isActive = (int) $account['is_active'] === 1;
            $speedKbps = (int) $account['speed_limit_kbps'];
            $volumeUsed = (int) $account['volume_used_bytes'];
            $volumeLimit = (int) $account['volume_limit_bytes'];
            $volumePercent = Helpers::volumePercent($account);
            ?>
            <tr>
                <td class="col-id"><?= (int) $account['id'] ?></td>
                <td class="col-name">
                    <strong><?= e($account['name']) ?></strong>
                    <code class="row-ip"><?= e($account['ip_address']) ?></code>
                </td>
                <td>
                    <span
                        class="online-status online-chip is-<?= e($onlineState) ?>"
                        data-live-online="<?= (int) $account['id'] ?>"
                        title="<?= e((string) ($online['title'] ?? '')) ?>"
                    >
                        <span class="online-dot <?= e($dotClass) ?>"></span>
                        <span class="online-chip-text">
                            <span class="online-label"><?= e($online['label']) ?></span>
                            <?php if ($onlineState !== 'disabled'): ?>
                                <span class="online-meta"><?= e($online['relative'] !== '—' ? 'handshake: ' . $online['relative'] : 'بدون handshake') ?></span>
                            <?php endif; ?>
                        </span>
                    </span>
                </td>
                <td><span class="badge <?= e($badge['class']) ?>"><?= e($badge['label']) ?></span></td>
                <td class="col-limits">
                    <div class="limits-row">
                        <span class="limit-chip limit-chip-speed" title="سرعت">
                            <span class="limit-label">سرعت</span>
                            <span class="limit-value"><?= $speedKbps > 0 ? Helpers::ltrIsolate(Helpers::formatSpeed($speedKbps)) : '∞' ?></span>
                        </span>
                        <span class="limit-chip limit-chip-volume<?= $volumePercent !== null && $volumePercent >= 90 ? ' is-danger' : ($volumePercent !== null && $volumePercent >= 70 ? ' is-warning' : '') ?>" title="حجم مصرفی">
                            <span class="limit-label">حجم</span>
                            <span class="limit-value"><?= Helpers::ltrIsolate(Helpers::formatBytes($volumeUsed) . ' / ' . ($volumeLimit > 0 ? Helpers::formatBytes($volumeLimit) : '∞')) ?></span>
                        </span>
                        <span class="limit-chip limit-chip-expiry" title="انقضا">
                            <span class="limit-label">انقضا</span>
                            <span class="limit-value"><?= Helpers::formatExpiryDisplayHtml($account) ?></span>
                        </span>
                    </div>
                </td>
                <td class="col-actions">
                    <div class="dd-wrap">
                        <button type="button" class="dd-trigger" aria-label="عملیات" aria-haspopup="true" aria-expanded="false">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor">
                                <circle cx="8" cy="3" r="1.4"/>
                                <circle cx="8" cy="8" r="1.4"/>
                                <circle cx="8" cy="13" r="1.4"/>
                            </svg>
                        </button>
                        <div class="dd-menu" hidden>
                            <a href="/?account=<?= (int) $account['id'] ?>&tab=view" class="dd-item js-account-modal" data-account-id="<?= (int) $account['id'] ?>" data-am-tab="view">
                                <svg class="dd-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                                    <path d="M1 8s2.5-5 7-5 7 5 7 5-2.5 5-7 5-7-5-7-5Z"/>
                                    <circle cx="8" cy="8" r="2"/>
                                </svg>
                                <span>جزئیات</span>
                            </a>
                            <a href="/?account=<?= (int) $account['id'] ?>&tab=edit" class="dd-item js-account-modal" data-account-id="<?= (int) $account['id'] ?>" data-am-tab="edit">
                                <svg class="dd-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 2l3 3-8 8H3v-3l8-8Z"/>
                                </svg>
                                <span>ویرایش</span>
                            </a>
                            <a href="/download.php?id=<?= (int) $account['id'] ?>" class="dd-item">
                                <svg class="dd-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M8 2v8M5 7l3 3 3-3M3 13h10"/>
                                </svg>
                                <span>دانلود .conf</span>
                            </a>
                            <a href="<?= e($wgManager->buildSubscribePanelUrl($account)) ?>" target="_blank" rel="noopener" class="dd-item">
                                <svg class="dd-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M7 3H3a1 1 0 0 0-1 1v9a1 1 0 0 0 1 1h9a1 1 0 0 0 1-1V9M10 2h4m0 0v4m0-4L7 9"/>
                                </svg>
                                <span>پنل کاربری</span>
                            </a>
                            <div class="dd-sep"></div>
                            <form method="post" class="dd-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
                                <?= dashboardListFields($listState) ?>
                                <button type="submit" class="dd-item <?= $isActive ? 'dd-item-warn' : 'dd-item-ok' ?>">
                                    <?php if ($isActive): ?>
                                        <svg class="dd-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                                            <rect x="3" y="3" width="4" height="10" rx="1"/>
                                            <rect x="9" y="3" width="4" height="10" rx="1"/>
                                        </svg>
                                        <span>غیرفعال کردن</span>
                                    <?php else: ?>
                                        <svg class="dd-icon" viewBox="0 0 16 16" fill="currentColor">
                                            <path d="M4 3l10 5-10 5V3Z"/>
                                        </svg>
                                        <span>فعال کردن</span>
                                    <?php endif; ?>
                                </button>
                            </form>
                            <button type="button" class="dd-item js-reset-open"
                                    data-account-id="<?= (int) $account['id'] ?>"
                                    data-account-name="<?= e($account['name']) ?>">
                                <svg class="dd-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M2 8a6 6 0 1 0 1.2-3.6M2 2v4h4"/>
                                </svg>
                                <span>ریست</span>
                            </button>
                            <div class="dd-sep"></div>
                            <form method="post" class="dd-form" onsubmit="return confirm('اکانت «<?= e($account['name']) ?>» حذف شود؟');">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $account['id'] ?>">
                                <?= dashboardListFields($listState) ?>
                                <button type="submit" class="dd-item dd-item-danger">
                                    <svg class="dd-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 4h10M6 4V2h4v2M5 4v9a1 1 0 0 0 1 1h4a1 1 0 0 0 1-1V4"/>
                                        <line x1="7" y1="7" x2="7" y2="11"/>
                                        <line x1="9" y1="7" x2="9" y2="11"/>
                                    </svg>
                                    <span>حذف اکانت</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </td>
            </tr>
            <?php
        }
    }

    return (string) ob_get_clean();
}

function dashboardRenderPagination(array $list): string
{
    $search = $list['search'];
    $page = $list['page'];
    $perPage = $list['per_page'];
    $totalPages = $list['total_pages'];

    if ($totalPages <= 1) {
        return '';
    }

    ob_start();
    ?>
    <nav class="pagination" aria-label="صفحه‌بندی">
        <?php if ($page > 1): ?>
            <a class="page-link" href="<?= e(dashboardUrl($search, $page - 1, $perPage)) ?>">قبلی</a>
        <?php else: ?>
            <span class="page-link is-disabled">قبلی</span>
        <?php endif; ?>

        <?php
        $window = 2;
        $start = max(1, $page - $window);
        $end = min($totalPages, $page + $window);
        if ($start > 1): ?>
            <a class="page-link" href="<?= e(dashboardUrl($search, 1, $perPage)) ?>">1</a>
            <?php if ($start > 2): ?><span class="page-ellipsis">…</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($p = $start; $p <= $end; $p++): ?>
            <?php if ($p === $page): ?>
                <span class="page-link is-active"><?= $p ?></span>
            <?php else: ?>
                <a class="page-link" href="<?= e(dashboardUrl($search, $p, $perPage)) ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($end < $totalPages): ?>
            <?php if ($end < $totalPages - 1): ?><span class="page-ellipsis">…</span><?php endif; ?>
            <a class="page-link" href="<?= e(dashboardUrl($search, $totalPages, $perPage)) ?>"><?= $totalPages ?></a>
        <?php endif; ?>

        <?php if ($page < $totalPages): ?>
            <a class="page-link" href="<?= e(dashboardUrl($search, $page + 1, $perPage)) ?>">بعدی</a>
        <?php else: ?>
            <span class="page-link is-disabled">بعدی</span>
        <?php endif; ?>
    </nav>
    <?php

    return (string) ob_get_clean();
}

function dashboardRenderMetaText(array $list): string
{
    $totalAccounts = $list['total_accounts'];
    $rangeFrom = $list['range_from'];
    $rangeTo = $list['range_to'];

    if ($totalAccounts === 0) {
        return 'اکانتی یافت نشد';
    }

    return 'نمایش ' . $rangeFrom . '–' . $rangeTo . ' از ' . $totalAccounts;
}

function dashboardRenderStats(array $list): string
{
    $active = (int) ($list['active_accounts'] ?? 0);
    $inactive = (int) ($list['inactive_accounts'] ?? 0);
    $total = (int) ($list['total_accounts'] ?? 0);

    ob_start();
    ?>
    <div class="dashboard-stats" role="group" aria-label="آمار اکانت‌ها">
        <div class="dashboard-stat dashboard-stat-active">
            <span class="dashboard-stat-num"><?= $active ?></span>
            <span class="dashboard-stat-label">فعال</span>
        </div>
        <div class="dashboard-stat dashboard-stat-inactive">
            <span class="dashboard-stat-num"><?= $inactive ?></span>
            <span class="dashboard-stat-label">غیرفعال</span>
        </div>
        <div class="dashboard-stat dashboard-stat-total">
            <span class="dashboard-stat-num"><?= $total ?></span>
            <span class="dashboard-stat-label">کل</span>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

/** @return array<string, mixed> */
function dashboardListAjaxPayload(array $list, WireGuardManager $wgManager): array
{
    $listState = $list['list_state'];

    return [
        'html' => [
            'tbody' => dashboardRenderAccountsTbody($list, $wgManager),
            'pagination' => dashboardRenderPagination($list),
            'meta' => dashboardRenderMetaText($list),
            'stats' => dashboardRenderStats($list),
        ],
        'state' => [
            'search' => $list['search'],
            'page' => $list['page'],
            'per_page' => $list['per_page'],
            'total' => $list['total_accounts'],
            'active' => $list['active_accounts'],
            'inactive' => $list['inactive_accounts'],
            'total_pages' => $list['total_pages'],
            'range_from' => $list['range_from'],
            'range_to' => $list['range_to'],
        ],
        'url' => dashboardUrl($listState['search'], $listState['page'], $listState['per_page']),
        'list_fields' => dashboardListFields($listState),
    ];
}

function sendDashboardSearchJson(WireGuardManager $wgManager): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $listState = dashboardStateFromRequest();
        $list = dashboardLoadList($wgManager, $listState);
        echo json_encode(
            dashboardListAjaxPayload($list, $wgManager),
            JSON_UNESCAPED_UNICODE,
        );
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }

    exit;
}

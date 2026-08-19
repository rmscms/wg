<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (($_GET['ajax'] ?? '') === 'online-status') {
    require __DIR__ . '/includes/online-status-json.php';
    sendOnlineStatusJson($wgManager);
}

requireLogin();

if (($_GET['ajax'] ?? '') === 'search') {
    require __DIR__ . '/includes/dashboard-search-ajax.php';
}

require __DIR__ . '/includes/dashboard-list-fragments.php';
require __DIR__ . '/includes/account-modal-ajax.php';

if (($_GET['ajax'] ?? '') === 'account-modal') {
    sendAccountModalJson($wgManager);
}

$listState = dashboardStateFromRequest();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['ajax'] ?? '') === 'account-save') {
    sendAccountModalSaveJson($wgManager);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        flash('danger', 'درخواست نامعتبر.');
        redirect(dashboardUrl($listState));
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'delete') {
            $wgManager->deleteAccount((int) ($_POST['id'] ?? 0));
            flash('success', 'اکانت حذف شد.');
        } elseif ($action === 'toggle') {
            $account = $wgManager->getAccount((int) ($_POST['id'] ?? 0));
            if ($account) {
                $wgManager->updateAccount((int) $account['id'], [
                    'is_active' => (int) $account['is_active'] === 1 ? 0 : 1,
                ]);
                flash('success', 'وضعیت اکانت تغییر کرد.');
            }
        } elseif ($action === 'reset_traffic') {
            $id = (int) ($_POST['id'] ?? 0);
            $wgManager->resetTraffic($id);
            flash('success', 'مصرف حجم ریست شد.');
        } elseif ($action === 'reset_expiry') {
            $id = (int) ($_POST['id'] ?? 0);
            $wgManager->resetExpiry($id);
            flash('success', 'تاریخ انقضا ریست شد.');
        } elseif ($action === 'reset_both') {
            $id = (int) ($_POST['id'] ?? 0);
            $wgManager->resetTrafficAndExpiry($id);
            flash('success', 'حجم و تاریخ انقضا ریست شد.');
        } elseif ($action === 'sync') {
            $wgManager->syncTraffic();
            $wgManager->enforceLimits();
            flash('success', 'ترافیک همگام‌سازی شد.');
        } elseif ($action === 'sync_wg') {
            $script = (string) ($config['scripts']['sync_wg'] ?? dirname(__DIR__) . '/scripts/sync-wg.php');

            if (!is_file($script)) {
                throw new RuntimeException('فایل sync-wg.php یافت نشد.');
            }

            $run = WgPanel\Shell::run('/usr/bin/php ' . escapeshellarg($script), false, true);
            $output = trim($run['output']);

            $added = [];
            $removed = [];
            $errors = [];

            foreach (explode("\n", $output) as $line) {
                if (str_starts_with($line, '  + added ')) {
                    $added[] = substr($line, 10);
                } elseif (str_starts_with($line, '  - removed ')) {
                    $removed[] = substr($line, 12);
                } elseif (str_starts_with($line, '  ! error ')) {
                    $errors[] = substr($line, 10);
                }
            }

            $remaining = 0;

            if (preg_match('/Diff remaining: (\d+)/', $output, $m)) {
                $remaining = (int) $m[1];
            }

            $parts = [];

            if ($removed !== []) {
                $parts[] = 'حذف: ' . implode('، ', $removed);
            }

            if ($added !== []) {
                $parts[] = 'اضافه: ' . implode('، ', $added);
            }

            if ($errors !== []) {
                $parts[] = 'خطا: ' . implode('، ', $errors);
            }

            if ($remaining > 0) {
                $parts[] = 'اختلاف باقی‌مانده: ' . $remaining;
            }

            if ($run['exit_code'] !== 0 && $parts === [] && $output !== '') {
                $parts[] = mb_substr($output, 0, 1500);
            }

            $msg = $parts === [] ? 'همگام‌سازی انجام شد. تغییری نیاز نبود.' : implode(' | ', $parts);
            flash(
                $run['exit_code'] === 0 && $errors === [] && $remaining === 0 ? 'success' : 'danger',
                $msg
            );
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }

    redirect(dashboardUrl($listState));
}

$list = dashboardLoadList($wgManager, $listState);
$listState = $list['list_state'];
$search = $list['search'];
$page = $list['page'];
$perPage = $list['per_page'];

$pageTitle = 'مدیریت اکانت‌ها';
$pageStyles = ['/assets/account-modal.css'];
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>اکانت‌های WireGuard</h1>
        <p class="muted">مدیریت کاربران، تاریخ انقضا، سرعت و حجم</p>
    </div>
    <div class="actions">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="sync">
            <?= dashboardListFields($listState) ?>
            <button type="submit" class="btn btn-secondary">همگام‌سازی ترافیک</button>
        </form>
        <form method="post" onsubmit="return confirm('wg0.conf با دیتابیس همگام شود؟');">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="sync_wg">
            <?= dashboardListFields($listState) ?>
            <button type="submit" class="btn btn-secondary">همگام‌سازی WireGuard</button>
        </form>
        <a href="/create.php" class="btn btn-primary">+ اکانت جدید</a>
    </div>
</div>

<div class="card dashboard-card" id="dashboard-list-card">
    <div id="dashboard-stats-wrap">
        <?= dashboardRenderStats($list) ?>
    </div>
    <div class="dashboard-toolbar">
        <form method="get" class="dashboard-search-form" id="dashboard-search-form">
            <input type="hidden" name="page" value="<?= (int) $page ?>" id="dashboard-page-input">
            <div class="dashboard-search">
                <div class="search-field" id="dashboard-search-field">
                    <span class="search-icon" aria-hidden="true">⌕</span>
                    <input
                        type="search"
                        name="q"
                        id="dashboard-search-input"
                        value="<?= e($search) ?>"
                        placeholder="جستجوی زنده: نام، IP یا ID..."
                        autocomplete="off"
                        spellcheck="false"
                        aria-controls="dashboard-accounts-tbody dashboard-meta dashboard-pagination-wrap"
                    >
                    <button
                        type="button"
                        class="search-clear"
                        id="dashboard-search-clear"
                        aria-label="پاک کردن جستجو"
                        <?= $search === '' ? 'hidden' : '' ?>
                    >&times;</button>
                    <span class="search-spinner" id="dashboard-search-spinner" hidden aria-hidden="true"></span>
                </div>
                <label class="per-page-select">
                    <span class="muted">در صفحه</span>
                    <select name="per_page" id="dashboard-per-page">
                        <?php foreach ([10, 20, 50, 100] as $option): ?>
                            <option value="<?= $option ?>" <?= $perPage === $option ? 'selected' : '' ?>><?= $option ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="dashboard-meta" id="dashboard-meta">
                    <span class="muted"><?= e(dashboardRenderMetaText($list)) ?></span>
                </div>
            </div>
            <div class="dashboard-filters">
                <div class="filter-chips" role="radiogroup" aria-label="وضعیت اکانت">
                    <?php foreach (dashboardStatusFilterOptions() as $value => $label): ?>
                        <label class="filter-chip">
                            <input
                                type="radio"
                                name="status"
                                value="<?= e($value) ?>"
                                <?= (string) ($listState['status'] ?? '') === (string) $value ? 'checked' : '' ?>
                            >
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="filter-dates">
                    <label class="filter-date">
                        <span>ایجاد از</span>
                        <input type="text" name="created_from" id="dashboard-created-from" data-jalali-date placeholder="1404/01/01" value="<?= e((string) ($listState['created_from'] ?? '')) ?>">
                    </label>
                    <label class="filter-date">
                        <span>تا</span>
                        <input type="text" name="created_to" id="dashboard-created-to" data-jalali-date placeholder="1404/12/29" value="<?= e((string) ($listState['created_to'] ?? '')) ?>">
                    </label>
                    <label class="filter-date">
                        <span>انقضا از</span>
                        <input type="text" name="expires_from" id="dashboard-expires-from" data-jalali-date placeholder="1404/01/01" value="<?= e((string) ($listState['expires_from'] ?? '')) ?>">
                    </label>
                    <label class="filter-date">
                        <span>تا</span>
                        <input type="text" name="expires_to" id="dashboard-expires-to" data-jalali-date placeholder="1404/12/29" value="<?= e((string) ($listState['expires_to'] ?? '')) ?>">
                    </label>
                    <button
                        type="button"
                        class="filter-reset"
                        id="dashboard-filter-reset"
                        <?= dashboardListIsFiltered($listState, false) ? '' : 'hidden' ?>
                    >بازنشانی فیلتر</button>
                </div>
            </div>
        </form>
    </div>

    <div class="table-wrap" id="dashboard-table-wrap">
        <table class="accounts-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>نام</th>
                    <th>اتصال</th>
                    <th>وضعیت</th>
                    <th>جزئیات</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="dashboard-accounts-tbody">
                <?= dashboardRenderAccountsTbody($list, $wgManager) ?>
            </tbody>
        </table>
    </div>

    <div id="dashboard-pagination-wrap">
        <?= dashboardRenderPagination($list) ?>
    </div>
</div>

<p class="live-indicator-note">● وضعیت اتصال هر ۱۰ ثانیه به‌روزرسانی می‌شود (بر اساس handshake WireGuard)</p>

<div id="reset-modal" class="reset-modal" hidden aria-hidden="true">
    <div class="reset-modal-backdrop js-reset-close" tabindex="-1"></div>
    <div class="reset-modal-panel" role="dialog" aria-modal="true" aria-labelledby="reset-modal-title">
        <div class="reset-modal-header">
            <div>
                <h2 id="reset-modal-title" class="reset-modal-title">ریست اکانت</h2>
                <p class="reset-modal-subtitle">یکی از گزینه‌های زیر را انتخاب کنید</p>
            </div>
            <button type="button" class="reset-modal-close js-reset-close" aria-label="بستن">&times;</button>
        </div>
        <p class="reset-modal-account">
            <span class="reset-modal-account-label">اکانت</span>
            <strong id="reset-modal-account-name">—</strong>
        </p>
        <div class="reset-options">
            <button type="button" class="reset-option" data-reset-action="reset_expiry">
                <span class="reset-option-icon reset-option-icon-expiry" aria-hidden="true">📅</span>
                <span class="reset-option-body">
                    <span class="reset-option-title">ریست تاریخ</span>
                    <span class="reset-option-desc">پاک کردن انقضا؛ اتصال WireGuard یک‌بار قطع و وصل می‌شود</span>
                </span>
            </button>
            <button type="button" class="reset-option" data-reset-action="reset_traffic">
                <span class="reset-option-icon reset-option-icon-volume" aria-hidden="true">📦</span>
                <span class="reset-option-body">
                    <span class="reset-option-title">ریست حجم</span>
                    <span class="reset-option-desc">صفر کردن مصرف حجم؛ اتصال WireGuard یک‌بار قطع و وصل می‌شود</span>
                </span>
            </button>
            <button type="button" class="reset-option reset-option-both" data-reset-action="reset_both">
                <span class="reset-option-icon reset-option-icon-both" aria-hidden="true">↺</span>
                <span class="reset-option-body">
                    <span class="reset-option-title">ریست هر دو</span>
                    <span class="reset-option-desc">ریست حجم و انقضا؛ اتصال WireGuard یک‌بار قطع و وصل می‌شود</span>
                </span>
            </button>
        </div>
        <div class="reset-modal-footer">
            <button type="button" class="btn btn-secondary js-reset-close">انصراف</button>
        </div>
    </div>
</div>

<form id="reset-submit-form" method="post" class="visually-hidden">
    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
    <input type="hidden" name="action" id="reset-submit-action" value="">
    <input type="hidden" name="id" id="reset-submit-id" value="">
    <?= dashboardListFields($listState) ?>
</form>

<?php require __DIR__ . '/includes/account-modal.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
<script src="/assets/live-status.js"></script>
<script src="/assets/dashboard.js"></script>
<script src="/assets/account-modal.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>

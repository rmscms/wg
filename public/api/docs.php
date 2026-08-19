<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$apiSection  = $config['api'] ?? [];
$apiToken    = isLoggedIn() ? (string) ($apiSection['token'] ?? '') : '';
$apiEnabled  = isLoggedIn() && !empty($apiSection['enabled']) && $apiToken !== '';

// The ?api-docs.json query → proxy to the JSON spec
if (isset($_GET['api-docs.json'])) {
    header('Location: /api/openapi.php?api-docs.json');
    exit;
}

$specUrl  = '/api/openapi.php?api-docs.json';
$baseUrl  = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Reference — WireGuard Panel</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #0f172a; }

        /* ─── Top bar ─── */
        #api-topbar {
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 9999;
            height: 52px;
            background: rgba(9, 14, 28, 0.92);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border-bottom: 1px solid rgba(255,255,255,.07);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            gap: 1rem;
        }

        .topbar-brand {
            display: flex;
            align-items: center;
            gap: .6rem;
            text-decoration: none;
        }

        .topbar-brand-text {
            font-family: system-ui, -apple-system, sans-serif;
            font-weight: 700;
            font-size: .95rem;
            background: linear-gradient(90deg, #60a5fa, #818cf8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .topbar-badge {
            font-family: monospace;
            font-size: .7rem;
            padding: .18rem .5rem;
            border-radius: 5px;
            background: rgba(99,102,241,.2);
            color: #a5b4fc;
            border: 1px solid rgba(99,102,241,.3);
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: .75rem;
        }

        <?php if ($apiEnabled): ?>
        .token-wrap {
            display: flex;
            align-items: center;
            gap: .5rem;
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 8px;
            padding: .3rem .6rem .3rem .8rem;
        }

        .token-label {
            font-family: system-ui, sans-serif;
            font-size: .72rem;
            color: #9ca3af;
            white-space: nowrap;
        }

        .token-value {
            font-family: monospace;
            font-size: .72rem;
            color: #93c5fd;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            direction: ltr;
        }

        .btn-copy-token {
            padding: .25rem .55rem;
            border-radius: 5px;
            border: 1px solid rgba(255,255,255,.12);
            background: rgba(255,255,255,.07);
            color: #e5e7eb;
            font-family: system-ui, sans-serif;
            font-size: .72rem;
            cursor: pointer;
            white-space: nowrap;
            transition: background .15s;
        }

        .btn-copy-token:hover { background: rgba(255,255,255,.12); }
        .btn-copy-token.copied { color: #86efac; border-color: rgba(34,197,94,.3); background: rgba(34,197,94,.08); }
        <?php endif; ?>

        .btn-dashboard {
            padding: .35rem .85rem;
            border-radius: 7px;
            border: 1px solid rgba(255,255,255,.1);
            background: rgba(255,255,255,.06);
            color: #d1d5db;
            text-decoration: none;
            font-family: system-ui, sans-serif;
            font-size: .78rem;
            font-weight: 500;
            transition: background .15s;
            white-space: nowrap;
        }

        .btn-dashboard:hover { background: rgba(255,255,255,.11); }

        /* ─── Scalar offset ─── */
        #scalar-root {
            padding-top: 52px;
        }
    </style>
</head>
<body>

<!-- ─── Top bar ─── -->
<div id="api-topbar">
    <a href="/api/docs.php" class="topbar-brand">
        <span class="topbar-brand-text">WireGuard Panel</span>
        <span class="topbar-badge">API v1</span>
    </a>

    <div class="topbar-actions">
        <?php if ($apiEnabled): ?>
        <div class="token-wrap">
            <span class="token-label">Bearer Token</span>
            <span class="token-value" id="token-display"><?= e($apiToken) ?></span>
            <button class="btn-copy-token" id="copy-token-btn" onclick="copyToken()">کپی</button>
        </div>
        <?php elseif (isLoggedIn()): ?>
        <span style="font-size:.75rem;color:#6b7280;font-family:system-ui,sans-serif;">API در config فعال نیست</span>
        <?php else: ?>
        <a href="<?= e(adminLoginPath()) ?>?return=<?= e(urlencode('/api/docs.php')) ?>" class="btn-dashboard">ورود به پنل</a>
        <?php endif; ?>

        <a href="/" class="btn-dashboard">داشبورد</a>
    </div>
</div>

<!-- ─── Scalar ─── -->
<div id="scalar-root"></div>

<script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
<script>
Scalar.createApiReference('#scalar-root', {
    url: '<?= $specUrl ?>',
    theme: 'purple',
    layout: 'modern',
    darkMode: true,
    defaultOpenAllTags: false,
    hideModels: false,
    hideDownloadButton: false,
    showSidebar: true,
    servers: [{ url: '<?= e($baseUrl) ?>/api/v1', description: 'Panel API v1' }],
    <?php if ($apiEnabled && $apiToken !== ''): ?>
    authentication: {
        preferredSecurityScheme: 'BearerAuth',
        http: {
            bearer: {
                token: '<?= e($apiToken) ?>'
            }
        }
    },
    <?php endif; ?>
    metadata: {
        title: 'WireGuard Panel API',
    }
});
</script>

<?php if ($apiEnabled): ?>
<script>
function copyToken() {
    const token = <?= json_encode($apiToken) ?>;
    navigator.clipboard.writeText(token).then(function () {
        const btn = document.getElementById('copy-token-btn');
        btn.textContent = '✓ کپی شد';
        btn.classList.add('copied');
        setTimeout(() => { btn.textContent = 'کپی'; btn.classList.remove('copied'); }, 2000);
    });
}
</script>
<?php endif; ?>

</body>
</html>

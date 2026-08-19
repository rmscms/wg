<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'WireGuard Panel') ?></title>
    <link rel="stylesheet" href="/assets/fonts.css">
    <link rel="stylesheet" href="/assets/style.css">
    <link rel="stylesheet" href="/assets/jalali-datepicker.css">
    <?php if (!empty($pageStyles) && is_array($pageStyles)): ?>
        <?php foreach ($pageStyles as $styleHref): ?>
            <link rel="stylesheet" href="<?= e($styleHref) ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body<?= !empty($bodyClass) ? ' class="' . e($bodyClass) . '"' : '' ?>>
    <?php if (isLoggedIn()): ?>
    <nav class="navbar">
        <div class="container nav-inner">
            <a href="/" class="brand">WireGuard Panel</a>
            <div class="nav-links">
                <a href="/">اکانت‌ها</a>
                <a href="/create.php">ایجاد اکانت</a>
                <a href="/api/docs.php">API</a>
                <a href="/settings.php">تنظیمات</a>
                <a href="/logout.php">خروج</a>
            </div>
        </div>
    </nav>
    <?php endif; ?>

    <main class="container">
        <?php if (empty($suppressFlash) && ($flash = getFlash())): ?>
            <div class="alert alert-<?= e($flash['type']) ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>

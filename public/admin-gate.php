<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$slug = WgPanel\AdminPath::slug($config);

if ($slug === '' || !WgPanel\AdminPath::isLoginRequest($config)) {
    WgPanel\AdminPath::notFound();
}

require_once __DIR__ . '/login.php';

<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!isLoggedIn()) {
    if (WgPanel\AdminPath::isCustom($config)) {
        WgPanel\AdminPath::notFound();
    }

    redirect('/login.php');
}

unset($_SESSION['wg_admin']);
redirectToAdminLogin();

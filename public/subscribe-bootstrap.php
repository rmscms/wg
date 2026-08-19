<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

function resolveSubscribeAccount(WgPanel\WireGuardManager $wgManager): ?array
{
    // Short token: /s/{short} → ?short=xxx
    $short = trim((string) ($_GET['short'] ?? ''));
    if ($short !== '') {
        return $wgManager->getAccountByShortToken($short);
    }

    // Full token: /subscribe.php?token=xxx or /sub/{token}
    $token = trim((string) ($_GET['token'] ?? ''));
    if ($token === '') {
        return null;
    }

    return $wgManager->getAccountBySubscribeToken($token);
}

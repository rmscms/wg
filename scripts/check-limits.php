#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/cli-bootstrap.php';

try {
    $wgManager->enforceLimits();
    $reconciled = $wgManager->reconcileRuntimePeers(true);
    if ($reconciled['added'] !== []) {
        echo 'Runtime peers restored: ' . implode(', ', $reconciled['added']) . "\n";
    }
    if ($reconciled['errors'] !== []) {
        echo 'Runtime restore errors: ' . implode('; ', $reconciled['errors']) . "\n";
    }
    echo "Limits check completed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

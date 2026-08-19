#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/cli-bootstrap.php';

$verbose = in_array('--verbose', $argv ?? [], true) || in_array('-v', $argv ?? [], true);

try {
    $wgManager->syncTrafficData($verbose);
    echo "Traffic synced.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

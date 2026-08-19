<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/includes/online-status-json.php';

sendOnlineStatusJson($wgManager);

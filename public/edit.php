<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
requireLogin();

$id = (int) ($_GET['id'] ?? ($_POST['id'] ?? 0));
redirect('/?account=' . $id . '&tab=edit');

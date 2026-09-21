<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

unset($_SESSION['pending_registration']);
redirect_to('index.php');

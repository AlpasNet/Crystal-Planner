<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

logout_user();
redirect_to('index.php');

<?php
declare(strict_types=1);
require __DIR__ . '/error_page.php';
$rawCode = $_GET['code'] ?? ($_SERVER['REDIRECT_STATUS'] ?? 500);
$requested = filter_var($rawCode, FILTER_VALIDATE_INT);
$status = is_int($requested) ? $requested : 500;
meteonexa_render_error_page($status);

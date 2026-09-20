<?php
declare(strict_types=1);
http_response_code(503);
header('Retry-After: 120');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Content-Type: text/html; charset=utf-8');
readfile(dirname(__DIR__) . '/maintenance.html');

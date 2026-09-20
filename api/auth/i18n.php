<?php
declare(strict_types=1);
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/i18n.php';
function meteonexa_auth_language(mixed $value): string { return meteonexa_language($value); }
function meteonexa_auth_catalog(PDO $pdo, string $language): array { return meteonexa_catalog($pdo, $language); }
function meteonexa_auth_translate(PDO $pdo, string $language, string $key, array $params = []): string { return meteonexa_text($pdo, $language, $key, $params); }

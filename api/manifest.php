<?php
declare(strict_types=1);
require_once __DIR__ . '/backend_i18n.php';
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') { http_response_code(405); header('Allow: GET'); exit; }

$language = meteonexa_backend_language($_GET['language'] ?? null);
$name = meteonexa_backend_text('app.name', [], $language);
$description = meteonexa_backend_text('home.meteonexa_provides_reliable_weather_forecasts_radar_official_alerts', [], $language);

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
echo json_encode([
    'id' => './',
    'name' => $name,
    'short_name' => $name,
    'description' => $description,
    'lang' => $language,
    'start_url' => '../',
    'scope' => '../',
    'display' => 'standalone',
    'display_override' => ['window-controls-overlay', 'standalone', 'minimal-ui'],
    'orientation' => 'any',
    'background_color' => '#030914',
    'theme_color' => '#06152f',
    'categories' => ['weather', 'utilities', 'lifestyle'],
    'shortcuts' => [
        ['name'=>meteonexa_backend_text('nav.radar', [], $language), 'short_name'=>meteonexa_backend_text('nav.radar', [], $language), 'url'=>'../#radar', 'icons'=>[['src'=>'../assets/icons/icon-192.png?v=20.1','sizes'=>'192x192','type'=>'image/png']]],
        ['name'=>meteonexa_backend_text('nav.intelligence', [], $language), 'short_name'=>meteonexa_backend_text('nav.intelligence', [], $language), 'url'=>'../#intelligence', 'icons'=>[['src'=>'../assets/icons/icon-192.png?v=20.1','sizes'=>'192x192','type'=>'image/png']]],
        ['name'=>meteonexa_backend_text('nav.mobile.alerts', [], $language), 'short_name'=>meteonexa_backend_text('nav.mobile.alerts', [], $language), 'url'=>'../#alerts', 'icons'=>[['src'=>'../assets/icons/icon-192.png?v=20.1','sizes'=>'192x192','type'=>'image/png']]],
        ['name'=>meteonexa_backend_text('home.alert_count.route_weather', [], $language), 'short_name'=>meteonexa_backend_text('home.alert_count.route_weather', [], $language), 'url'=>'../#route', 'icons'=>[['src'=>'../assets/icons/icon-192.png?v=20.1','sizes'=>'192x192','type'=>'image/png']]],
    ],
    'icons' => [
        ['src' => '../assets/icons/icon-192.png?v=20.1', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '../assets/icons/icon-512.png?v=20.1', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '../assets/icons/maskable-512.png?v=20.1', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

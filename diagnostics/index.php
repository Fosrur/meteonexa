<?php
declare(strict_types=1);

require dirname(__DIR__) . '/api/bootstrap.php';
require dirname(__DIR__) . '/api/auth_session.php';
require dirname(__DIR__) . '/api/error_page.php';
require dirname(__DIR__) . '/api/diagnostics_access.php';

try {
    $config = load_config();
    $pdo = meteonexa_db($config);
    $session = meteonexa_current_auth_session($pdo, $config, false);
    if (!is_array($session)) meteonexa_render_error_page(401, ['path'=>'/diagnostics/']);
    if (!meteonexa_diagnostics_authorized($pdo, $config, $session)) meteonexa_render_error_page(403, ['path'=>'/diagnostics/']);
} catch (Throwable $error) {
    meteonexa_render_error_page(503, ['path'=>'/diagnostics/']);
}
meteonexa_browser_page_security_headers();
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html class="i18n-pending" lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>MeteoNexa</title>
<link rel="icon" href="../assets/icons/favicon-32.png">
<link rel="stylesheet" href="../dist/diagnostics/diagnostics.1ebd4aca18a3.css">
</head>
<body class="diag-booting">
<div class="diag-boot" id="diag-boot" aria-live="polite"><span class="diag-spinner" aria-hidden="true"></span><strong data-i18n-key="diag.loading.session"></strong></div>
<main class="diag-shell" id="diag-app" hidden>
  <header class="diag-header">
    <a class="diag-back" href="../#settings" data-i18n-aria-label="diag.back" aria-label=""><span aria-hidden="true">←</span></a>
    <div class="diag-heading">
      <span class="diag-kicker"><span data-i18n-key="app.name"></span> <span aria-hidden="true">20.1</span></span>
      <h1 data-i18n-key="diag.title"></h1>
      <p data-i18n-key="diag.subtitle"></p>
    </div>
    <span class="diag-secure"><i aria-hidden="true"></i><span data-i18n-key="diag.secure"></span></span>
  </header>

  <section class="diag-summary-card">
    <div class="diag-summary-copy">
      <span class="diag-label" data-i18n-key="diag.summary.kicker"></span>
      <h2 data-i18n-key="diag.summary.title"></h2>
      <p data-i18n-key="diag.summary.copy"></p>
    </div>
    <div class="diag-summary-side">
      <span class="diag-session" id="diag-session"><i aria-hidden="true"></i><span data-i18n-key="diag.session.verified"></span></span>
      <button class="diag-button primary" type="button" data-diag-action="summary"><span data-i18n-key="diag.summary.button"></span></button>
    </div>
  </section>

  <div class="diag-dashboard">
    <section class="diag-services" aria-labelledby="diag-services-title">
      <div class="diag-section-title"><div><span class="diag-label" data-i18n-key="diag.services.kicker"></span><h2 id="diag-services-title" data-i18n-key="diag.services.title"></h2></div><p data-i18n-key="diag.services.copy"></p></div>

      <article class="diag-service-card">
        <span class="diag-service-icon" aria-hidden="true">@</span>
        <div class="diag-service-copy"><span class="diag-label" data-i18n-key="diag.smtp.kicker"></span><h3 data-i18n-key="diag.smtp.title"></h3><p data-i18n-key="diag.smtp.copy"></p></div>
        <button class="diag-button" type="button" data-diag-action="smtp"><span data-i18n-key="diag.smtp.button"></span></button>
      </article>

      <article class="diag-service-card">
        <span class="diag-service-icon" aria-hidden="true">AI</span>
        <div class="diag-service-copy"><span class="diag-label" data-i18n-key="diag.ai.kicker"></span><h3 data-i18n-key="diag.ai.title"></h3><p data-i18n-key="diag.ai.copy"></p></div>
        <button class="diag-button" type="button" data-diag-action="ai"><span data-i18n-key="diag.ai.button"></span></button>
      </article>

      <article class="diag-service-card">
        <span class="diag-service-icon" aria-hidden="true">↗</span>
        <div class="diag-service-copy"><span class="diag-label" data-i18n-key="diag.push.kicker"></span><h3 data-i18n-key="diag.push.title"></h3><p data-i18n-key="diag.push.copy"></p></div>
        <button class="diag-button" type="button" data-diag-action="push"><span data-i18n-key="diag.push.button"></span></button>
      </article>

      <article class="diag-service-card">
        <span class="diag-service-icon" aria-hidden="true">☁</span>
        <div class="diag-service-copy"><span class="diag-label" data-i18n-key="diag.weather.kicker"></span><h3 data-i18n-key="diag.weather.title"></h3><p data-i18n-key="diag.weather.copy"></p></div>
        <button class="diag-button" type="button" data-diag-action="weather"><span data-i18n-key="diag.weather.button"></span></button>
      </article>

      <article class="diag-service-card">
        <span class="diag-service-icon" aria-hidden="true">⚡</span>
        <div class="diag-service-copy"><span class="diag-label" data-i18n-key="diag.engine.kicker"></span><h3 data-i18n-key="diag.engine.title"></h3><p data-i18n-key="diag.engine.copy"></p></div>
        <button class="diag-button" type="button" data-diag-action="engine"><span data-i18n-key="diag.engine.button"></span></button>
      </article>

      <article class="diag-service-card">
        <span class="diag-service-icon" aria-hidden="true">6×</span>
        <div class="diag-service-copy"><span class="diag-label" data-i18n-key="diag.quality.kicker"></span><h3 data-i18n-key="diag.quality.title"></h3><p data-i18n-key="diag.quality.copy"></p></div>
        <button class="diag-button" type="button" data-diag-action="quality"><span data-i18n-key="diag.quality.button"></span></button>
      </article>
      <article class="diag-service-card">
        <span class="diag-service-icon" aria-hidden="true">↻</span>
        <div class="diag-service-copy"><span class="diag-label" data-i18n-key="diag.calibration.kicker"></span><h3 data-i18n-key="diag.calibration.title"></h3><p data-i18n-key="diag.calibration.copy"></p></div>
        <button class="diag-button" type="button" data-diag-action="calibration"><span data-i18n-key="diag.calibration.button"></span></button>
      </article>

      <article class="diag-service-card">
        <span class="diag-service-icon" aria-hidden="true">⌁</span>
        <div class="diag-service-copy"><span class="diag-label" data-i18n-key="diag.authdevices.kicker"></span><h3 data-i18n-key="diag.authdevices.title"></h3><p data-i18n-key="diag.authdevices.copy"></p></div>
        <button class="diag-button" type="button" data-diag-action="authdevices"><span data-i18n-key="diag.authdevices.button"></span></button>
      </article>
    </section>

    <aside class="diag-results" aria-labelledby="diag-results-title">
      <div class="diag-results-title">
        <div><span class="diag-label" data-i18n-key="diag.results.kicker"></span><h2 id="diag-results-title" data-i18n-key="diag.results.title"></h2></div>
        <button class="diag-clear" id="diag-clear" type="button" data-i18n-key="diag.results.clear"></button>
      </div>
      <div id="diag-results-list" class="diag-results-list"><p class="diag-empty" data-i18n-key="diag.results.empty"></p></div>
    </aside>
  </div>
</main>
<div class="diag-loader" id="diag-loader" aria-hidden="true"><span class="diag-spinner" aria-hidden="true"></span><strong data-i18n-key="diag.loading.test"></strong></div>
<script defer src="../dist/js/security-runtime.b4dbf8677d9d.js"></script>
<script defer src="../dist/js/i18n-runtime.8c5df08eb812.js"></script>
<script defer src="../dist/diagnostics/diagnostics.834385d4e357.js"></script>
</body>
</html>

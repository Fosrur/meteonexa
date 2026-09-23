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
    if (!is_array($session)) meteonexa_render_error_page(401, ['path'=>'/qa/']);
    if (!meteonexa_diagnostics_authorized($pdo, $config, $session)) meteonexa_render_error_page(403, ['path'=>'/qa/']);
} catch (Throwable $error) {
    meteonexa_render_error_page(503, ['path'=>'/qa/']);
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
<link rel="icon" href="../assets/icons/favicon-32.png?v=20.1">
<link rel="stylesheet" href="../dist/qa/qa.269ad8277a93.css">
</head>
<body class="qa-booting">
<div class="qa-boot" id="qa-boot" aria-live="polite"><span class="qa-spinner" aria-hidden="true"></span><strong data-i18n-key="qa.loading.session"></strong></div>
<main class="qa-shell" id="qa-app" hidden>
  <header class="qa-header">
    <a class="qa-back" href="../#settings" data-i18n-aria-label="qa.back" aria-label=""><span aria-hidden="true">←</span></a>
    <div class="qa-heading">
      <span class="qa-kicker"><span data-i18n-key="app.name"></span> <span aria-hidden="true">20.1</span></span>
      <h1 data-i18n-key="qa.title"></h1>
      <p data-i18n-key="qa.subtitle"></p>
    </div>
    <span class="qa-secure"><i aria-hidden="true"></i><span data-i18n-key="qa.secure"></span></span>
  </header>

  <section class="qa-summary-card">
    <div class="qa-summary-copy">
      <span class="qa-label" data-i18n-key="qa.gate.kicker"></span>
      <h2 data-i18n-key="qa.gate.title"></h2>
      <p data-i18n-key="qa.gate.copy"></p>
    </div>
    <div class="qa-summary-side">
      <span class="qa-session"><i aria-hidden="true"></i><span data-i18n-key="diag.session.verified"></span></span>
      <button class="qa-button primary" type="button" data-qa-action="runtime"><span data-i18n-key="qa.gate.button"></span></button>
    </div>
  </section>

  <div class="qa-dashboard">
    <section class="qa-services" aria-label="" data-i18n-aria-label="qa.tests.aria">
      <div class="qa-section-title"><div><span class="qa-label" data-i18n-key="diag.services.kicker"></span><h2 data-i18n-key="diag.services.title"></h2></div><p data-i18n-key="diag.services.copy"></p></div>

      <article class="qa-service-card"><span class="qa-service-icon" aria-hidden="true">DB</span><div class="qa-service-copy"><span class="qa-label" data-i18n-key="qa.runtime.kicker"></span><h3 data-i18n-key="qa.runtime.title"></h3><p data-i18n-key="qa.runtime.copy"></p></div><button class="qa-button" type="button" data-qa-action="summary"><span data-i18n-key="qa.runtime.button"></span></button></article>
      <article class="qa-service-card"><span class="qa-service-icon" aria-hidden="true">☁</span><div class="qa-service-copy"><span class="qa-label" data-i18n-key="qa.weather.kicker"></span><h3 data-i18n-key="qa.weather.title"></h3><p data-i18n-key="qa.weather.copy"></p></div><button class="qa-button" type="button" data-qa-action="weather"><span data-i18n-key="qa.weather.button"></span></button></article>
      <article class="qa-service-card"><span class="qa-service-icon" aria-hidden="true">⚡</span><div class="qa-service-copy"><span class="qa-label" data-i18n-key="qa.engine.kicker"></span><h3 data-i18n-key="qa.engine.title"></h3><p data-i18n-key="qa.engine.copy"></p></div><button class="qa-button" type="button" data-qa-action="engine"><span data-i18n-key="qa.engine.button"></span></button></article>
      <article class="qa-service-card"><span class="qa-service-icon" aria-hidden="true">6×</span><div class="qa-service-copy"><span class="qa-label" data-i18n-key="qa.quality.kicker"></span><h3 data-i18n-key="qa.quality.title"></h3><p data-i18n-key="qa.quality.copy"></p></div><button class="qa-button" type="button" data-qa-action="quality"><span data-i18n-key="qa.quality.button"></span></button></article>
      <article class="qa-service-card"><span class="qa-service-icon" aria-hidden="true">↻</span><div class="qa-service-copy"><span class="qa-label" data-i18n-key="qa.calibration.kicker"></span><h3 data-i18n-key="qa.calibration.title"></h3><p data-i18n-key="qa.calibration.copy"></p></div><button class="qa-button" type="button" data-qa-action="calibration"><span data-i18n-key="qa.calibration.button"></span></button></article>
      <article class="qa-service-card"><span class="qa-service-icon" aria-hidden="true">✓</span><div class="qa-service-copy"><span class="qa-label" data-i18n-key="qa.trust.kicker"></span><h3 data-i18n-key="qa.trust.title"></h3><p data-i18n-key="qa.trust.copy"></p></div><button class="qa-button" type="button" data-qa-action="trust"><span data-i18n-key="qa.trust.button"></span></button></article>
      <article class="qa-service-card explicit"><span class="qa-service-icon" aria-hidden="true">AI</span><div class="qa-service-copy"><span class="qa-label" data-i18n-key="qa.ai.kicker"></span><h3 data-i18n-key="qa.ai.title"></h3><p data-i18n-key="qa.ai.copy"></p></div><button class="qa-button" type="button" data-qa-action="ai"><span data-i18n-key="qa.ai.button"></span></button></article>
      <article class="qa-service-card explicit"><span class="qa-service-icon" aria-hidden="true">@</span><div class="qa-service-copy"><span class="qa-label" data-i18n-key="qa.smtp.kicker"></span><h3 data-i18n-key="qa.smtp.title"></h3><p data-i18n-key="qa.smtp.copy"></p></div><button class="qa-button" type="button" data-qa-action="smtp"><span data-i18n-key="qa.smtp.button"></span></button></article>
      <article class="qa-service-card explicit"><span class="qa-service-icon" aria-hidden="true">↗</span><div class="qa-service-copy"><span class="qa-label" data-i18n-key="qa.push.kicker"></span><h3 data-i18n-key="qa.push.title"></h3><p data-i18n-key="qa.push.copy"></p></div><button class="qa-button" type="button" data-qa-action="push"><span data-i18n-key="qa.push.button"></span></button></article>
    </section>

    <aside class="qa-results" aria-labelledby="qa-results-title">
      <div class="qa-results-title"><div><span class="qa-label" data-i18n-key="qa.results.kicker"></span><h2 id="qa-results-title" data-i18n-key="qa.results.title"></h2></div><button class="qa-clear" id="qa-clear" type="button" data-i18n-key="qa.results.clear"></button></div>
      <div class="qa-results-list" id="qa-results-list"><p class="qa-empty" data-i18n-key="qa.results.empty"></p></div>
    </aside>
  </div>
  <footer class="qa-footer"><a href="../diagnostics/" data-i18n-key="qa.open.diagnostics"></a><span data-i18n-key="qa.footer"></span></footer>
</main>
<div class="qa-loader" id="qa-loader" aria-hidden="true"><span class="qa-spinner" aria-hidden="true"></span><strong data-i18n-key="qa.loading.test"></strong></div>
<script defer src="../dist/js/security-runtime.b4dbf8677d9d.js"></script>
<script defer src="../dist/js/i18n-runtime.7a8eb94d7015.js"></script>
<script defer src="../dist/qa/qa.2fdf8f061a7b.js"></script>
</body>
</html>

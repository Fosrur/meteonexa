#!/usr/bin/env python3
"""P1 maintainability refactor contract."""
from pathlib import Path
import json,re,subprocess,sys
root=Path(sys.argv[1]).resolve() if len(sys.argv)>1 else Path(__file__).resolve().parents[1]
read=lambda p:(root/p).read_text(encoding='utf-8',errors='replace')
app=read('js/app.js'); store=read('modules/esm/core/store.mjs'); readme=read('readme.md'); dbfacade=read('api/database.php'); connection=read('api/database/connection.php'); migrations=read('api/database/migrations.php'); i18n=read('modules/esm/core/i18n-preferences.mjs'); account=read('modules/esm/domains/account.mjs'); authflow=read('modules/esm/domains/auth-flow.mjs'); radarmotion=read('modules/esm/domains/radar-motion.mjs'); radarcontroller=read('modules/esm/domains/radar-controller.mjs'); locations=read('modules/esm/domains/locations.mjs'); navigation=read('modules/esm/domains/navigation.mjs'); notifications=read('modules/esm/domains/notifications.mjs')
manifest=json.loads(read('asset-manifest.json'))
migration_versions=[int(path.name[:4]) for path in sorted((root/'api/database/migrations').glob('[0-9][0-9][0-9][0-9]_*.php'))]
checks={
    'legacy state bridge removed from app': 'bridgeLegacyState' not in app and 'syncFromLegacy' not in app and 'syncCoreState' not in app,
    'core attaches authoritative runtime state': 'attachRuntimeState' in store and 'attachRuntimeState?.(state)' in app,
    'core no mirrored legacy snapshot API': 'bridgeLegacyState' not in store and 'syncFromLegacy' not in store and 'let legacyState' not in store,
    'runtime state factory extracted': ("SERVICES.require('runtimeState').create" in app or 'MeteoNexaRuntimeState.create' in app) and (root/'modules/esm/core/runtime-state.mjs').is_file(),
    'weather utilities extracted': ("SERVICES.require('weatherUtils').create" in app or 'MeteoNexaWeatherUtils.create' in app) and (root/'modules/esm/core/weather-utils.mjs').is_file(),
    'tooltips extracted': ("SERVICES.require('tooltips')" in app or 'MeteoNexaTooltips' in app) and (root/'modules/esm/core/tooltips.mjs').is_file(),
    'app source reduced below phase-1 size': len(app.encode()) < 520000,
    'i18n and preferences extracted': 'installI18nPreferences' in i18n and 'translateDOM' in i18n and 'synchronizeRemotePreferences' in i18n and 'function translateDOM' not in app,
    'account synchronization extracted': 'provided.accountDomain' in account and 'provided.accountSync' in account and 'api/account/sync.php' in account and 'const accountSyncState' not in app,
    'auth flow extracted': 'provided.authFlow' in authflow and 'reconcileEmailServerSession' in authflow and 'requestEmailCode' in authflow and 'let authResendTimer' not in app,
    'radar predictive motion extracted': 'provided.radarMotion' in radarmotion and 'RADAR_MOTION_ANALYSIS_FAILED' in radarmotion and 'function radarBestShift' not in app,
    'radar controller extracted': 'provided.radarController' in radarcontroller and 'function initRadarVectorMap' in radarcontroller and 'function ensureRadar' in radarcontroller and 'function initRadarVectorMap' not in app and 'function ensureRadar' not in app,
    'app source reduced below phase-2 size': len(app.encode()) < 470000,
    'locations domain extracted': 'provided.locations' in locations and 'function getCurrentLocationData' in locations and 'function renderFavorites' in locations and 'function getCurrentLocationData' not in app and 'function renderFavorites' not in app,
    'navigation controller extracted': 'createController' in navigation and 'function goToPage' in navigation and 'function isGuestSession' in navigation and 'function goToPage' not in app and 'function isGuestSession' not in app,
    'app source reduced below phase-3 size': len(app.encode()) < 440000,
    'notifications and pwa domain extracted': 'provided.notifications' in notifications and 'function openNotificationCenter' in notifications and 'function registerPWA' in notifications and 'api/push/inbox.php' in notifications and 'function openNotificationCenter' not in app and 'function registerPWA' not in app,
    'notification event wiring extracted': 'function bindNotificationEvents' in notifications and 'bindNotificationEvents();' in app and "$('#notification-button')?.addEventListener" not in app,
    'app source reduced below final-p1 size': len(app.encode()) < 415000,
    'new core modules fingerprinted': all(k in manifest for k in ['modules/esm/core/store.mjs','modules/esm/core/runtime-state.mjs','modules/esm/core/tooltips.mjs','modules/esm/core/weather-utils.mjs','modules/esm/core/i18n-preferences.mjs']),
    'phase-2/3/4/final domain modules fingerprinted': all(k in manifest for k in ['modules/esm/domains/account.mjs','modules/esm/domains/auth-flow.mjs','modules/esm/domains/radar-motion.mjs','modules/esm/domains/radar-controller.mjs','modules/esm/domains/locations.mjs','modules/esm/domains/navigation.mjs','modules/esm/domains/notifications.mjs']),
    'database facade stays thin': len(dbfacade.splitlines()) <= 35 and "database/connection.php" in dbfacade and "database/migrations.php" in dbfacade,
    'database responsibilities split': all((root/'api/database'/name).is_file() for name in ['driver.php','crypto.php','schema.php','migrations.php','connection.php','metadata.php','smtp.php','ai.php','security.php']),
    'connection delegates migration execution': 'meteonexa_run_mysql_migrations' in connection and 'meteonexa_run_sqlite_migrations' in connection and 'meteonexa_current_schema_version()' in connection,
    'migration manifest reaches schema 28': 'return 28;' in migrations and migration_versions == list(range(16,29)),
    'readme documents p1': '## 20.1 — P1 refactoring di manutenibilità' in readme and 'Stato frontend autorevole' in readme and 'P1 fase 2 — i18n, account, auth e radar predittivo' in readme and 'P1 fase 3 — controller radar, MapLibre e playback' in readme and 'P1 fase 4 — località, ricerca, preferiti e navigazione' in readme and 'P1 finale — notification center, push e PWA' in readme and 'P1 completato' in readme and 'Backend database modulare' in readme,
}
failed=[]
for name,ok in checks.items():
    print(('PASS' if ok else 'FAIL'),name)
    if not ok: failed.append(name)
if failed: raise SystemExit('P1 maintainability smoke failed: '+', '.join(failed))
print('P1 maintainability refactor PASS')

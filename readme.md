# MeteoNexa 20.1 — Final Candidate

## P0 + P5/P6 stabilization — 21 settembre 2026

Questa revisione mantiene la release **20.1 RC2 / Final Candidate** e aggiunge hardening/affidabilità senza cambiare lo schema DB: CSP reporting first-party (`/api/csp-report.php`), verifica live dei relativi header, audit dipendenze schedulato indipendentemente dai deploy, runbook di rotazione chiavi, contratto che mantiene il worker fuori dalla rete pubblica `proxy`, test production-like della maintenance 503 con asset CSS/JS/logo/i18n, fallback maintenance leggibile anche in caso di failure degli asset, correzione del menu lingua login senza overlay sul CTA ospite, guardrail performance in browser reale e una lane PHPStan incrementale a livello 4 sul driver DB.

Il repository **non può modificare da solo il flag GitHub “Allow write access” di una deploy key**: il requisito read-only è documentato in `docs/docs/reports/ARCHITECTURE-SECURITY.md` (sezione Key rotation runbook) e va verificato una volta nelle impostazioni GitHub del repository. La rotazione VAPID è esplicitamente trattata come operazione che richiede nuova sottoscrizione push dei client.


<!-- METEONEXA_CURRENT_CONTRACT_START -->
## 20.1 RC2 — root organizzata, deploy verificabile e tooling cross-platform

La root del repository resta il **control plane + document root pubblico** della web app, ma non contiene più configurazioni quality non convenzionali sparse. Le pagine HTML pubbliche, `readme.md`, i file convenzionali di build/deploy e i metadati web restano in root; PHPStan, PHP-CS-Fixer e Semgrep sono centralizzati in `config/quality/`; le evidenze Markdown non normative sono in `docs/reports/`. I runtime JavaScript sono in `js/`, i bundle CSS in `css/`, gli ESM in `modules/esm/`, i partial CSS in `styles/` e gli endpoint/backend in `api/`. `qa/architecture_layout_smoke.py` protegge questa struttura. Lo spostamento dell'intero document root sotto `public/` resta volutamente fuori da questa stabilizzazione perché richiederebbe un refactoring coordinato di Apache, Dockerfile, Service Worker, fingerprint e QA.

Il catalogo i18n corrente è **4.645 chiavi × 5 lingue = 23.225 traduzioni**. Anche `maintenance.html` usa il contratto i18n condiviso e mantiene critical CSS/logo embedded per restare leggibile durante il cutover.

Il deploy production prepara il candidato **con il sito ancora online**: build delle immagini con SHA, backup del database/runtime e restore drill. `metadata.txt` registra `schema_source`; il restore deve riprodurre quello schema sorgente, non essere già allo schema target. Solo dopo questi gate viene attivato `/var/lib/meteonexa/maintenance.flag`, vengono ricreati `web` e `worker`, `/api/system/status.php` apre il DB ed esegue le migration necessarie fino allo schema corrente **28**, poi `verify-mysql.sh` richiede `MYSQL_SCHEMA_PASS`. Le immagini/container espongono la revisione Git tramite provenance OCI e il deploy deve verificare che la revisione corrisponda al `DEPLOY_SHA`. Nel workflow GitHub la maintenance resta attiva durante i controlli interni, viene rimossa prima dello smoke live esterno e viene ripristinata se quello smoke fallisce.

## Contratto corrente della release — 21 settembre 2026

Questa è la sezione normativa per lo stato attuale del sorgente. **Versione applicazione: 20.1 RC2 / Final Candidate; schema 28; tabelle applicative: 47; translation seed: `20.1-semantic-i18n-v2`; catalogo attivo: 4.645 chiavi × 5 lingue.** `readme.md` resta l'unica documentazione Markdown normativa; `docs/docs/reports/*.md` contiene soltanto evidenze/audit e non può ridefinire il contratto corrente.

Il comando autorevole per rigenerare `SHA256SUMS.txt` è `npm run checksums:update`, implementato in Node e quindi utilizzabile anche su Windows senza WSL/Bash. Il controllo in CI resta `npm run checksums:verify`.

Evidenza prima di questa riorganizzazione: **MeteoNexa QA #21** e **MeteoNexa Production Deploy #21** sul commit `496e610a8d01c263e921ad9621894fba396e6a8a` sono terminati con successo; il deploy ha ricreato `web` e `worker`, portato/verificato MySQL a `schema_version=28`, stampato `MYSQL_SCHEMA_PASS actual=28 expected=28`, rimosso la maintenance e superato lo smoke live esterno. Ogni nuovo commit, inclusa questa pulizia della root, deve superare nuovamente gli stessi gate prima di essere considerato production-ready.

### Checksum release cross-platform

Il generatore `tools/update-release-checksums.mjs` calcola gli SHA-256 dai blob presenti nell'indice Git, non dai line ending del working tree locale. In questo modo `npm run checksums:update` produce lo stesso manifest su Windows e Linux ed evita differenze CRLF/LF nei file di configurazione.
<!-- METEONEXA_CURRENT_CONTRACT_END -->

## P1 release/documentation hardening — 20 settembre 2026

- `qa/production_readiness_smoke.py` usa l'indice Git solo quando `git rev-parse --show-toplevel` coincide esattamente con la root applicativa; un repository padre non viene più scambiato per il repository MeteoNexa.
- Il contratto documentale distingue `readme.md` (source of truth) da `docs/docs/reports/*.md` (evidenze non normative), evitando falsi failure nei bundle di audit.
- `qa/release_provenance_smoke.py` valida l'identità VCS: nei source bundle senza `.git` opera in modalità neutra; in CI richiede repository alla root, HEAD committato e working tree pulita.
- Le sezioni storiche dello stesso README sono state rese esplicite come snapshot temporali; lo schema attuale resta esclusivamente **28**.

## Hotfix browser reale post-deploy — 20 settembre 2026

Questa revisione resta **MeteoNexa 20.1 RC2 / Final Candidate**: non viene promossa a `20.1 Final` finché il nuovo sorgente non supera nuovamente CI browser, deploy e collaudo live.

- **Assistant Center:** apertura, chiusura, invio, cestino, suggerimenti dinamici, cambio modalità e scelta AI/locale sono ora gestiti con delega `document` persistente. Il form intercetta sempre `submit` con `preventDefault()`, quindi il guest non può più ricadere sul submit HTML nativo e tornare alla login quando preme **Invia**.
- **Guest contract:** guest = assistente locale; sessione email verificata = locale + AI. Le azioni interne dell'Assistant non dipendono più dal timing di `suite.bind()` né dalla sopravvivenza di singoli nodi DOM sostituiti durante bootstrap/render.
- **Radar provider failure:** un errore upstream non viene più mascherato come tile trasparente HTTP 200. Se esiste una tile cache precedente viene servita come `STALE`; in assenza di cache il proxy risponde 502 con PNG minimale e header `X-MeteoNexa-Tile-Fallback: provider-error`, così UI/MapLibre possono rilevare e segnalare davvero il guasto.
- **PWA cache:** Service Worker revisionato a `20.1-final-04-assistant-runtime-radar` per invalidare la shell precedente e distribuire i nuovi asset Assistant/Radar.
- **Line ending deployment:** aggiunto `.gitattributes` con `eol=lf` per script e file runtime, così i `.sh` non vengono più pubblicati con CRLF da Windows.
- **QA:** aggiunto E2E per invio/suggerimenti/cestino/chiusura Assistant guest; il test Radar mantiene le stesse asserzioni ma dispone di 45 s complessivi per esercitare in sequenza i quattro layer.

## Remediation runtime post-deploy — 20 settembre 2026

Questa revisione corregge regressioni emerse nel collaudo browser reale dopo il deploy del Final Candidate.

- **Origin canonico:** `https://www.meteonexa.com/` è l’unico origin UI canonico. Le navigazioni HTML GET/HEAD su `https://meteonexa.com/` vengono redirette con 308 a `www`; API e Service Worker non sono forzati cross-origin, così una PWA già installata può aggiornarsi senza rompere sessione/bootstrap.
- **Device identity:** `js/security-runtime.js` è l’unico proprietario di `deviceId/deviceKey`. `accountDomain` consuma la dipendenza `security` del service registry e non emette sync account/località con `deviceId` vuoto.
- **Assistant:** guest = chatbot locale; sessione email verificata = chatbot locale + AI esterna. `assistant-center-button` apre il dialog in entrambi i casi, mentre la modalità AI è nascosta/disabilitata per il guest e il trasporto AI resta protetto.
- **Radar:** Radar / Precipitazioni / Satellite / Fulmini conservano un singolo binding DOM. Il controllo Fulmini non viene più clonato a runtime e viene delegato a `suiteIntegrations` tramite service registry. Il radar osservato usa LibreWXR palette 10; il forecast precipitazioni resta separato su Open-Meteo.
- **QA:** aggiunti gate specifici per device identity, guest assistant, Assistant Center e binding layer radar. I gate precedenti coprivano bene build/security/contratti statici ma non esercitavano questi percorsi UI reali.
- **Browser gate remediation:** la prima run del commit `55ae909` ha esposto due errori nel setup E2E (app shell guest ancora `hidden` nel test e pagina radar marcata `active` invece di `active-page`) e un controllo runtime Fulmini rimasto sulla vecchia classe `active`. I test ora attraversano lo stato visibile reale, il timer Fulmini usa `active-page` e i job Chromium/Firefox ricostruiscono `npm run build:production` prima di servire l’app, così Playwright prova gli asset generati dal sorgente del commit e non un `dist` preesistente.
- **Browser gate remediation #27:** Chromium e Firefox hanno entrambi chiuso 28/31 e hanno isolato tre problemi deterministici: il trigger Assistant Center dipendeva ancora dal binding tardivo di `suite.bind()`, il test History cercava nomi di funzione non minificati dentro l’output esbuild, e il test dei layer Radar usava `elementFromPoint()` su controlli sotto la viewport. Il trigger Assistant Center usa ora delega document-level indipendente dal timing di `meteonexa:ready`; History valida marker semantici del bundle production; Radar usa `scrollIntoViewIfNeeded()` + Playwright trial click per verificare il vero hit target.

### Security — invarianti correnti

1. nessuna chiamata account/località parte con identificativo dispositivo vuoto;
2. il guest non può invocare AI esterna, sync account, preferenze private o archivio radar;
3. una sessione email è privata solo dopo riconciliazione HttpOnly + device proof;
4. la canonicalizzazione `www` riguarda le navigazioni HTML, non forza API/SW cross-origin;
5. il radar proxy mantiene allowlist host, firma host-bound, validazione tile, quote e verifica content-type.

### Architettura — invarianti correnti

Il service registry ESM resta il boundary autorevole: `security` fornisce la device identity; `accountDomain` la usa dal dependency view senza shadowing; `suiteAssistant` separa motore locale e trasporto AI; `advanced` coordina i layer radar e delega Fulmini a `suiteIntegrations` senza sostituire nodi DOM già bindati.

> **Source of truth operativa — 20 settembre 2026.** Le sezioni storiche più sotto documentano RC1/RC2 e possono descrivere stati precedenti; in caso di contrasto prevale questa sezione.

## Stato corrente

La roadmap software 20.1 è completata nel sorgente corrente. La release resta **Final Candidate** finché non sono verificati sul commit pubblicato: GitHub Actions completo, deploy production con backup/restore, smoke live e integrazioni reali. Il nome **20.1 Final** va assegnato solo dopo questi gate.

### Roadmap software 20.1

- **Security / Docker / CI contract:** completato. Compose hardened, runtime non-root/read-only, backup/restore drill, MySQL 8.4, PHPStan, ESLint, Semgrep, Trivy e browser regression sono parte del contratto CI.
- **Radar / Nowcast:** completato nel codice, incluso proxy radar globale, palette provider corretta, firma host-bound, cache/fallback, tracking multi-frame, ETA e probabilistic nowcast. Resta la validazione live post-deploy su più continenti/zoom.
- **Intelligence / Confidence / Skill:** completato, con confidence timeline, model skill, sample-size protection, decay e pesi dinamici.
- **Copilot AI:** completato come layer esplicativo sopra decisioni meteorologiche deterministiche.
- **Personal Weather Twin:** completato per i profili supportati e le soglie personalizzabili.
- **Forecast Change / Decision Timeline:** completato con rilevamento dei cambiamenti materiali e finestre decisionali.
- **Alert lifecycle:** completato nel codice, inclusi lifecycle, deduplica, cooldown e quiet hours. Durante le quiet hours le notifiche ordinarie sono soppresse; le allerte di severità `red` possono superare il silenzio come safety override.
- **Route Weather:** completato nel contratto 20.1 con campionamento temporale/spaziale, rischi meteo e suggerimento della migliore partenza.
- **Public Accuracy:** implementazione completata. La pubblicazione resta intenzionalmente subordinata a campioni/contributor reali sufficienti: il software non inventa accuratezza quando i dati non sono maturi.
- **Sun & Cloud Window:** completato con motore deterministico basato su copertura nuvolosa, probabilità pioggia, `is_day`, sunshine duration e shortwave radiation; il satellite può solo correggere prudentemente la confidence.
- **Widget / PWA quick actions:** completato il contratto web/PWA con quick actions reali nel Web App Manifest per Radar, Intelligence, Alerts e Route Weather, oltre alle preview/widget interne. I widget nativi OS fuori dallo standard PWA richiederebbero packaging nativo e non vengono falsamente dichiarati parte della 20.1 web.
- **Analytics/tracciamento utenti:** rimosso. Plausible e Product Metrics non sono caricati né raccolti; restano esclusivamente log/metriche tecniche di affidabilità.
- **Privacy/Cookie Policy:** riallineate alla rimozione del tracking.
- **i18n:** catalogo corrente **4645 chiavi × 5 lingue = 23225 traduzioni**; seed e baseline SQLite riallineati.

## Gate necessari per il freeze 20.1 Final

1. commit e push del Final Candidate corrente;
2. GitHub Actions completo sul commit pubblicato, inclusi Chromium e Firefox;
3. deploy sul VPS tramite procedura ufficiale con backup e verifica restore;
4. validazione radar live su Europa, USA, Asia-Pacifico e area oceanica, a zoom differenti;
5. verifica production di SMTP/configurazione mail, push/worker, auth e Privacy/Cookie Policy; i metadati legali restano opzionali a livello tecnico e, se configurati, devono contenere solo valori reali;
6. smoke post-deploy e verifica rollback readiness;
7. solo dopo tutti i gate verdi: freeze/tag **20.1 Final**.

## Revisione corrente

- Corretto il proxy radar globale: `tileColor=10` non viene più degradato dal tile endpoint.
- Rimossi completamente runtime/API di traffic analytics e Product Metrics e aggiornate le policy.
- Aggiunto **Sun & Cloud Window** a Intelligence.
- Aggiunte **PWA Quick Actions** reali nel manifest.
- Chiuso il comportamento **Alert quiet hours**, con override limitato alle allerte `red`.
- Service Worker portato alla revisione `20.1-final-04-assistant-runtime-radar`.
- Aggiunto `qa/roadmap_20_1_completion_smoke.py` al gate aggregato.

### QA locale di questa revisione

Dopo l'hardening P0/P1, il comando aggregato `bash qa/run-all.sh` è stato rieseguito integralmente sul source bundle e termina con **`MeteoNexa 20.1 QA PASS`**. Restano separati i gate che richiedono servizi/tool esterni della CI (MySQL/Docker/security scanner e Playwright Chromium/Firefox), che devono risultare verdi sul nuovo commit pubblicato prima del freeze Final.

`npm ci` non è terminato in questo ambiente per timeout di trasporto: ESLint/esbuild e i browser E2E devono quindi essere considerati verificati per **questa revisione** solo quando il nuovo GitHub Actions del commit pubblicato sarà verde. La pipeline contiene già la matrice Playwright **Chromium + Firefox**.

## 20.1 RC2 Stabilization contract — 18 settembre 2026

### RC2 — fix runtime e production readiness

RC2 chiude i due regressivi emersi dopo RC1: il servizio `advanced` resta immutabile e Suite si integra tramite `registerLifecycleHook()` invece di sovrascrivere `renderAll/onPage/locationChanged/afterRefresh`; inoltre `Continua come ospite` usa l'intero `<button>` come hit target mouse/touch, con i figli a `pointer-events:none`. Entrambi i casi sono coperti da smoke test ed E2E.

Contratto deployment RC2: **un solo `docker-compose.yml`** per produzione e staging; lo staging isola progetto, container e `${METEONEXA_RUNTIME_DIR}` tramite `docker/staging-up.sh`. Il runtime applicativo resta un host bind mount intenzionale (backup/restore trasparente sul VPS), mentre MySQL usa il named volume `meteonexa_mysql`. Il backup ufficiale è `mysql.sql.gz + runtime.tar.gz + metadata.txt + SHA256SUMS`; il restore usa lo stesso formato e il drill importa realmente il dump in MySQL 8.4 temporaneo verificando `schema_version=28`.

Supply-chain RC2: `package-lock.json`, `qa/package-lock.json` e `composer.lock` sono obbligatori; la CI usa `npm ci`, Composer installa dal lock, e mantiene PHPStan, ESLint/esbuild, Semgrep, Trivy, MySQL 8.4 e Playwright Chromium+Firefox.

**GO production:** non è implicito nella sigla RC2. Il freeze 20.1 Final avviene solo dopo i gate live/staging. I metadati legali sono opzionali per il gate tecnico; se configurati devono essere reali e non vanno mai inventati.


### RC2 — remediation della prima CI GitHub reale

La prima esecuzione GitHub Actions della RC2 ha validato l'avvio del workflow e ha evidenziato esclusivamente problemi di release engineering: permessi eseguibili degli script shell persi durante il passaggio ZIP/Windows/GitHub, metadato Composer `license` assente con `composer validate --strict`, e MySQL 8.4 che richiede `log_bin_trust_function_creators=1` per creare i trigger con l'utente applicativo quando il binary logging è attivo. RC2 corregge questi punti senza modificare il comportamento funzionale dell'applicazione.

Il compose MySQL ufficiale abilita quindi `--log-bin-trust-function-creators=1`; la CI MySQL standalone abilita lo stesso flag dinamicamente come root prima dei test e continua ad eseguire i test applicativi con l'utente `meteonexa`. I workflow invocano inoltre gli script operativi tramite `bash`, così la CI non dipende dal bit eseguibile preservato dall'host Windows o dall'archivio ZIP. `composer.json` dichiara `license: proprietary`, coerente con un'applicazione closed-source e con la validazione Composer strict.

### Evidenza CI storica RC2 (non identifica il pacchetto corrente)

**Evidenza storica:** GitHub Actions run 20 sul commit `f8d22fe9898d1f82626b8e1a2024a87da7043b3b` (19 settembre 2026) risultò PASS completo sul trigger `push`; questo SHA non identifica il source bundle corrente e non deve essere usato come provenance della nuova release.

- `release-gates`: PASS, incluso rebuild deterministico degli asset e suite regression;
- `mysql-auth-regression`: PASS su MySQL 8.4;
- `backup-restore-drill`: PASS con backup ufficiale e restore reale dello schema 28;
- `staging-compose`: PASS con compose production-like hardened;
- `quality-security`: PASS per PHPStan, ESLint, esbuild/production build, Semgrep, Trivy filesystem e Trivy immagine;
- `browser-regression (chromium)`: PASS;
- `browser-regression (firefox)`: PASS.

**`live-production-security` non viene eseguito sui push:** il workflow lo abilita solo per `workflow_dispatch` o `schedule`. Il job esegue `python3 qa/live_security_check.py https://www.meteonexa.com/` e verifica il sito già pubblicato; non effettua alcun deploy.

**Deployment:** il workflow `MeteoNexa QA` non contiene step SSH/SCP/rsync/deploy e non pubblica la RC sul VPS. Il rilascio production resta un’operazione separata sul VPS, da eseguire solo dopo i gate GO, con backup/rollback disponibili.

**Aggiornamento successivo al run 20:** il `workflow_dispatch` con `live-production-security` è stato successivamente eseguito ed è passato. Quel risultato ha validato il sito allora pubblicato, ma non distribuisce automaticamente le revisioni sorgente successive. Restano quindi da completare, per il pacchetto corrente, il nuovo CI, le integrazioni con credenziali reali, il deploy con backup e gli smoke test post-deploy/rollback. I metadati legali non sono un gate tecnico e non vanno popolati con placeholder.

La build resta **MeteoNexa 20.1 RC2** e non è implicitamente deployata in produzione. Lo **Stato corrente — 20 settembre 2026** in testa al documento è la source of truth operativa.
### Stato stabilizzazione corrente

- Corretto `ReferenceError: updateThreshold is not defined`.
- Corretto `TypeError: "renderAll" is read-only` eliminando le assegnazioni dirette a `advanced.renderAll/onPage/afterRefresh/locationChanged`.
- CTA **Continua come ospite** resa interamente cliccabile/tappabile: hit target sul `<button>` completo, children non intercettano pointer, `touch-action: manipulation`.


## Storico RC1 — runtime fix 11

Questo README resta l’unica documentazione Markdown **normativa** della release. Consolida architettura, security, audit RC, release notes, checklist e note CSS precedentemente separate; eventuali `docs/docs/reports/*.md` sono soltanto evidenze non normative.

### Fix applicato in questa revisione

Errore production osservato:

- `Uncaught ReferenceError: updateThreshold is not defined` in `js/app.js`;
- successivi `METEONEXA_RUNTIME_API_NOT_READY` in `js/advanced.js`, `js/suite.js` e bootstrap ESM.

Causa radice: `updateThreshold()` era stato estratto in `modules/esm/domains/app-utilities.mjs`, ma il servizio `appUtilities` non lo restituiva e `js/app.js` non lo acquisiva tramite destructuring. Quando `js/app.js` costruiva il payload per `runtimeApi.publish(...)`, la reference inesistente interrompeva l’esecuzione prima della pubblicazione della Runtime API. I bundle Advanced/Suite, correttamente dipendenti da `runtimeApi.get()`, fallivano quindi a cascata con `METEONEXA_RUNTIME_API_NOT_READY`.

Correzioni:

- `appUtilities.create()` ora espone `updateThreshold`;
- `js/app.js` lega `updateThreshold` dal servizio prima di pubblicare la Runtime API;
- aggiunto test di regressione in `qa/p3_final_architecture_smoke.mjs`;
- revisione Service Worker aggiornata a `rc1-runtimefix-12-lifecycle-guest` per invalidare la vecchia shell;
- asset fingerprintati rigenerati (`js/app.js` -> `dist/app.b3d4fa62ce8b.js`, `app-utilities.mjs` -> `dist/modules/esm/domains/app-utilities.91c064bdb67d.mjs`);
- corretto il QA i18n affinché rispetti l’attributo esplicito `data-i18n-skip`.

### Verifiche eseguite

PASS: sintassi JavaScript, static analysis, Runtime API ESM, service publication dei 28 moduli, dependency injection, shell architecture, backend maintainability, CSS architecture, semantic i18n, production-build contract, P5 audit/performance, RC smoke, hardcoded-copy i18n, P0 security refactor e lint PHP su 145 file.

Il contratto P4 è stato riallineato alla scelta operativa ufficiale: `web` e `worker` usano `./runtime:/var/lib/meteonexa`. Il bind mount è intenzionale e deve essere protetto/backupato sul VPS; MySQL resta su named volume dedicato.

### Security review sintetica

- Nessun `eval`, `new Function` o `document.write` nei sorgenti runtime verificati.
- Runtime API e service registry mantengono boundary espliciti e immutabili.
- QA P0 conferma allowlist amministrativa deployment-owned, assenza di bootstrap admin hardcoded e integrità MapLibre pin/SHA-512.
- Le superfici PHP controllate passano il lint sintattico.
- I gate esistenti documentano cookie auth HttpOnly/Secure/SameSite, CSRF/same-origin, CSP/HSTS, rate limiting, upload policy e hardening container; il punto Docker runtime sopra resta l’unico gate strutturale fallito nel controllo effettuato.
- La verifica effettuata è statica/locale: non sostituisce DAST, dependency scan online, test browser reali o test MySQL/SMTP contro l’ambiente production.

### Architettura — valutazione aggiornata

La causa del bug non richiede rollback dell’architettura ESM/DI. Il problema era un contratto di export incompleto al confine tra `appUtilities` e il classic shell `js/app.js`. Il fix mantiene la direzione architetturale: singolo state autorevole, service registry chiuso, Runtime API pubblicata solo dopo la composizione del main shell, domini ESM con dipendenze dichiarate. Il nuovo test di regressione verifica esattamente quel confine.

---


## Documento consolidato da `README.md`

# MeteoNexa 20.1

Manuale unico di sviluppo, rilascio e gestione della release MeteoNexa.

## Stato della release

- Brand: **MeteoNexa**
- Versione applicazione: **20.1**
- Stato: **Release Candidate RC2 — code-complete per staging; produzione solo dopo tutti i gate GO**
- Schema DB: **28**
- Produzione: **Docker + Apache/PHP 8.3 + MySQL 8.4 + Caddy HTTPS**
- Dominio produzione canonico: **https://www.meteonexa.com/**; le navigazioni HTML GET/HEAD su **https://meteonexa.com/** ricevono redirect **308** verso `www`.
- Sviluppo localhost: **SQLite**
- SMTP produzione: **Brevo SMTP Relay**, porta `587`, `STARTTLS`, `AUTH PLAIN`
- Mittente automatico consigliato: `MeteoNexa <alerts@meteonexa.com>`
- Worker: pipeline meteo + push dispatch
- PWA: Service Worker, manifest, offline shell
- Lingue: IT / EN / ES / FR / DE

Questo ZIP è il backup sorgente pulito della release. Non contiene password SMTP, API key, `.app-secret`, `database.json`, dati utente o dump del database MySQL di produzione.

La copia SQLite distributiva e sanitizzata è:

```text
api/install/meteonexa-baseline.sqlite
```

È lo snapshot SQLite da usare per localhost o per inizializzare una nuova installazione. Contiene schema 28, traduzioni e configurazioni non sensibili; le tabelle con sessioni, dispositivi, credenziali, AI e dati personali sono vuote.

---

## 20.1 — Audit finale post-roadmap + performance bootstrap

Questa sezione è la **source of truth corrente**; le sezioni P0/P1/P2/P3/P4 successive restano come storico del percorso di refactoring e possono descrivere numeri/schema validi soltanto al momento di quella tranche.

Audit completo dell’ultimo pacchetto: architettura ESM/DI, backend/database, header/CSP, auth/sessioni, upload, Cookie Policy, Privacy Policy, cataloghi i18n e build. La suite precedente risultava verde, ma il controllo indipendente ha trovato due drift non intercettati dai gate storici: **96 chiavi hash-like residue** sotto `html.*` / `attr.*` / `meta.*` e una Privacy Policy che non esponeva l’identità legale del Titolare configurabile dal deployment. Entrambi sono stati corretti.

- schema corrente: **28**;
- `translation_seed_version`: **20.1-semantic-i18n-v2**;
- catalogo: **4.645 chiavi × 5 lingue = 23.225 traduzioni**;
- chiavi i18n hash-like attive: **0**;
- migration semantiche: `0027` (1.221 chiavi `ui/code`) + `0028` (96 chiavi residue `html/attr/meta`);
- identità legale Art. 13: `METEONEXA_LEGAL_CONTROLLER_NAME`, `METEONEXA_LEGAL_CONTROLLER_ADDRESS`, `METEONEXA_PRIVACY_CONTACT_EMAIL`; DPO opzionale con `METEONEXA_DPO_EMAIL`;
- la pagina Privacy mostra un warning localizzato se l’identità legale non è completa, invece di inventare un Titolare;
- link/informativa AI sono coerenti con `openrouter` o `groq` effettivamente configurato;
- il bootstrap ESM avvia in parallelo il download/import dei moduli pre-app, ma conserva l’installazione sequenziale dichiarata per mantenere side effect e dipendenze deterministici.

Cookie Policy e implementazione restano allineate ai cookie tecnici first-party (`meteonexa_auth_session`, `meteonexa_trusted_device`, `meteonexa_client`, `meteonexa_language`). Plausible/Product Metrics e il relativo tracking applicativo sono rimossi; non sono presenti cookie pubblicitari o profilanti.

Il nuovo gate `qa/p5_audit_performance_smoke.py` protegge legal context, cookie/security contract, zero chiavi hash residue e bootstrap parallelo.

**Supply-chain RC2:** il repository contiene `package-lock.json`, `qa/package-lock.json` e `composer.lock`; le versioni dirette di `esbuild`, `eslint`, Playwright, PHP-CS-Fixer e PHPStan sono fissate. La CI non usa fallback `npm install`: esegue `npm ci`, `composer validate`/`composer install`, Semgrep e Trivy. `tools/generate-lockfiles.sh` resta l’unico comando di manutenzione per rigenerare i lock quando si aggiornano intenzionalmente le dipendenze.

---

## 20.1 — Release Candidate: startup, cache e runtime radar

Questa revisione è la **Release Candidate consigliata per il collaudo pre-produzione**. Non introduce nuove API pubbliche o modifiche allo schema DB rispetto alla P5 (schema **28**): chiude invece il percorso performance con correzioni runtime e ottimizzazioni misurabili sul critical path.

### Correzione runtime bloccante prima della release

L'audit della RC ha rilevato un problema non intercettato dai precedenti smoke test: `visualization.mjs` usava ancora un identificatore `SERVICES` non dichiarato quando costruiva il controller radar e `js/app.js` utilizzava funzioni radar non restituite dalla factory. In browser questo poteva produrre `ReferenceError` durante il bootstrap o all'apertura Radar. La RC corregge il contratto: `visualization` dichiara `radarMotion` e `radarController` nel dependency graph, usa `deps.*` e restituisce esplicitamente le funzioni radar consumate da `js/app.js`. Il gate `qa/release_candidate_smoke.py` impedisce la regressione.

### Critical path più leggero

- i moduli esclusivi di Suite (`suite-support`, `route-weather`, `suite-assistant`) non fanno più parte del gruppo `PRE_APP_ESM`: vengono importati/installati solo dopo `js/app.js` e Copilot, prima di `js/suite.js`;
- `js/app.js` viene pre-caricato ad alta priorità mentre Core e domini ESM vengono installati;
- `css/suite.css`, `css/intelligence.css`, `css/decision-timeline.css`, `css/watch-plan.css`, `css/copilot.css` e MapLibre CSS non bloccano più il first paint: vengono applicati dopo il primo frame;
- MapLibre JS è parser-deferred;
- payload ESM pre-app sorgente: **~499,8 KB → ~421,2 KB**;
- CSS render-blocking sorgente: **~618 KB → ~420 KB**, oltre alla rimozione di MapLibre CSS dal percorso bloccante.

### Service Worker / cache

La shell PWA è ora divisa in `CRITICAL_SHELL` e `OPTIONAL_SHELL`. L'installazione precachea soltanto la shell necessaria al bootstrap; policy, lingue secondarie, map assets e CSS feature vengono scaldati successivamente in idle tramite `WARM_OPTIONAL_SHELL`. Gli asset content-addressed `dist/*.<sha12>.*` usano la cache HTTP esistente (`force-cache`) durante il warmup invece di essere riscaricati forzatamente. Le richieste di warmup sono limitate a quattro worker concorrenti. Revisione shell: **`rc1-runtimefix-12-lifecycle-guest`**.

### Stato Release Candidate

- schema DB: **28**;
- 47 tabelle SQLite/MySQL;
- 4.645 chiavi × 5 lingue = **23.225 traduzioni**;
- chiavi i18n hash-like attive: **0**;
- P0→P4 + audit P5: mantenuti;
- nuovo gate RC: `npm run check:rc`;
- checklist operativa: `RELEASE-CHECKLIST.md`.

Prima del deploy production valorizzare obbligatoriamente identità legale/privacy in `.env`, completare la CI (inclusi ESLint/esbuild/PHPStan/Semgrep/Trivy/MySQL/Playwright) e seguire `RELEASE-CHECKLIST.md`.

---


## 20.1 — P1 refactoring di manutenibilità

Nello **snapshot storico P1**, la versione applicazione era già 20.1 ma lo schema allora corrente era **26**; quella revisione manteneva il comportamento funzionale invariato e riduceva il debito strutturale individuato nell’audit architetturale. Non introduce framework frontend, non modifica provider meteo, Decision Engine, autenticazione, privacy o formato delle API pubbliche.

### Stato frontend autorevole

`modules/esm/core/store.mjs` non mantiene più una seconda copia sincronizzata del vecchio stato applicativo. Sono stati rimossi `bridgeLegacyState`, `syncFromLegacy` e i relativi richiami sparsi in `js/app.js`. Il Core viene collegato una sola volta con `attachRuntimeState(state)` e costruisce le viste di dominio direttamente dallo **stato runtime autorevole**; i moduli di dominio continuano a pubblicare eventi/patch senza richiedere una sincronizzazione manuale successiva. In questo modo cambi di località, navigazione, meteo, radar, auth e privacy non possono divergere a causa di una copia Core rimasta indietro.

La costruzione iniziale dello stato è stata estratta in `modules/esm/core/runtime-state.mjs`. Inoltre le utility meteo/località/timezone/preview sono state spostate in `modules/esm/core/weather-utils.mjs` e il lifecycle dei tooltip in `modules/esm/core/tooltips.mjs`. `js/app.js` resta ancora il principale file applicativo e sarà ulteriormente ridotto nei P1 successivi, ma non contiene più questi blocchi infrastrutturali. Tutti i nuovi moduli sono inclusi nella pipeline di fingerprinting `dist/` e nel manifest degli asset.

### P1 fase 2 — i18n, account, auth e radar predittivo

La seconda tranche P1 continua la riduzione di `js/app.js` senza cambiare API o comportamento utente. Il coordinamento **i18n/preferenze** è ora in `modules/esm/core/i18n-preferences.mjs`: catalogo dinamico, observer DOM, lingua/tema, persistenza locale, sync remota e selettori lingua restano collegati allo stesso `state`, ma non sono più implementati nel bootstrap principale.

La sincronizzazione account è stata estratta in `modules/esm/domains/account.mjs`: preferiti, profili attività e idratazione post-login usano ancora gli stessi endpoint e mantengono `window.MeteoNexaAccountSync` come contratto pubblico compatibile. Il flusso email/OTP/trusted re-entry è stato spostato in `modules/esm/domains/auth-flow.mjs`; timer resend, stato SMTP, riconciliazione della sessione server e dialog auth non mantengono più stato temporaneo globale dentro `js/app.js`.

Il primo blocco radar ad alta complessità è stato separato in `modules/esm/domains/radar-motion.mjs`: proiezione tile, compositing delle immagini radar, stima del vettore di movimento, ETA/uscita precipitazione, confidence e rendering del pannello predittivo. La mappa/live controller resta per ora nel bootstrap applicativo e verrà ridotto in tranche successive: questa scelta evita una riscrittura "big bang" del radar.

Dopo questa tranche `js/app.js` passa da circa **565 KB / 10.668 righe** del primo P1 a circa **507 KB / 9.506 righe**. I nuovi moduli sono sorgenti first-party fingerprintate in `dist/`; il caricamento resta `defer` e avviene prima di `js/app.js`, quindi non vengono introdotti bundle/runtime esterni.

### P1 fase 3 — controller radar, MapLibre e playback

La terza tranche P1 completa la separazione del controller radar principale da `js/app.js`. Il nuovo `modules/esm/domains/radar-controller.mjs` possiede il lifecycle della mappa MapLibre, il layer raster live, fallback canvas, recovery WebGL, sincronizzazione camera, ricerca/GPS dalla pagina radar, caricamento live/forecast, refresh, cambio modalità, frame slider, playback e zoom. Il motore predittivo resta separato in `modules/esm/domains/radar-motion.mjs`: controller e motion condividono lo stesso `state` runtime ma hanno responsabilità distinte.

Il controller non introduce un nuovo store o nuove API globali applicative: viene creato dal bootstrap con dipendenze esplicite (`CONFIG`, utility DOM, persistenza, ricerca località, fetch meteo e funzioni del motion engine). `js/app.js` conserva solo il wiring tra i moduli e le chiamate usate dal resto dell'interfaccia. Anche `radar-controller.js` è incluso nel fingerprinting `dist/` e viene caricato con `defer` prima di `js/app.js`.

Dopo questa tranche `js/app.js` scende da circa **507 KB / 9.506 righe** a circa **461 KB / 8.429 righe**. Il blocco radar estratto misura circa **57 KB / 1.121 righe**, ma è ora isolato per dominio e può essere testato/refactorizzato senza attraversare il bootstrap generale. Versione applicazione, schema DB, endpoint e comportamento utente restano invariati.

### P1 fase 4 — località, ricerca, preferiti e navigazione

La quarta tranche P1 estrae da `js/app.js` il dominio località in `modules/esm/domains/locations.mjs`. Il modulo gestisce geolocalizzazione browser e reverse geocoding, ricerca città, selezione onboarding, recenti, cambio località, preferiti, caricamento sintetico delle card preferite e le azioni di ricerca globale/command search. Il bootstrap continua a fornire dipendenze esplicite e lo stesso `state` runtime: non viene introdotto un secondo store e gli endpoint/provider restano invariati.

`modules/esm/domains/navigation.mjs` non è più soltanto l'event bus per `navigation-request`: possiede ora anche la policy guest/auth, la visibilità delle feature/pagine, il caricamento della configurazione UI, il routing tra pagine e il relativo lifecycle. `js/app.js` conserva il wiring verso radar, history, Intelligence e Advanced mediante callback esplicite, evitando dipendenze implicite o un service locator globale.

Dopo questa tranche `js/app.js` scende a circa **430 KB / 7.886 righe**. Geolocalizzazione, ricerca/preferiti e routing non sono più implementati nel bootstrap principale. `locations.js` e il controller esteso di `navigation.js` sono inclusi nel fingerprinting `dist/` e caricati con `defer` prima di `js/app.js`. Resta un ultimo blocco P1 concentrato su notification center/push e pulizia finale del bootstrap; dopo quel passaggio il progetto può entrare nel P2 senza lasciare i principali domini funzionali dentro `js/app.js`.

### Backend database modulare

`api/database.php` è ora un **facade stabile**: gli endpoint esistenti continuano a includere lo stesso file, ma le responsabilità sono separate sotto `api/database/`:

- `driver.php`: driver PDO e compatibility rewrite MySQL;
- `crypto.php`: cifratura/decrittografia dei secret;
- `schema.php`: controlli e sync dello schema/capability correnti;
- `migrations.php`: contratto di versione e upgrade SQLite/MySQL;
- `connection.php`: apertura/validazione della connessione e bootstrap;
- `metadata.php`: versioni/cataloghi applicativi;
- `smtp.php`: persistenza e provisioning SMTP;
- `ai.php`: persistenza e provisioning configurazione AI;
- `security.php`: HMAC, rate limiting e pruning dello stato di sicurezza.

Nello **snapshot storico P1**, il nuovo `meteonexa_migration_manifest()` rendeva esplicita la sequenza schema **16 → 26** e `meteonexa_current_schema_version()` era già l’unica costante runtime usata dal bootstrap per la versione allora corrente. Le migration storiche sono state tolte dalla funzione di connessione; le future revisioni DB devono essere aggiunte al modulo migration invece di far crescere nuovamente `meteonexa_db()`. La baseline SQLite e `mysql-schema.sql` restano gli snapshot completi per nuove installazioni.

### P1 finale — notification center, push e PWA

L'ultima tranche P1 estrae da `js/app.js` il dominio notifiche in `modules/esm/domains/notifications.mjs`. Il modulo possiede ora capability detection del dispositivo/browser, stato permessi, UI del Notification Center, inbox server tramite `api/push/inbox.php`, badge applicazione, notifiche meteo locali, invio tramite Service Worker/Notifications API e preferenze severe/rain. Anche la card degli avvisi ufficiali esposta nel Notification Center viene coordinata dal dominio tramite callback esplicite verso il sottosistema Alerts.

Il lifecycle PWA è stato spostato nello stesso dominio perché è parte della capacità di delivery: registrazione e update del Service Worker, `beforeinstallprompt`, stato installazione e azione di installazione non sono più implementati nel bootstrap. Anche gli event listener specifici di notification center/inbox/PWA vengono registrati da `bindNotificationEvents()`, lasciando `bindEvents()` responsabile soltanto del coordinamento tra domini.

Dopo il P1 finale `js/app.js` scende a circa **406 KB / 7.480 righe**, rispetto ai circa **598 KB / 11.303 righe** dell'inizio dell'audit. `modules/esm/domains/notifications.mjs` è un asset first-party fingerprintato e caricato con `defer` prima di `js/app.js`; non sono stati introdotti nuovi endpoint, provider, store o dipendenze runtime esterne.

**P1 completato.** Le responsabilità strutturali prioritarie individuate nell'audit sono ora separate: stato runtime/core, database, i18n/preferenze, account/auth, radar motion/controller, località/navigation e notifications/PWA. Da questo punto il percorso di manutenzione passa al **P2**, dedicato alla standardizzazione del toolchain/moduli ES, qualità statica e progressiva riduzione dei globali `window.*`, senza una riscrittura framework.

### Contratto QA P1

`qa/p1_maintainability_smoke.py` protegge il refactoring verificando: assenza del vecchio bridge frontend, presenza dei nuovi moduli fingerprintati, facade DB sottile, separazione dei sottosistemi database, manifest migration continuo fino allo schema allora corrente 26 e documentazione README aggiornata. I test preesistenti che ispezionavano direttamente `api/database.php` sono stati adattati a leggere i moduli reali senza indebolire i controlli.

## 20.1 — P2 fase 1: toolchain e confini runtime espliciti

Nello **snapshot storico di avvio P2**, la base P1 restava su versione 20.1 e schema allora corrente **26**, senza cambiare API, provider, UI o comportamento funzionale. Il primo obiettivo non è convertire in blocco l'applicazione a un framework o a ES modules, ma rimuovere le dipendenze JavaScript implicite che renderebbero rischiosa quella conversione.

### Service registry

`modules/esm/core/service-registry.mjs` è il boundary usato da `js/app.js` per risolvere i servizi `MeteoNexa*`; nella fase 1 lo stesso contratto era ancora uno script classico e dalla fase 2 è un ES module nativo. Il registry espone `get`, `require`, `publish` e una allowlist chiusa di nomi di servizio. `js/app.js` non accede più direttamente a decine di `window.MeteoNexa...`: le dipendenze sono nominate, verificabili e falliscono esplicitamente quando un servizio obbligatorio non è ancora disponibile. I globali compatibili restano temporaneamente pubblicati perché altri moduli legacy li consumano ancora; il registry è quindi un **compatibility boundary**, non un secondo container di stato.

### Runtime API tra bootstrap, Advanced e Suite

`modules/esm/core/runtime-api.mjs` definisce il contratto esplicito tra il bootstrap principale e i bundle legacy caricati dopo `js/app.js`; dalla fase 2 il contratto è esportato/importato come ES module nativo. `js/app.js` pubblica una API immutabile con accesso allo stato autorevole e agli helper condivisi necessari (`loadJSON`, `saveJSON`, loader, weather refresh, threshold, locale/formattazione, ecc.). `js/advanced.js` e i due domini principali di `js/suite.js` non dipendono più dal fatto che dichiarazioni top-level di uno script classico diventino accidentalmente proprietà globali del browser.

Questo elimina il principale ostacolo tecnico alla successiva migrazione a `type="module"`: quando un file verrà convertito a ES module, i suoi simboli top-level potranno restare module-scoped senza rompere Advanced/Suite. La conversione ESM vera e propria viene fatta in tranche successive, dopo avere esplicitato i confini, invece di affidarsi all'ordine implicito dei globali.

### Toolchain locale senza dipendenze runtime

È stato aggiunto `package.json` con una toolchain minima:

```bash
npm run check         # sintassi + static architecture/security checks
npm run build:assets  # rigenera dist/ e manifest fingerprintato
npm run build         # check + build asset
npm run qa            # suite QA completa
```

Il comando `npm run check` usa `tools/static-analysis.mjs` e non richiede pacchetti npm esterni: usa Node >= 20, esegue `node --check` su JS/MJS, vieta `eval`, `new Function` e `document.write`, verifica che `js/app.js` passi dal service registry e protegge il contratto Runtime API di Advanced/Suite. Questo mantiene il source package riproducibile anche in ambienti CI/offline; ESLint/esbuild potranno essere aggiunti in una tranche P2 successiva quando il boundary ESM sarà sufficientemente maturo.

`service-registry` e `runtime-api` restano asset first-party fingerprintati; dalla fase 2 sono pubblicati come `.mjs` e installano soltanto i compatibility global necessari ai bundle non ancora migrati. Non introducono richieste remote, cookie, storage o endpoint.

### Contratto QA P2

`qa/p2_toolchain_smoke.py` verifica package/toolchain, ordine di caricamento, fingerprinting dei nuovi boundary, assenza di bypass `window.MeteoNexa*` in `js/app.js`, uso esplicito della Runtime API in Advanced/Suite e documentazione P2. `qa/run-all.sh` esegue inoltre `tools/static-analysis.mjs` come gate di release.


## 20.1 — P2 fase 2: ES modules nativi e toolchain standard

La seconda tranche P2 avvia la conversione runtime reale senza trasformare in blocco tutti i file legacy. `modules/esm/core/service-registry.mjs` e `modules/esm/core/runtime-api.mjs` usano ora `export`/`import()` nativi e sostituiscono i precedenti script classici omonimi. I compatibility global `window.MeteoNexaServices` e `window.MeteoNexaRuntimeAPI` restano deliberatamente disponibili finché i domini rimanenti non saranno migrati, ma la loro implementazione autorevole è module-scoped.

### Bootstrap ESM e caricamento compatibile

`modules/esm/bootstrap.mjs` è caricato con `type="module"`. Legge il manifest first-party `js/asset-manifest.js`, importa i boundary ESM tramite URL fingerprintati e, dalla fase 3, installa anche l'intero core applicativo ESM prima di caricare in sequenza i domini P1 ancora classici. Solo dopo avvia `js/app.js`, `js/advanced.js`, Copilot, `js/suite.js`, Weather Intelligence e i feature controller successivi. In questo modo il passaggio a ESM non dipende più dall'ordine temporale implicito tra script `defer` e module script.

`js/app.js`, `js/advanced.js` e il date picker di `js/suite.js` sono inoltre **late-load safe**: se `DOMContentLoaded` è già avvenuto avviano il proprio bind tramite `queueMicrotask`; se il documento è ancora in parsing mantengono il listener one-shot. Questo rende il bootstrap robusto anche a futuri cambi di bundling/preload.

Il manifest resta `no-cache` ed è soltanto un indice di URL content-addressed; il codice eseguibile continua a essere servito da `dist/` con cache immutable. `.htaccess` dichiara esplicitamente `.mjs` come `text/javascript`. Anche il Service Worker include bootstrap e boundary ESM nella shell offline. La revisione della shell cache viene inoltre portata a `p2-esm-1`, così l’upgrade non riusa una shell P2 fase 1 incompatibile con il nuovo ordine di bootstrap.

### ESLint ed esbuild

La toolchain standard è ora dichiarata in `package.json` con versioni pinned:

```bash
npm run check          # gate zero-dependency: sintassi + architettura/security
npm run lint:eslint    # ESLint sui moduli ESM
npm run bundle:verify  # verifica con esbuild che il grafo ESM sia bundlabile
npm run check:full     # check + ESLint + esbuild
npm run build          # check + fingerprinting dist/
npm run qa             # suite QA completa
```

`eslint.config.mjs` applica inizialmente regole strette ai soli file sotto `modules/esm/` (`no-undef`, `no-eval`, `no-new-func`, `no-var`, `prefer-const`, `eqeqeq`, ecc.). Lintare subito tutto il legacy produrrebbe rumore e renderebbe più difficile distinguere debito storico da regressioni nuove; il perimetro ESLint verrà ampliato mentre i file passano a ESM.

`esbuild` è una **dipendenza di sviluppo soltanto**: non viene caricato dal browser e non entra nell'immagine/runtime di produzione. `tools/esbuild-verify.mjs` verifica bootstrap, boundary e core ESM come entry point bundlabili per una futura fase di consolidamento, mentre l'applicazione continua oggi a servire ESM nativi fingerprintati. La suite release continua a usare anche `tools/static-analysis.mjs`, così il pacchetto può essere verificato senza installare dipendenze npm.

### QA P2 fase 2

`qa/p2_esm_runtime_smoke.mjs` importa realmente i moduli ESM con Node e verifica allowlist del service registry, errori per servizi sconosciuti/non pronti, immutabilità e validazione del Runtime API contract. `qa/p2_toolchain_smoke.py` protegge bootstrap `type="module"`, manifest/fingerprinting, caricamento sequenziale, late-load safety, MIME `.mjs`, Service Worker e configurazione ESLint/esbuild. I vecchi `modules/core/service-registry.js` e `modules/core/runtime-api.js` sono rimossi per evitare due sorgenti della stessa logica.

## 20.1 — P2 fase 3: core applicativo in ES modules

La terza tranche P2 migra il **core applicativo P1** a moduli ES nativi senza cambiare stato, API o comportamento utente. Le implementazioni autorevoli sono ora esclusivamente:

- `modules/esm/core/store.mjs`: proiezione immutabile dello stato runtime, patch/event bus e `attachRuntimeState()`;
- `modules/esm/core/runtime-state.mjs`: factory dello stato runtime autorevole;
- `modules/esm/core/tooltips.mjs`: lifecycle tooltip UI;
- `modules/esm/core/weather-utils.mjs`: utility meteo, località, timezone e preview;
- `modules/esm/core/i18n-preferences.mjs`: traduzione DOM, lingua/tema e sincronizzazione preferenze.

I precedenti `modules/core/*.js` corrispondenti sono stati rimossi: non esistono due copie della stessa logica. Ogni modulo ESM esporta la propria API e una funzione `install*()` che pubblica temporaneamente il compatibility global (`MeteoNexaCore`, `MeteoNexaRuntimeState`, ecc.) necessario ai domini non ancora migrati. Il global è quindi un **adapter di transizione**, non più la sorgente dell'implementazione.

### Grafo di bootstrap deterministico

`modules/esm/bootstrap.mjs` è ora l'autorità sull'ordine di inizializzazione. Importa in parallelo i sette moduli core ESM fingerprintati, installa service registry/Runtime API/core e poi carica in sequenza `PRE_APP_CLASSIC_SCRIPTS` (domini, Route Weather, custom controls e Product Metrics). Solo quando i contratti pre-app sono disponibili carica il tail applicativo (`js/app.js`, Advanced, Copilot, Suite, Weather Intelligence e feature controller). `index.html` conserva soltanto i runtime di base (`i18n-runtime`, config, security), `js/asset-manifest.js` e il bootstrap `type="module"`.

Questa modifica elimina la dipendenza dall'ordine di molti `<script defer>` e impedisce ai domini classici di catturare un `MeteoNexaCore` non ancora installato. La fase 3 usava la revisione shell `p2-esm-core-2`; il P2 finale la sostituisce con `p2-esm-services-3`, che include anche domini e feature ESM.

### QA P2 fase 3

`qa/p2_core_esm_smoke.mjs` importa realmente i cinque moduli core con Node, verifica gli adapter globali, l'immutabilità delle API, la factory dello stato, la proiezione dello store e le factory weather/i18n. `qa/p2_toolchain_smoke.py`, `tools/static-analysis.mjs` e `qa/asset_contract.py` verificano inoltre che i vecchi core classic siano assenti, che il bootstrap dichiari il grafo ESM/pre-app, che `index.html` non duplichi gli script dinamici e che manifest/Service Worker includano i nuovi `.mjs` fingerprintati.

**Stato P2 fase 3:** il core e i boundary infrastrutturali sono ESM nativi; la fase finale completa la migrazione di domini/feature e rimuove i compatibility global dal percorso applicativo principale.



## 20.1 — P2 finale: domini/feature ESM e service registry interno

Il P2 è completato. Tutti i moduli applicativi sotto `modules/domains/` e `modules/features/` sono stati rimossi e sostituiti dalle implementazioni autorevoli sotto `modules/esm/domains/` e `modules/esm/features/`. Il bootstrap importa/installla i domini ESM prima di `js/app.js` e installa le feature ESM nel medesimo ordine funzionale della pipeline precedente, senza duplicare sorgenti classic.

`MeteoNexaServices` è ora un **registro interno reale**: `publish()` memorizza il servizio nel registry e non crea più automaticamente `window.MeteoNexa*`. L'eventuale esposizione legacy è separata ed esplicita tramite `exposeLegacy()`. Core, Runtime API, domini e feature vengono quindi risolti dal registry; `js/app.js`, `js/advanced.js`, `js/suite.js`, Weather Intelligence, Product Metrics e Custom Controls usano tutti il solo boundary `window.MeteoNexaServices`. I runtime foundational ancora classic (`MeteoNexaSecurity` e `MeteoNexaI18n`) restano temporaneamente first-party global perché sono caricati prima del bootstrap ESM.

I nuovi moduli domain/feature usano un adapter ESM confinato: il codice storico viene eseguito dentro un proxy locale che risolve i vecchi nomi `MeteoNexa*` dal registry e intercetta le pubblicazioni nel registry stesso. Importare un modulo `.mjs` non produce side effect globali; l'installazione avviene soltanto quando `bootstrap.mjs` chiama esplicitamente `install()`. Questo permette di mantenere invariato il comportamento mentre il codice interno viene progressivamente ripulito dai vecchi nomi senza reintrodurre dipendenze globali runtime.

Il Service Worker usa la shell revision `p2-esm-services-3` e precachea core, domini e feature ESM fingerprintati. `tools/esbuild-verify.mjs` include l'intero grafo ESM come superficie di verifica. La QA aggiunge `qa/p2_domain_esm_smoke.mjs`, che importa tutti i domini/feature con Node e verifica che espongano `install()`/`serviceNames` senza creare globali. `tools/static-analysis.mjs` impedisce inoltre a `js/app.js`, Advanced, Suite, Weather Intelligence, Product Metrics e Custom Controls di bypassare il service registry.

**Stato P2: COMPLETATO.** Il passo successivo può essere P3; non è necessaria un'altra tranche P2 per il boundary runtime.

## 20.1 — Rimozione traffic analytics

A partire dalla revisione del 19 settembre 2026 MeteoNexa non carica più il modulo di traffic analytics Plausible. Sono stati rimossi `analytics.js`, l’endpoint pubblico `api/analytics/config.php`, le variabili `METEONEXA_PLAUSIBLE_*`, la destinazione Plausible dalla CSP, la diagnostica analytics e i riferimenti nelle Privacy/Cookie Policy. Non vengono quindi più inviati pageview o parametri di acquisizione a un servizio analytics esterno. I Product Metrics first-party erano separati dal traffic analytics nella revisione precedente, ma nella revisione corrente sono stati rimossi dal runtime e non raccolgono più eventi di utilizzo.

## 20.0 — AI Weather Copilot 3 + Route/Decision orchestration

### Bootstrap/mobile hardening

Il bootstrap 20.0 usa un catalogo i18n statico locale come first-paint fallback, quindi login e guest non dipendono da cookie acknowledgement, MySQL o dalla latenza di `api/i18n.php`. Per i guest gli avvisi ufficiali, UI config, auth reconciliation e Watch My Plan sono fail-soft/background e non bloccano l’ingresso nell’app. La pagina Advanced aggiorna i provider nelle card senza mantenere aperto il loader globale; le voci di navigazione non possono ricevere lo spinner di un’operazione asincrona.


La 20.0 consolida l’assistente meteo in una **orchestrazione server-side deterministica**. `api/ai/orchestrator.php` seleziona gli strumenti necessari in base alla domanda e combina forecast corrente, canonical consensus a 6 modelli, confidence/skill locale, nowcast probabilistico, Decision Timeline, cambi previsionali materiali, contesto severe/official e Route Weather quando la richiesta riguarda uno spostamento.

Il modello linguistico **non decide il meteo**: prima viene prodotto un oggetto decisionale deterministico (`good / caution / avoid / learning`) con score, confidence, rischio dominante, finestra migliore/alternativa ed eventuale segmento critico del percorso. Solo dopo l’LLM può spiegare quel risultato. Il system prompt vieta di sostituire la decisione del motore o inventare dati, fonti, ETA o rischi.

Route Weather e Copilot condividono `api/route/weather_engine.php`; non esistono due algoritmi percorso. Le coordinate campionate sono usate dal motore MeteoNexa per calcolare il rischio, ma **non vengono incluse nel payload inviato al provider LLM**. Nel dialog dell’assistente la risposta è accompagnata da un blocco evidenza responsive con score, confidence, miglior partenza, rischio percorso e numero di fonti. L’orchestratore espone inoltre come evidenze strutturate il canonical consensus, warning ufficiali, Convective Risk, trust locale aggregato e, per i percorsi, vento/raffiche/crosswind del segmento critico; l’LLM li può spiegare ma non sostituire.

Nello **snapshot storico 20.0**, lo schema era **26 / 47 tabelle**. Il catalogo di quella release era `translation_seed_version=20.0-alignment-hardening-v3`: **4.623 chiavi × 5 lingue = 23.115 traduzioni**. Non vengono introdotti nuovi cookie, tracker o SDK analytics; Privacy e Cookie Policy espongono soltanto la release corrente.

### Verifica roadmap consolidata fino alla 20.0

La base 20.0 mantiene attivi e coperti da QA i blocchi sviluppati lungo la roadmap: Reliability Consistency (6 modelli, confidence dinamica, provider server-owned e Radar3 probation), Product Metrics privacy-first, Decision Timeline + Forecast Change, Nowcast probabilistico, Public Local Accuracy, Watch My Plan e AI Weather Copilot con Route/Decision orchestration. Explainability e Model Skill per metrica/orizzonte restano capacità di supporto attive. Le proposte **Community Ground Truth** e **Long Range Scenarios 8–15 giorni** non facevano parte della sequenza di release fino alla 20.0 e non vengono dichiarate come implementate in questo pacchetto.

### Package clean-up 20.0

A partire dalla 20.0 la cronologia release è conservata **solo in questo README**. Runtime, QA, policy, architecture/security docs, migration helpers e asset usano nomi semantici/version-independent. I vecchi gate `release_*` e i file feature nominati con la release sono stati sostituiti da contratti correnti (`model_consistency`, `product_metrics`, `decision_timeline`, `probabilistic_nowcast`, `public_accuracy`, `watch_plan`, `copilot_orchestration`). `qa/run-all.sh` esegue soltanto la suite attuale e le regressioni funzionali; non esiste più un runner storico nel pacchetto operativo.

## 19.5 — Watch My Plan

Nello **snapshot storico 19.5**, lo schema era **26 / 47 tabelle** e veniva aggiunto **Watch My Plan** come estensione decisionale account-only. Un piano contiene soltanto attività, orario, durata e riferimento (`locationKey`) a una località già sincronizzata nell’account: il record del piano non duplica coordinate, email o testo libero. Sono ammessi gli stessi 11 profili della Decision Timeline, massimo **8 piani attivi** per account, con orizzonte massimo di **14 giorni**.

La persistenza riusa `account_sync_state` con namespace `watch_plan`, quindi non viene introdotta una seconda tabella o un secondo sistema di sincronizzazione. Il worker considera solo i piani che entrano nelle successive **72 ore**, risolve server-side la località già salvata e rivaluta il piano tramite Provider Orchestrator, canonical consensus e lo stesso scoring weather-only della Decision Timeline 19.2. Un nowcast recente può contribuire solo se esiste già nel ledger ed è sufficientemente fresco; il worker non inventa un nowcast e non effettua fetch modello dal browser.

La prima valutazione crea soltanto la baseline. Una push viene generata solo per un peggioramento materiale o un recupero significativo, con cooldown minimo di **3 ore** e riuso della pipeline push esistente. Nessuna notifica viene emessa per piccole oscillazioni. L’article `#v195-watch-panel` usa lo stesso contratto `glass-panel / panel-title` degli article Intelligence, con card a due colonne su desktop e una colonna sui breakpoint tablet/mobile; il dialog usa header/body/footer e i custom controls esistenti, senza introdurre `select`, date picker o time picker HTML5 nativi.

La 19.5 non aggiunge provider, cookie, tracker, fingerprint, SDK analytics o nuove categorie di dati. Il catalogo di quella release era `translation_seed_version=19.5-watch-my-plan-v1`: **4.666 chiavi × 5 lingue = 23.330 traduzioni**.

## 19.4 — Public Local Accuracy / Trust

Nello **snapshot storico 19.4**, lo schema era **26 / 47 tabelle** e veniva resa pubblicabile, nello stesso article Accuracy esistente, una vista locale delle evidenze verificate degli ultimi 60 giorni. Non viene creato un secondo article: `#model-accuracy-panel` conserva lo stesso contratto `glass-panel / panel-title` e aggiunge un blocco interno responsive.

Le metriche pubbliche sono **fail-closed**: una metrica viene esposta solo con almeno **3 contributori distinti e 20 verifiche** nella località selezionata. Sotto soglia viene mostrato soltanto `Learning`, senza esporre il numero dei contributori. La risposta contiene esclusivamente aggregati (MAE temperatura, Precision/Recall pioggia, False Alarm Ratio temporali, Radar ETA MAE/within-tolerance) e non contiene `device_id`, account, email o record individuali.

L'endpoint `api/accuracy/public.php` è first-party, same-origin, rate-limited e non scrive dati. Riusa i ledger verificati già presenti (`model_skill_samples`, `predictive_alert_opportunities`, `radar_eta_predictions`) con finestra massima di 60 giorni. Nessun nuovo provider, cookie, localStorage/sessionStorage, fingerprint o schema DB viene introdotto. Il catalogo di quella release era `translation_seed_version=19.4-public-local-accuracy-v1`.

## 19.3 — Nowcast 4.0 probabilistico

Nello **snapshot storico 19.3**, lo schema era **26 / 47 tabelle** e veniva potenziato in-place l’article Nowcast già presente, senza creare un secondo pannello o un nuovo design system. Il backend aggiunge `api/intelligence/v193_helpers.php` e produce un payload `nowcastV4` 0–90 minuti a passo 5 minuti combinando l’evidenza già disponibile: Radar2/Radar3, lightning, satellite, osservazioni/stazioni, consensus a 6 modelli, Convective Risk 4, Severe Outlook e avvisi ufficiali. Nessun nuovo provider viene contattato dalla 19.3.

Nowcast 4.0 espone probabilità di pioggia con intervalli d’incertezza, probabilità temporalesca, probabilità di raffiche forti e, quando l’ETA supera i guardrail, una distribuzione probabilistica dell’arrivo con finestra più probabile e cumulata entro +15/+30/+45/+60 minuti. **Hail Potential resta un indice 0–100 e non viene trasformato in una falsa probabilità di grandine.** Gli avvisi ufficiali restano separati e autorevoli.

La release conserva il guardrail Radar3 introdotto nella 19.0.9: finché Radar3 è in probation/fallback, `radarAuthority=radar2-authoritative`; solo `radar3Mode=active` consente di etichettare Radar3 come sorgente ETA verificata. Le notifiche critiche e i trigger deterministici non vengono spostati sul nuovo layer probabilistico: la 19.3 lo introduce come decision-support osservabile, evitando di cambiare la semantica di sicurezza prima della calibrazione storica.

La UI riusa `#v190-nowcast-panel`, `glass-panel`, `panel-title` e il grid Intelligence già validato. Sono aggiunti soltanto componenti interni responsive per probabilità, evidenze e uncertainty range, con breakpoint 720/460 px; quindi non nasce un article graficamente diverso dagli altri. Il catalogo di quella release era `translation_seed_version=19.3-probabilistic-nowcast-v4-v1`: **4.587 chiavi × 5 lingue = 22.935 traduzioni**. Privacy Policy e Cookie Policy dichiarano che la release riusa dati/provider già esistenti e non aggiunge cookie, tracker, storage browser o nuove categorie di dati personali.

## 19.2 — Decision Timeline + Forecast Change 2.0

Nello **snapshot storico 19.2**, lo schema era **26 / 47 tabelle** e non venivano aggiunte nuove categorie di dati o provider. Introduce una Decision Timeline server-driven sulle prossime 24 ore per 11 profili (corsa, bici, moto, mare, trekking, bambini, animali, cantiere, commute, eventi e fotografia). Il punteggio è dichiaratamente **weather-only**: combina consenso pesato, soglie personali, raffiche, temperatura, rischio temporalesco, confidence e l'eventuale impatto nowcast nei primi 90 minuti. Ogni profilo espone finestra migliore, alternativa, rischio dominante e confidence senza fingere informazioni non meteorologiche.

Forecast Change 2.0 riusa `forecast_run_snapshots` e non crea un secondo storico. La UI mostra solo cambi decisionali: cambio di tipo evento, spostamento di almeno 30 minuti, variazione della probabilità di pioggia di almeno 15 punti o variazione dell'accordo modelli di almeno 15 punti. Quando il nowcast verificabile supera i guardrail (`impact >= 60`, `confidence >= 55`) può comparire una conferma radar/nowcast con ETA. Le oscillazioni minori vengono filtrate e `notifyRecommended` viene esposto soltanto per un cambiamento recente con impatto sufficiente.

L'article **Decision Timeline** usa lo stesso contratto grafico `glass-panel / panel-title` degli article Intelligence esistenti e breakpoint dedicati a 720/460 px. Il pannello Forecast Change esistente viene aggiornato in-place, evitando duplicazioni visive. I nuovi asset sono modulari (`api/intelligence/v192_helpers.php`, `modules/esm/features/intelligence-v192.mjs`, `intelligence-v192.css`). Privacy Policy e Cookie Policy dichiarano esplicitamente che la 19.2 riusa dati e snapshot già trattati e non introduce cookie, tracker, storage analytics o fornitori esterni. Il catalogo di quella release era `translation_seed_version=19.2-decision-timeline-forecast-change-v2-v1`: **4.561 chiavi × 5 lingue = 22.805 traduzioni**.

## 19.1 — Privacy-first Product Metrics (storico, rimosso dal runtime corrente)

La 19.1 parte dalla 19.0.9 Reliability Consistency già verificata e introduce **Product Metrics first-party**, senza cambiare il motore meteorologico. In quello **snapshot storico 19.1** il contratto dati passava a **schema 26 / 47 tabelle** con `product_metrics_daily`: non esiste una riga per visita, utente, account, dispositivo o sessione. Il server conserva soltanto `metric_date + event_name + event_count + updated_at`, quindi lo storico è aggregato prima della persistenza.

La whitelist chiusa degli eventi è: `page_home`, `page_radar`, `page_intelligence`, `search_location`, `open_alert`, `open_explainability`, `use_route`, `use_ai`, `enable_notifications`, `bug_report`, `install_pwa`. Non vengono inviati al Product Metrics endpoint email, account/device ID, coordinate, nome località, query di ricerca, testo AI, preferenze o payload meteorologici. Il client mantiene i conteggi solo in memoria e usa `fetch(..., credentials:'omit')` verso `api/metrics/product.php`; non vengono introdotti cookie analytics, `localStorage`/`sessionStorage` analytics, fingerprint o SDK esterni.

L'endpoint è same-origin, accetta solo JSON, applica una whitelist server-side e limiti globali, rifiuta eventi/campi non previsti e conserva al massimo **180 giorni** di aggregati giornalieri. `METEONEXA_PRODUCT_METRICS_ENABLED=0` è il kill switch operativo; la retention può essere ridotta ma non estesa oltre 180 giorni senza modificare il codice/policy della release.

Diagnostics aggiunge una card **Product Metrics** coerente con il layout responsive esistente. Il riepilogo amministrativo espone soltanto totali per evento e per giorno degli ultimi 30 giorni, mai record per persona. `runtime_metrics` resta separata e continua a servire esclusivamente osservabilità tecnica.

Privacy Policy e Cookie Policy sono aggiornate alla 19.1 e distinguono Product Metrics dai normali log tecnici di rete/sicurezza. Non viene aggiunto alcun processor analytics di terze parti. Il catalogo è `translation_seed_version=19.1-product-metrics-v1`: **4.515 chiavi × 5 lingue = 22.575 traduzioni**.

I gate 19.1 verificano schema/parity SQLite-MySQL, whitelist eventi, `credentials:'omit'`, assenza di storage persistente nel client analytics, policy/i18n, card Diagnostics, retention e assenza di payload sensibili. La correzione cosmetica della versione Diagnostics (`19.0.8` residuo) è inclusa nello stesso contratto.

## 19.0.9 — Reliability Consistency

La 19.0.9 mantiene **schema 25 / 46 tabelle** e concentra la release sulla coerenza delle evidenze meteorologiche. Il conteggio dei modelli non è più fissato a cinque nei percorsi Home/Trust: il backend deriva `modelsExpected` dalla lista server dei provider e la Suite calcola i modelli mancanti da `MODEL_DEFINITIONS.length`, eliminando il caso in cui sei modelli potessero aumentare artificialmente la confidence.

Advanced Models non interroga più direttamente i sei endpoint Open-Meteo dal browser. Il browser usa `api/weather/fusion.php`, che passa dal Provider Orchestrator server, riusa cache/stale handling e restituisce evidenza normalizzata e limitata. In questo modo Home e Advanced condividono la stessa sorgente server e le coordinate selezionate non vengono più inviate dal browser ai sei endpoint modello.

Radar 3.0 entra inoltre in **probation** fino ad almeno 20 verifiche comparabili sia per Radar2 sia per Radar3. Durante la probation Radar3 continua a essere calcolato e verificato, ma Radar2 resta autorevole per ETA e decisioni; Radar3 diventa `verified_active` solo se lo storico non mostra regressioni MAE o within-tolerance.

La release aggiunge gate QA specifici per il conteggio modelli, la sorgente server dei modelli e la probation Radar3. Non introduce analytics, nuovi cookie, nuove categorie di dati personali o modifiche allo schema DB.

## 19.0.8 — Fingerprint asset contract hotfix

La 19.0.8 mantiene **schema 25 / 46 tabelle** e corregge un problema di packaging della 19.0.7: `index.html` poteva conservare hash precedenti di `js/app.js`, `js/suite.js` e `js/weather-intelligence.js` mentre `asset-manifest.json` e `dist/` contenevano hash più recenti. In produzione le URL mancanti venivano quindi risolte sulla pagina HTML di errore (`text/html`); con `X-Content-Type-Options: nosniff` il browser bloccava correttamente quei file JavaScript e `js/advanced.js` poteva poi generare il secondario `state is not defined`.

Il builder `tools/fingerprint_assets.py` ora normalizza anche **riferimenti fingerprintati obsoleti non presenti nel manifest corrente**, quindi può riparare un work tree parzialmente ricostruito. Il nuovo gate `qa/release_19008_asset_contract.py` verifica sorgente → SHA-256 → nome fingerprint → contenuto `dist/` → manifest → riferimenti HTML/PHP. Un asset referenziato ma assente blocca la release. `js/advanced.js` usa inoltre un accesso resiliente allo stato legacy: se il bundle principale non è disponibile non produce più una cascata `ReferenceError`, lasciando visibile il guasto originario.

Privacy/Cookie/Security restano invariati nel trattamento: nessun nuovo cookie, tracker, analytics, provider o categoria di dati. `translation_seed_version=19.0.8-asset-contract-hotfix-v1`: **4.498 chiavi × 5 lingue = 22.490 traduzioni**.

## 19.0.7 — Intelligence history clarity, AIFS client parity e screen capture bug-report

La 19.0.7 mantiene **schema 25 / 46 tabelle** e non cambia il contratto Radar3 active/fallback della 19.0.6. Corregge invece tre problemi emersi nei primi giorni di produzione.

### Consensus davvero a 6 modelli

Il backend usava già sei famiglie (`ECMWF IFS`, `ECMWF AIFS AI`, `ICON`, `GFS`, `Météo-France`, `UKMO`), ma due percorsi legacy del browser e il renderer dei voti restavano a cinque. AIFS è ora presente anche in `js/config.js`, preview/client fetch, Suite e lista voti. Il kicker i18n è **“Consensus 6 modelli”**. Le etichette modello passano dalle chiavi `provider.*` invece di copy UI literal.

### Demo guest ≠ storico persistente

`api/demo/intelligence.php` resta deliberatamente stateless per account/history: non persiste snapshot personali dei run e non costruisce `model_skill_samples`. La UI non deve quindi dire che il worker sta accumulando campioni o che è stato salvato un primo snapshot quando la request è `mode=guest-demo`. La release aggiunge copy esplicito per la demo e forza tramite migration `guest_visible=0` per le card account-history (`skill`, `change`, `celltracking`, `locations`) anche se un vecchio DB produzione conteneva override precedenti.

Per gli utenti autenticati `api/intelligence/summary.php` esegue inoltre un **calibration touch opportunistico e network-free** usando i sei modelli e le osservazioni già caricati dalla request: verifica i campioni maturi soltanto in presenza di osservazioni indipendenti e accoda gli orizzonti successivi. Il cron/worker resta il percorso preferito, ma l'assenza temporanea di scheduler non congela completamente lo storico. Se manca una fonte osservativa indipendente la UI lo dichiara, invece di fingere avanzamento.

### Bug report: registra schermo, mai fotocamera

`#bug-report` usa esclusivamente `navigator.mediaDevices.getDisplayMedia()`. Desktop/browser compatibili aprono il chooser di **schermo / finestra / scheda**; quando supportato l'utente può condividere anche audio. La funzione non usa `getUserMedia()` e non apre la fotocamera come fallback. Sui browser/mobile che non espongono la Screen Capture API viene mostrata un'istruzione localizzata per usare il registratore schermo del sistema e allegare il video.

Privacy e Cookie Policy sono riallineate a questo comportamento. La 19.0.7 **non introduce analytics**: prima di aggiungere un provider di statistiche va scelto esplicitamente e documentato come servizio esterno.

### Release contract

`translation_seed_version=19.0.7-intelligence-guest-screen-capture-v1`: **4.495 chiavi × 5 lingue = 22.475 traduzioni**. Le correzioni di stringhe esistenti sono hash-gated (`translation-corrections-19.0.7.json`) per non sovrascrivere personalizzazioni DB. `README.md`, `SECURITY.md`, `ARCHITECTURE.md`, Privacy Policy e Cookie Policy sono parte del gate della release.

## 19.0.6 — Production hardening + Radar 3.0 active con fallback protetto

La 19.0.6 mantiene **schema 25 / 46 tabelle** e promuove `METEONEXA_RADAR3_MODE=active` come default di produzione. La promozione non è un bypass dei guardrail: Radar 3.0 diventa autorevole solo quando il radar corrente ha al massimo 15 minuti, esiste almeno una traccia con almeno 2 frame, `trackConfidence >= 55`, energia positiva e il cono 15/30/45/60/90 è completo. Se una di queste condizioni manca, oppure almeno 20 verifiche comparabili per algoritmo mostrano Radar 3.0 peggiore di Radar 2.x oltre i limiti MAE/tolleranza, il runtime entra in `active-fallback-v2` e mantiene Radar 2.x autorevole.

La release elimina inoltre l'ultima verifica ETA circolare rimasta nel vecchio helper Nowcast 2.0: `etaVerification` legge soltanto `radar_eta_predictions` chiuse dal verifier con `verification_method=independent-*`; il solo aumento di `impactProbability` non viene più trattato come ground truth. Trust Scoreboard e ledger verificati restano la sorgente per MAE, mediana, P90 e percentuale entro tolleranza.

### Audit i18n completo

Le superfici HTML principali non contengono copy utente non tradotto; JavaScript/Service Worker non assegnano messaggi UI tramite literal prose. Brand, simboli, unità (`°C`, `km/h`, `mm`), codici tecnici (`DB`, `AI`, `HTTPS`, `SMTP`) e nomi propri dei provider non sono copy traducibile. Sono state corrette anche etichette generiche rimaste in inglese nelle lingue ES/FR/DE (Calibration Worker, servizi, destinazione/distanza, satellite, trend stabile, Intelligence, Smart Alerts, Weather Confidence e Personal Weather Twin). Il catalogo di quella release era `19.0.6-production-radar3-active-i18n-security-v1`: **4.486 chiavi × 5 lingue = 22.430 traduzioni**. La migration usa hash SHA-256 dei valori package precedenti per aggiornare soltanto le copie release-owned, preservando eventuali personalizzazioni DB.

### Privacy / Cookie / Security

Privacy e Cookie Policy dichiarano esplicitamente Radar 3.0 active con fallback, verifica ETA indipendente, retention fino a 180 giorni per i campioni tecnici verificati e fino a 30 giorni per le metriche runtime. La 19.0.6 non introduce cookie, tracker, analytics di terze parti, identificatori browser o nuove categorie di dati personali.

L'hardening browser aggiunge `frame-src 'none'`, `Cross-Origin-Opener-Policy`, `X-Permitted-Cross-Domain-Policies`, `Origin-Agent-Cluster`, `Permissions-Policy` restrittiva sulle superfici amministrative, `no-store` e `X-Robots-Tag` per QA/Diagnostics/error pages. Le API JSON restano `default-src 'none'`. Il CSP principale continua a consentire `style-src-attr 'unsafe-inline'` esclusivamente perché la UI usa CSS custom properties dinamiche negli attributi style; `script-src-attr` resta `none` e non sono consentiti script inline.

### Gate produzione

`qa/release_19006_production_audit.py` verifica release contract, i18n/hardcoded, policy, cookie inventory, security headers, Radar3 active/fallback, ETA ground truth indipendente, DB MySQL/SQLite e assenza di secret/runtime artifacts. Il Weather Replay include anche il production gate Radar3.

## 19.0.5 — QA/Diagnostics white-page fix + browser CSP boundary

La 19.0.5 mantiene **schema 25 / 46 tabelle** e non modifica gli algoritmi meteorologici della 19.0.4. Corregge la causa reale della pagina bianca su `/qa/` e `/diagnostics/`: entrambe le pagine includono `api/bootstrap.php`, che applica intenzionalmente alle API JSON una Content Security Policy `default-src 'none'`. La 19.0.4 non sostituiva quella policy prima di renderizzare le due superfici HTML, quindi il browser bloccava CSS, JavaScript e runtime i18n. Poiché il contenuto principale parte `hidden` e viene sbloccato dal JavaScript, il risultato visivo era una pagina completamente bianca.

`api/error_page.php` espone ora `meteonexa_browser_page_security_headers()`. QA e Diagnostics la chiamano soltanto dopo il gate server-side di sessione/amministratore e prima dell'HTML; la pagina errore la usa anche nei percorsi 401/403/503. Le API JSON continuano invece a mantenere il CSP più restrittivo `default-src 'none'`. Il CSP browser consente esclusivamente risorse same-origin necessarie (`script/style/connect/worker/manifest`), immagini/font self/data e mantiene `object-src 'none'`, `frame-ancestors 'none'`, `form-action 'self'`.

L’autorizzazione QA/Diagnostics è deployment-owned e verificata server-side; le identità privilegiate non sono più incluse nel package. La release aggiunge un regression test HTTP che avvia PHP localmente e verifica che `/qa/` e `/diagnostics/`, anche in un percorso di errore controllato, restituiscano HTML non vuoto e un CSP compatibile con gli asset browser invece del CSP API-only.

`README.md`, `SECURITY.md`, `ARCHITECTURE.md`, Privacy Policy e Cookie Policy sono riallineati. `translation_seed_version=19.0.5-browser-csp-qa-v1`; catalogo **4.480 chiavi × 5 lingue = 22.400 traduzioni**. Non vengono aggiunti cookie, tracker, nuovi dati personali o nuove finalità.

## 19.0.4 — semantic Intelligence cleanup + i18n legacy migration

La 19.0.4 mantiene **schema 25 / 46 tabelle** e non modifica gli algoritmi meteorologici della 19.0.3. È una release di pulizia strutturale: identificatori UI, chiavi i18n, action QA/Diagnostics, helper PHP/JS e nomi dei test legati a una vecchia numerazione Intelligence sono stati sostituiti con nomi semantici (`intelq`, `quality`, `quality_helpers.php`). La UI continua a esporre **Verified Trust** e il consensus a 6 modelli.

La migration `meteonexa_sync_cleanup_19004()` conserva le traduzioni esistenti rinominando le chiavi legacy sul MySQL/SQLite già installato e rimuove le vecchie chiavi solo dopo aver copiato il relativo valore. Il package non contiene più copy user-facing, commenti di release o identificatori runtime con la vecchia etichetta Intelligence. Il gate `qa/release_19004_legacy_cleanup_smoke.py` impedisce che tali token ricompaiano.

`README.md`, `SECURITY.md`, `ARCHITECTURE.md`, Privacy Policy, Cookie Policy, baseline SQLite, `mysql-schema.sql` e catalogo i18n sono allineati a `translation_seed_version=19.0.4-legacy-intelligence-cleanup-v1`. Catalogo: **4.477 chiavi × 5 lingue = 22.385 traduzioni**. Non vengono introdotti nuovi cookie, tracker, dati personali o finalità.

## 19.0.3 — QA/Diagnostics UI + runtime diagnostics hardening

La 19.0.3 mantiene **schema 25 / 46 tabelle** e non modifica gli algoritmi meteorologici. Corregge le due superfici amministrative `/qa/` e `/diagnostics/`: dopo l’autorizzazione entrambe impostano esplicitamente `Content-Type: text/html; charset=utf-8`, mantenendo `X-Content-Type-Options: nosniff`; QA usa ora lo stesso design system responsive di Diagnostics e il bootstrap loader viene rimosso soltanto dopo i18n + sessione autorizzata, senza restare nel documento a pagina pronta.

Il runtime diagnostic contract è riallineato alla release reale: schema atteso **25**, consensus atteso **6 modelli**, icona `6×`, Quality Lab rinominato **Verified Trust** nelle cinque lingue e nessun riferimento user-facing a etichette legacy del Quality Lab. Push non sottoscritto, token MeteoAlarm EDR assente e secret HTTP del Calibration Worker assente sono classificati come **warning opzionali**, non come failure del sistema. Il pulsante “test sicuri” esegue ogni controllo isolatamente: un singolo endpoint degradato non cancella gli esiti già ottenuti e viene riportato con dettaglio; il Trust Scoreboard restituisce un errore diagnostico controllato invece di un generico `INTERNAL_ERROR`.

Policy e catalogo sono allineati a `translation_seed_version=19.0.3-qa-diagnostics-runtime-v1`, **4.474 chiavi × 5 lingue = 22.370 traduzioni**; Privacy/Cookie dichiarano che questa patch non introduce nuovi dati personali, cookie, tracker o finalità.

## 19.0.2 — canonical origin, QA/Diagnostics e release-health hardening

La 19.0.2 mantiene **schema 25 / 46 tabelle** e non cambia gli algoritmi meteorologici della 19.0.1. Corregge invece il boundary HTTP emerso in produzione tra `meteonexa.com` e `www.meteonexa.com`: `assert_same_origin()` confronta ora l'`Origin` del browser con l'autorità esterna della **richiesta corrente**, mentre `METEONEXA_BASE_URL` resta il riferimento canonico/fallback e non viene più usato per rifiutare una richiesta realmente same-origin su un alias del medesimo deployment. Una richiesta `evil.example → meteonexa.com` continua a essere rifiutata; non sono introdotte wildcard CORS.

La canonicalizzazione corrente usa `https://www.meteonexa.com/`: le navigazioni HTML GET/HEAD sull’apex `https://meteonexa.com/` ricevono 308 verso `www`, mentre API e Service Worker non vengono forzati cross-origin per preservare bootstrap/aggiornamento di client preesistenti.

Nelle release precedenti era presente un amministratore package esplicito per QA/Diagnostics; la configurazione corrente non include più identità privilegiate nel sorgente. La 19.0.2 aggiunge anche `meteonexa_sync_qa_admins_19002()` con un marker separato per riallineare l'HMAC alla secret effettiva del deployment; l'autorizzazione runtime continua comunque a verificare direttamente la allowlist package, quindi non dipende dall'HMAC DB per evitare lock-out.

Sono stati corretti due drift correnti: `install/index.php` ora provisiona **19.0.2 / schema 25** e `api/system/status.php` considera healthy lo **schema 25**. I riferimenti 18.x/19.0.1 nelle migration storiche e nel changelog restano intenzionalmente immutati. Catalogo: `translation_seed_version=19.0.2-origin-canonical-health-v1`, **4.470 chiavi × 5 lingue = 22.350 traduzioni**.

## 19.0.1 — Verified Contract audit + QA/Diagnostics access repair

La 19.0.1 non cambia lo schema meteorologico della 19.0.0: **schema 25 / 46 tabelle**. È una release di verifica e hardening. Tutti i blocchi annunciati con la 19.0.0 sono ricontrollati nel codice e coperti dal gate `qa/release_19001_verified_contract.py`: ground truth ETA indipendente, TP/FP/FN/TN, retention, Freshness 2.0, AIFS nel legacy Accuracy, request tracing, Trust Scoreboard 2.0, Route/Twin verification, Provider Orchestrator, Radar Object Tracking 3.0 shadow, Convective calibration + Hail Potential, Service Worker server-authoritative, Weather Replay e MySQL 8.4 CI.

### Fix 403 QA/Diagnostics

Nelle release precedenti era presente un amministratore esplicito del package; la configurazione corrente lo ha rimosso. La 19.0.0 poteva però restare bloccata da un vecchio marker one-shot `qa_admin_provision_18_10_3=done` se il relativo HMAC mancava o non corrispondeva più alla secret corrente. La 19.0.1 elimina questa dipendenza: `api/diagnostics_access.php` nella configurazione corrente autorizza solo la allowlist deployment-owned `METEONEXA_QA_ADMIN_EMAILS` e gli HMAC DB già provisionati; SMTP non è una sorgente di autorizzazione. La nuova migration `meteonexa_sync_qa_admins_19001()` usa un marker separato e rigenera/merge l'HMAC con la `.app-secret` corrente.

L'email package è presente nel codice di configurazione, non nel seed DB; `mysql-schema.sql` e la baseline SQLite restano privi di email amministrative in chiaro e di HMAC precalcolati non portabili.

### i18n, policy e documentazione

Il catalogo di quella release era `translation_seed_version=19.0.1-verified-contract-qa-access-v1` con **4.467 chiavi × 5 lingue = 22.335 traduzioni**. `privacy.html`, `cookie-policy.html`, `README.md`, `SECURITY.md` e `ARCHITECTURE.md` sono allineati alla 19.0.1. Il gate anti-hardcode verifica le superfici HTML principali e i moduli UI 19.x; brand, unità meteorologiche, codici tecnici e nomi propri dei provider non vengono trattati come copy traducibile.

## 19.0.0 — Verified Trust, Radar Object Tracking 3.0 e promozione senza regressioni

La 19.0.0 incorpora in un solo pacchetto il percorso previsto per 18.11.1, 18.12 e 19.0. Il principio operativo è **shadow → verifica → confronto → promozione**: un algoritmo nuovo non sostituisce quello corrente solo perché è più recente. Radar 3.0 resta `shadow` per default (`METEONEXA_RADAR3_MODE=shadow`); il motore 2.x rimane autorevole finché il Trust Scoreboard non dimostra un miglioramento su campioni osservati.

### Ground truth indipendente e Trust Scoreboard 2.0

La verifica ETA radar non usa più `fusion` o la confidence della stessa traiettoria come prova del proprio successo. `radar_eta_predictions` registra algoritmo e ground truth; una previsione viene verificata soltanto tramite osservazione radar successiva sul punto oppure evidenza indipendente qualificata. In assenza di prova sufficiente il campione diventa `expired_unverified`, non un falso errore. Il Trust Scoreboard 2.0 confronta Radar 2.x/Radar 3.0 con MAE, mediana, P90 e percentuale entro tolleranza e include sample size/periodo.

Gli alert storm/wind usano anche `predictive_alert_opportunities`: finestre orarie vengono registrate anche quando MeteoNexa **non** prevede l'evento, consentendo `TP / FP / FN / TN`, precision, recall/POD, false alarm ratio, specificity e Brier. I bucket senza osservazioni indipendenti restano `insufficient_evidence` e non vengono forzati in TN.

### Radar Object Tracking 3.0 in shadow mode

`meteonexa_radar_object_tracks_v3()` traccia oggetti radar su più frame usando posizione prevista, area, energia e picco, conserva ID deterministici, velocità, accelerazione, crescita/decadimento, parent/merge relationships e coni 15/30/45/60/90 minuti. Split/merge non vengono più compressi prematuramente in una singola cella. La UI indica esplicitamente se Radar 3.0 è in verifica shadow o attivo.

### Convective Risk calibrato + Hail Potential

Convective Risk evolve alla pipeline v4 mantenendo compatibilità API con `convectiveRiskV3`: il raw score CAPE/CIN/shear/freezing/convective precipitation viene confrontato con lo storico verificato locale e progressivamente calibrato. Finché i campioni non sono sufficienti resta in modalità `raw-learning`/`shrunk-learning`. `Hail Potential` è una diagnostica separata e conservativa basata su CAPE, shear, freezing level, lightning trend, crescita Radar 3.0 e accordo ensemble: **non è un'allerta ufficiale né una previsione deterministica di grandine**.

### Provider Orchestrator e Freshness 2.0

Le sei famiglie modello (IFS, AIFS, ICON, GFS, Météo-France, UKMO) possono essere richieste in parallelo con `curl_multi`, budget globale e timeout per provider. Un provider lento non blocca tutta Intelligence: il backend restituisce il risultato parziale e usa cache stale solo quando dichiarata. La freshness separa fetch/cache age da model-run age; quando l'upstream non espone un cycle timestamp uniforme il run viene esplicitamente marcato **stimato**, evitando di presentare l'ora di download come ora del modello.

Variabili principali: `METEONEXA_PROVIDER_PARALLEL`, `METEONEXA_PROVIDER_BUDGET_SECONDS`, `METEONEXA_PROVIDER_TIMEOUT_SECONDS`, `METEONEXA_RADAR3_MODE`, `METEONEXA_VERIFICATION_RETENTION_DAYS`, `METEONEXA_RUNTIME_METRICS_RETENTION_DAYS`, `METEONEXA_MINIMUM_TRUST_SAMPLES`.

### Retention e observability correlata

`radar_eta_predictions`, `decision_verification_samples`, `predictive_alert_verifications`, `predictive_alert_opportunities` e `runtime_metrics` hanno retention temporale e limiti massimi globali. Il pruning è throttled e non avviene a ogni request. Ogni ciclo backend riceve un `requestId` server-generated propagato anche in `X-Request-ID`; le metriche runtime possono usare lo stesso trace ID senza includere email, token, secret, password, coordinate precise o contenuto delle conversazioni AI.

### Un solo Alert Engine per gli utenti autenticati

Il Service Worker 19.0 non ricalcola in parallelo gli alert per le configurazioni autenticate correnti: con `serverAuthoritative=true` legge esclusivamente notifiche già decise dal motore Smart Alert server-side (`api/push/pending.php`). Il vecchio calcolo locale Open-Meteo rimane solo come fallback di compatibilità per configurazioni precedenti che non dichiarano il server autorevole. In questo modo app e push non hanno due motori meteorologici indipendenti.

### Weather Replay e release gates

`qa/weather_replay_1900_smoke.php` + `qa/fixtures/weather-replay-1900.json` introducono replay deterministici offline per object tracking, metriche TP/FP/FN/TN, ground truth radar indipendente e calibrazione convettiva. Ogni evoluzione del motore può quindi essere valutata prima della promozione. Il gate 19.0 verifica inoltre schema/seed/versione MySQL↔SQLite, policy, i18n, shadow mode, retention, AIFS legacy accuracy alignment, provider orchestrator e Service Worker server-authoritative.

### DB / i18n / policy

Schema **25**, **46 tabelle** in baseline/MySQL. Nuova tabella `predictive_alert_opportunities`; `radar_eta_predictions` aggiunge algoritmo e provenance del ground truth; `runtime_metrics` aggiunge `trace_id` e `duration_ms`. `translation_seed_version=19.0.0-trust-radar3-v1`. Il catalogo contiene **4.464 chiavi × 5 lingue = 22.320 traduzioni**. Privacy e Cookie Policy descrivono retention, verifica indipendente, osservabilità privacy-safe e confermano l'assenza di nuovi cookie pubblicitari/profilanti.

## 18.11.0 — Verified Precision, Convective Risk 3.0 e Trust Scoreboard

Questa release sposta l'obiettivo da precisione *percepita* a precisione **misurata e verificabile**. Le nuove funzioni non aumentano la confidence quando mancano campioni: espongono stato di apprendimento, sample size e metriche solo quando esistono osservazioni utilizzabili.

### Radar Skill / ETA verification

Ogni ETA radar pubblicabile viene registrata in `radar_eta_predictions` con istante previsto, tolleranza e confidence. Quando l'impatto radar viene poi osservato, la previsione viene chiusa con errore firmato, errore assoluto e percentuale entro tolleranza. La UI mostra MAE ETA e numero di verifiche soltanto dopo campioni reali; le predizioni non verificate scadono come `missed`.

### Convective Risk 3.0

`api/intelligence/convective_v3.php` aggiunge CAPE, CIN, shear 850–500 hPa, freezing level, showers/precipitazione convettiva e accordo multi-modello/ensemble. Il risultato è una diagnostica 0–72 h (`low/elevated/moderate/high`) che **non è un'allerta ufficiale** e non usa l'LLM. Il Severe Outlook mantiene il gate multi-modello e riceve il contesto convettivo come evidenza aggiuntiva, senza promuovere automaticamente severità.

### Nowcast multi-cell

Il radar segmenta più nuclei con connected components, associa i centroidi fra frame consecutivi e restituisce per ciascuna cella crescita/decadimento, direzione, vettore di movimento, candidato split/merge e cono di traiettoria fino a 90 minuti. La card Intelligence espone il numero di celle e un riepilogo delle prime celle, senza fingere traiettorie quando l'eco è insufficiente.

### Freshness visibile

Accanto alla Confidence Timeline vengono mostrati età radar, età AIFS e numero di modelli freschi disponibili (`x/6`). Questo rende la confidence spiegabile: l'utente vede immediatamente *quanto sono recenti* le evidenze che la sostengono.

### Accuracy / Trust Scoreboard

La pagina privata `/qa/` contiene il nuovo test **Accuracy / Trust Scoreboard**, alimentato da `api/diagnostics/trust-scoreboard.php`. Le metriche includono MAE temperatura, Brier precipitazione, Brier temporali, MAE ETA radar, quota ETA entro tolleranza, falsi positivi degli alert previsionali e conteggi Route/Twin verificati. Il pannello resta learning finché i campioni sono insufficienti.

### Route Weather / Personal Weather Twin verification

Le finestre consigliate vengono registrate in `decision_verification_samples`. Route Weather 3.1 salva finestra, risk score e campioni del percorso; Personal Weather Twin registra la migliore finestra per attività. Le osservazioni indipendenti successive chiudono i campioni verificabili, permettendo di misurare nel tempo se i consigli erano realmente utili.

### Observability

Provider stale/error, radar con frame insufficienti/segnale debole/errore di analisi, errori Route e failure AI producono metriche runtime. Le metriche DB (`runtime_metrics`) sono affiancate da un ring log tecnico server-side `runtime-observability.ndjson`, limitato dimensionalmente, per distinguere errore meteorologico da outage/timeout/provider fallback. Nessun payload contiene password o token.

### DB, privacy e release contract

Schema **24**: nuove tabelle `radar_eta_predictions`, `decision_verification_samples`, `predictive_alert_verifications`, `runtime_metrics`. `mysql-schema.sql`, baseline SQLite e migration runtime sono allineati. `translation_seed_version=18.11.0-verified-precision-v1`. Il catalogo contiene **4.451 chiavi × 5 lingue = 22.255 traduzioni**. Privacy/Cookie descrivono esplicitamente i campioni tecnici pseudonimizzati usati per verifica e confermano l'assenza di nuovi cookie pubblicitari/profilanti.

## 18.10.6 — Radar predittivo leggibile, guest boundary e responsive Alert Center

La 18.10.6 è una release di **affidabilità percepita + correttezza dei fallback**. Non cambia lo schema DB, che resta **23**.

### Radar predittivo: niente più ETA “null” o stime vecchie

Il rendering del radar predittivo non usa più `Number(null)` come se fosse `0`: ETA e uscita vengono accettati solo quando il valore originale esiste ed è realmente numerico. Lo stesso guard è riusato nei punti in cui l'ETA entra in Panoramica, Trust Brief, proactive AI context e trigger locali, così un valore assente non può diventare “adesso” o “0 min”.

La previsione ottica non mostra più una stima salvata da una sessione/località precedente mentre sta ancora acquisendo i frame correnti. Servono **almeno due fotogrammi radar recenti della sessione attuale**; prima di quel momento la card dichiara esplicitamente lo stato di acquisizione e non mostra ETA, direzione o percentuale. Se i frame esistono ma l'eco è troppo debole per una traiettoria robusta, la UI mostra “segnale radar insufficiente” e lascia le metriche non disponibili invece di inventare una direzione con velocità 0 km/h.

La vecchia fila di barre poco parlante è sostituita da una timeline a step di 15 minuti: **Adesso / +15 / +30 / … / +90**, con categorie localizzate `Asciutto`, `Segnale debole`, `Pioggia probabile`, `Nucleo intenso`. Queste categorie descrivono l'intensità relativa del segnale radar usato dall'algoritmo, non millimetri/ora certificati.

### Centro notifiche: `alert-day-head` realmente responsive

Le card giornaliere usano ora un header a griglia con contenimento del badge, wrapping controllato e fallback a riga singola/stack quando la card diventa stretta. Nel dialog notifiche sotto i 600 px stato e giorno vanno su righe separate, evitando il taglio di etichette lunghe come “Domenica / Da seguire”.

### Ospite: mostrare solo ciò che può davvero usare

Il boundary UI per gli ospiti è centralizzato in `applyUiVisibility()`: tutti i nodi `data-auth-only` vengono nascosti appena la sessione viene riconciliata, con un fail-safe CSS in `guest-mode`. Le sezioni 18.10.x che richiedono storico/account (`Nowcast 2.0` autenticato, `Confidence 2.0` verificata, Personal Weather Twin e stato avanzato modelli) non vengono più mostrate come card vuote agli ospiti. Anche i pulsanti “spiega con AI” e configurazione Smart Alert vengono nascosti.

Restano invece disponibili le funzioni pubbliche che non richiedono account: Panoramica, radar, forecast, avvisi ufficiali, consensus/esplainability pubblica e la **modalità Intelligence demo account-free**. Il demo endpoint arrotonda le coordinate, non usa device/account id, non persiste lo snapshot personale e non chiama l'LLM. In altre parole: se una funzione privata non può essere eseguita non viene pubblicizzata; se esiste una variante guest sicura viene presentata esplicitamente come demo.

### Analisi tecnica dell'affidabilità

**Punti forti attuali**

- warning ufficiali separati dalle previsioni MeteoNexa, con point-in-polygon EDR, validità temporale, gestione cache stale e fallback regionale non promosso a warning puntuale;
- Severe Outlook 2.0 deterministico su sei famiglie modello, con minimo 3 modelli freschi e soglia di accordo;
- Skill 2.0 con decay e shrinkage invece di assegnare fiducia automatica a modelli nuovi;
- Confidence Timeline, Forecast Change, radar/cell tracking, fusion con fulmini/satellite e verifica rolling ETA;
- Copilot tool-based: l'LLM spiega output strutturati e non decide severità/ETA;
- separazione guest/account e provider freshness già esplicita nel backend.

**Rischi residui da considerare prima di usare la parola “previsione affidabile” come promessa forte**

1. Il movimento radar client-side resta una stima da correlazione fra immagini: crescita/decadimento rapido, clutter, eco orografici o celle che nascono sul posto possono ridurre la qualità. La confidence va quindi calibrata con errori ETA realmente osservati, non soltanto con la correlazione del frame.
2. Il Severe Outlook temporali parte soprattutto da consenso dei weather code e fulmini di supporto. Per fare un salto di qualità servono anche indicatori convettivi indipendenti: CAPE/CIN, shear, freezing level, precipitazione convettiva, raffiche convettive e probabilità ensemble.
3. Skill 2.0 è affidabile solo dove esiste storico sufficiente per località/modello/fenomeno/lead-time. Con pochi campioni il prodotto deve continuare a mostrare “in apprendimento”, evitando score troppo precisi.
4. I provider esterni possono essere freschi, stale o parzialmente degradati. L'età della sorgente e il motivo del downgrade dovrebbero diventare ancora più visibili all'utente finale.
5. Route Weather 3.0 e Personal Weather Twin hanno bisogno di metriche di verifica proprie: errore sul punto critico, falsi allarmi, finestre consigliate poi rivelatesi buone/cattive.
6. La robustezza runtime beneficia di osservabilità privacy-safe: errori JS/API, timeout provider e percentuale di fallback dovrebbero essere correlati per release senza inviare coordinate precise o contenuti personali.

### Roadmap consigliata dopo 18.10.6

Priorità **P0 – fiducia**: scoreboard interno di ETA radar previsto/osservato, false-positive/false-negative severe, freshness per provider, dashboard di fallback/errori e alert di regressione per release.

Priorità **P1 – temporali/vento**: Convective Risk 3.0 con CAPE/CIN/shear/freezing level + probabilità ensemble + fulmini osservati; mantenere sempre separato il warning ufficiale.

Priorità **P2 – nowcast**: cell segmentation e tracking multi-cella, crescita/decadimento per oggetto, cone calibrato dai veri errori e previsione start/stop pioggia con distribuzione probabilistica invece di un singolo minuto.

Priorità **P3 – decisione**: verificare Route 3.0 e Weather Twin contro outcome reali, aggiungere “perché questa finestra è migliore” e confronto fra partenza adesso / +30 / +60 / +120.

Priorità **P4 – trasparenza**: pagina pubblica Accuracy/Trust con metriche aggregate per città/fenomeno/lead-time, numero campioni e data ultimo aggiornamento. È la differenziazione più forte rispetto a un'app che mostra soltanto mappe e chatbot.

### Release alignment / QA

La release usa `translation_seed_version=18.10.6-radar-clarity-guest-ui-v1`. Il catalogo contiene **4.424 chiavi × 5 lingue = 22.120 traduzioni**. Privacy Policy, Cookie Policy, `SECURITY.md`, `ARCHITECTURE.md`, baseline SQLite e `mysql-schema.sql` sono riallineati. Il gate 18.10.6 verifica anche assenza del caso `null min`, nessuna stima radar stale prima di due frame, stato “segnale insufficiente”, timeline leggibile, responsive `alert-day-head`, guest boundary e completezza IT/EN/ES/FR/DE.

## 20.1 — P3 fase 1: grafo dipendenze esplicito e installer ESM centralizzato

Il P3 parte dalla base P2 completata e riduce il debito rimasto negli adapter dei domini. Le 19 implementazioni sotto `modules/esm/domains/` e `modules/esm/features/` non contengono più una copia privata di `serviceProxy`, della cattura dei compatibility global e della pubblicazione nel registry. Questa logica è ora centralizzata una sola volta in `modules/esm/core/service-registry.mjs` tramite `services.installModule()`.

Ogni dominio/feature dichiara due contratti immutabili: `serviceNames` (servizi forniti) e `dependencies` (servizi consumati). La risoluzione resta volutamente lazy per i servizi UI pubblicati dopo il bootstrap pre-app, ma il grafo è ora ispezionabile e verificabile staticamente. `qa/p3_dependency_graph_smoke.mjs` importa tutti i 19 moduli, verifica nomi noti, provider univoci, assenza di self-dependency e presenza del centralized installer. `qa/run-all.sh` esegue anche il precedente domain ESM smoke, che ora fa parte della suite completa.

La revisione Service Worker è `p3-module-graph-1`, così un aggiornamento da P2 non riutilizza una shell con moduli fingerprintati della generazione precedente. `package.json` espone inoltre `npm run check:graph` e `check:full` include il controllo del grafo prima di ESLint/esbuild.

**Stato P3 fase 1:** adapter duplicati eliminati e grafo dei servizi reso esplicito. La business logic dei domini è invariata; le fasi P3 successive possono sostituire progressivamente il compatibility window con injection/import diretti dominio per dominio e alleggerire ulteriormente `js/app.js`/`js/suite.js`.

## 20.1 — P3 fase 2: dependency injection diretta nei domini/feature

La seconda tranche P3 elimina il compatibility access ai servizi MeteoNexa dentro tutti i 19 moduli sotto `modules/esm/domains/` e `modules/esm/features/`. I moduli non leggono e non scrivono più `window.MeteoNexa*` e non mantengono più mappe `SERVICE_KEYS`. `services.installModule()` passa invece tre boundary espliciti alla factory: il vero browser host (`window`) solo per API Web/DOM, una vista lazy `deps` limitata alle dipendenze dichiarate e una vista `provided` limitata ai servizi dichiarati in `serviceNames`.

Le dipendenze restano lazy per supportare servizi UI pubblicati dopo il bootstrap iniziale, ma il nome deve essere dichiarato nel grafo. La pubblicazione di un servizio non dichiarato viene rifiutata con `METEONEXA_MODULE_SERVICE_UNDECLARED`; `publish()` continua a scrivere solo nel registry interno e non ricrea compatibility global. I moduli che prima catturavano implicitamente `MeteoNexaCore` ora dichiarano `core` in `dependencies`, rendendo anche quel legame visibile al graph check.

`qa/p3_dependency_injection_smoke.mjs` verifica i 19 moduli, assenza di named global, assenza di `SERVICE_KEYS`, firma factory DI, injection reale dal registry, pubblicazione centralizzata e rifiuto dei servizi non dichiarati. `npm run check:di` espone il gate e `check:full` lo esegue insieme a graph check, ESLint ed esbuild. La revisione Service Worker è `p3-direct-di-2`.

**Stato P3 fase 2:** dependency injection diretta completata per domini e feature; il compatibility window non è più usato nel grafo applicativo ESM. Restano da alleggerire i grandi shell classici (`js/app.js`/Suite) e, dove conveniente, sostituire il service lookup con import/callback più locali.

---

## 18.10.5 — affidabilità allerte ufficiali + Severe Outlook 2.0

La 18.10.5 interviene sul confine più delicato dell'app: distinguere un **avviso ufficiale realmente valido per il punto selezionato** da una previsione MeteoNexa. Lo schema DB resta **23**.

### Allerte ufficiali: punto, validità e fallback

La sorgente primaria resta MeteoAlarm EDR quando configurata. Le feature GeoJSON vengono accettate come `relevant` soltanto quando la geometria contiene le coordinate richieste; un livello esplicitamente verde viene mantenuto verde e filtrato dalla UI. Ogni avviso viene normalizzato in uno stato temporale `active`, `upcoming`, `expired` o `unknown`: una futura allerta viene quindi mostrata come **pubblicata ma non ancora attiva**, mentre una scaduta non può rimanere visibile.

Il feed Atom rimane un fallback di resilienza, ma un match testuale sulla sola regione (es. "Liguria") finisce in `regionalAdvisories` e **non** viene più presentato come prova che la singola località sia dentro l'area ufficiale. In produzione è quindi fortemente consigliato configurare `METEONEXA_METEOALARM_EDR_TOKEN`: senza EDR l’app resta resiliente, ma i match regionali vengono mostrati prudentemente come avvisi da verificare e non come allerta puntuale. Se il provider restituisce una risposta fresca e nessun avviso geospaziale pertinente, quella risposta vuota è autorevole: un vecchio snapshot server-side non può più riattivare l'allerta. Lo snapshot viene riutilizzato soltanto durante indisponibilità/degrado del provider ed è comunque sottoposto a scadenza.

### Severe Outlook 2.0: temporali e vento forte previsti

`api/intelligence/severe_outlook_helpers.php` introduce un canale previsionale distinto dagli avvisi ufficiali. Usa il consensus delle **sei famiglie modello** già presenti (ECMWF IFS, ECMWF AIFS, ICON, GFS, Météo-France, UKMO), scarta modelli stale e richiede almeno **3 modelli freschi** e almeno **50% di accordo** con minimo 2 voti concordi.

Per i temporali costruisce finestre fino a **72 ore** dai weather code convettivi dei modelli e può aumentare la confidence nel brevissimo termine quando esiste evidenza fulmini coerente. Per il vento forte confronta le raffiche previste modello per modello con la soglia (55 km/h di default, soglia profilo negli Smart Alert), calcola picco/mediana/accordo e genera una finestra temporale. Questi eventi hanno sempre `official=false`, `predictive=true` e sorgente `MeteoNexa Severe Outlook 2.0`: non vengono mai chiamati "allerta ufficiale".

Panoramica mostra separatamente il segnale previsionale con orario di inizio, confidence e `voti/modelli + percentuale di accordo`. Gli Smart Alert server-side usano lo stesso Severe Outlook 2.0 per notifiche di temporali/vento forte, senza passare da LLM. L'endpoint pubblico severe usa coordinate arrotondate a 3 decimali, nessun account/device id e nessun AI linguistico esterno.

### Privacy, Cookie, i18n e release alignment

Privacy Policy e Cookie Policy descrivono esplicitamente la distinzione tra MeteoAlarm ufficiale e forecast severe MeteoNexa, il fallback regionale non geospaziale e il consensus a sei modelli. Non vengono introdotti nuovi cookie, tracker, analytics o storage di profilazione. Tutto il nuovo copy UI/backend usa il catalogo i18n: **4.401 chiavi × 5 lingue = 22.005 traduzioni**.

`README.md`, `SECURITY.md`, `ARCHITECTURE.md`, `mysql-schema.sql`, baseline SQLite e seed traduzioni sono allineati alla **18.10.5** con `translation_seed_version=18.10.5-official-alert-severe-outlook-v2`.

### QA

`qa/release_18105_smoke.py` blocca la release se ricompaiono i casi di falsa elevazione (verde→giallo, regione→punto, snapshot stale sopra una risposta fresca), se manca la distinzione active/upcoming, se Severe Outlook non richiede evidenza multi-modello o se una delle nuove chiavi manca in IT/EN/ES/FR/DE. La suite completa continua inoltre a verificare parità MySQL/SQLite, fingerprint, sintassi PHP/JS, privacy e regressioni core.

---

## 18.10.4 — primo avvio i18n atomico + Intelligence 2.0 allineata

La 18.10.4 corregge due regressioni visibili della 18.10.3 senza cambiare lo schema DB, che resta **23**.

### Traduzioni al primo caricamento

Il runtime i18n non considera più pronta l'interfaccia quando il catalogo è vuoto. Su un deploy nuovo MySQL può impiegare alcuni secondi a completare seed e migration: in precedenza il timeout breve poteva rimuovere `i18n-pending` prima che `api/i18n.php` fosse disponibile, mostrando una schermata con icone e contenitori ma senza testi.

La release aggiunge `api/i18n-fallback.php`, endpoint **database-independent** che legge esclusivamente il catalogo traduzioni impacchettato con la release. Il fallback non apre il DB, non legge sessioni/account, non carica secret e serve una sola lingua per richiesta. Appena `api/i18n.php` torna disponibile, il catalogo del database torna autorevole e può quindi continuare a contenere personalizzazioni dell'operatore. Il runtime mantiene lo splash i18n finché esiste un catalogo valido e ritenta automaticamente in caso di riavvio temporaneo di PHP/web server.

### Intelligence 2.0: stesso design system degli article esistenti

Gli article **Nowcast 2.0**, **Affidabilità / Confidence 2.0**, **Personal Weather Twin** e **Modelli AI meteorologici** usano ora lo stesso contratto grafico degli article Intelligence già presenti: griglia a due colonne desktop con gli stessi gap e padding degli article immediatamente precedenti, altezza bilanciata, header/background coerenti, sub-card basate su `var(--line)` / `var(--surface-faint)` e passaggio a colonna singola su tablet/mobile. Non sono stati aggiunti testi UI hardcoded.

### Release alignment

Privacy Policy, Cookie Policy, `SECURITY.md`, `ARCHITECTURE.md`, seed traduzioni, baseline SQLite e `mysql-schema.sql` sono portati alla **18.10.4**. Il seed traduzioni è `18.10.4-i18n-first-boot-layout-v1`. `meteonexa_sync_policy_18104()` aggiorna soltanto i marker legali standard ancora non personalizzati.

### QA

`qa/release_18104_smoke.py` verifica il fallback i18n senza DB, il divieto di rendere `i18n-ready` con catalogo vuoto, il layout coerente/responsive dei nuovi article, la completezza IT/EN/ES/FR/DE e l'allineamento MySQL/SQLite/seed.

---

## 18.10.3 — accesso QA/Diagnostics + provisioning amministratore

La 18.10.3 rende esplicito e deployabile l’accesso amministrativo alle console QA/Diagnostics senza salvare l’indirizzo amministratore in chiaro nel database attivo. Lo schema resta **23**.

### Accesso

Dopo il login con l’account QA autorizzato, la voce **QA / Diagnostica** è disponibile nelle Impostazioni. Gli entry point diretti sono `./qa/` e `./diagnostics/`; entrambi verificano `diagnosticsAllowed=true` lato server e rispondono 403 agli account non autorizzati.

La release contiene un amministratore bootstrap configurato nel package. Al primo bootstrap `api/database.php` normalizza l’indirizzo e salva in `app_metadata.qa_admin_email_hashes` esclusivamente un **HMAC-SHA256 legato alla `.app-secret` del deployment**. Questo vale sia per SQLite localhost sia per MySQL produzione. Il valore non può essere precalcolato correttamente dentro i due database distributivi, perché ogni installazione usa una secret diversa.

Per aggiungere amministratori senza modificare il package si può usare `METEONEXA_QA_ADMIN_EMAILS`, con più indirizzi separati da virgola/punto e virgola. La variabile è ora documentata in `.env.example` ed esposta in `docker-compose.yml`.

### Database e revoca

La migration `meteonexa_sync_qa_admins_18103()` fa merge con eventuali HMAC già presenti e registra `qa_admin_provision_18_10_3=done`. Il marker one-shot permette una revoca successiva: eliminando l’HMAC autorizzato dal DB, la release non lo reinserisce a ogni richiesta. Il seed MySQL/SQLite resta privo di HMAC non validi o email in chiaro.

### Privacy / Cookie / Security / Architecture

Privacy e Cookie Policy sono aggiornate alla **18.10.3**. Il provisioning QA è server-side e non introduce cookie, tracker, analytics, nuove categorie di dati degli utenti o nuovi trasferimenti. `SECURITY.md` e `ARCHITECTURE.md` documentano il trust boundary e il provisioning deployment-bound.

### QA release

`qa/release_18103_smoke.py` verifica versione, policy, seed DB, wiring della variabile ambiente, presenza dell’amministratore bootstrap, assenza dell’indirizzo in chiaro nei metadata SQLite/MySQL e simula la conversione in HMAC con una secret di test.

---

## 18.10.2 — riallineamento MySQL/SQLite + release gate DB

La 18.10.2 chiude il disallineamento individuato nel pacchetto 18.10.1 tra la baseline SQLite e `api/install/mysql-schema.sql`. Lo schema logico resta **23**: non vengono introdotte nuove tabelle o colonne.

### Database di produzione MySQL

`api/install/mysql-schema.sql` ora dichiara `app_version=18.10.2`, `schema_version=23` e `translation_seed_version=18.10.2-db-alignment-hardening-v1`, allineati con `api/install/meteonexa-baseline.sqlite` e con il catalogo `translations-18.4.json`. Le installazioni MySQL già esistenti continuano a essere aggiornate da `api/database.php`: la versione applicativa viene sincronizzata al bootstrap, le nuove chiavi i18n vengono importate senza sovrascrivere personalizzazioni e la migration `policy_18_10_2` aggiorna il marker Cookie solo quando è ancora il testo standard 18.10.1.

### Gate anti-disallineamento

`qa/release_audit.py` verifica ora anche i tre metadata del baseline MySQL (`app_version`, `schema_version`, `translation_seed_version`) contro quelli SQLite e contro la release corrente. `qa/release_18102_smoke.py` aggiunge controlli mirati su policy, catalogo traduzioni e assenza della cartella documentale vuota. Una futura release con MySQL fermo a una versione precedente deve quindi fallire il QA.

### Cartella documentale vuota

La cartella `docs/` era vuota e non risultava referenziata da runtime, installer, CI o build. È stata eliminata. La documentazione versionata resta nei file root `README.md`, `SECURITY.md` e `ARCHITECTURE.md`.

### Privacy / Cookie / Security / Architecture

Privacy Policy e Cookie Policy sono aggiornate alla **18.10.2**. Questa release è un riallineamento tecnico e non introduce nuovi cookie, tracker, categorie di dati, destinatari, finalità o trasferimenti rispetto alla 18.10.1. `SECURITY.md` e `ARCHITECTURE.md` includono il nuovo contratto di coerenza DB/release.

---

## 18.10.1 — responsive + i18n hardening Intelligence 2.0

La 18.10.1 consolida la release 18.10.0 senza cambiare schema DB (**23**) né categorie di trattamento dati. L'obiettivo è rendere le nuove sezioni deployabili con lo stesso livello di qualità visiva, responsive e di localizzazione delle aree mature dell'app.

### Article coerenti e responsive

Gli article di Nowcast 2.0, Skill/Confidence 2.0, Personal Weather Twin e modelli AI continuano a usare `glass-panel`, `panel-title`, `section-kicker`, badge e spacing comuni. `intelligence-v2.css` aggiunge soltanto regole di adattamento del dominio: griglia a due colonne desktop, colonna singola su tablet, header/badge impilabili, model row con wrap e metriche Nowcast a colonna sui telefoni stretti. Nessuna nuova card introduce un visual system parallelo.

### Nessun testo UI hardcoded nelle nuove sezioni

Badge, tooltip, label accessibili dei grafici, metadata AIFS e copy dinamico passano da chiavi `v190.*` nel catalogo i18n. Il pacchetto contiene **4.374 chiavi × 5 lingue = 21.870 traduzioni** (IT/EN/ES/FR/DE). I valori tecnici come percentuali, orari, nomi propri dei modelli e unità restano dati, mentre le frasi che li descrivono sono localizzate.

### Accessibilità e rendering dinamico

Le timeline Nowcast/Confidence hanno label accessibili localizzate; i punti grafico espongono tooltip/`aria-label` tradotti. Tutti i valori dinamici inseriti nei template vengono escaped prima del rendering.

### Privacy / Cookie / Security / Architecture

Privacy Policy e Cookie Policy sono aggiornate alla **18.10.1**. La release non introduce nuove finalità, destinatari, categorie di dati, trasferimenti, cookie di profilazione o analytics di terze parti rispetto alla 18.10.0. `SECURITY.md` e `ARCHITECTURE.md` documentano il nuovo gate i18n/responsive e il vincolo di non introdurre copy user-facing fuori dal catalogo traduzioni.

### QA di release

`qa/release_18101_smoke.py` verifica versione, legal pages, completezza delle nuove chiavi su tutte le lingue, assenza dei badge/tooltip hardcoded 18.10.0, responsive CSS, migration policy e integrità baseline. Gli asset vengono nuovamente fingerprintati dopo ogni modifica JS/CSS/HTML.

---

## 18.10.0 — architettura modulare + Intelligence 2.0

La 18.10.0 è una release funzionale e architetturale. Lo schema DB resta **23**, ma frontend, intelligence, route e Copilot vengono portati sul nuovo percorso di evoluzione. Privacy Policy, Cookie Policy, Security e Architecture sono aggiornate insieme alla versione.

### Modularizzazione e store/event layer unico

Sono stati introdotti `modules/core/store.js` e moduli separati per `navigation`, `weather`, `radar`, `alerts`, `auth`, `privacy`, `feedback` e `ai`. Il nuovo store è la destinazione comune degli aggiornamenti; un bridge controllato mantiene la compatibilità con il legacy `state` mentre `js/app.js`/`js/suite.js` vengono svuotati progressivamente. Le feature 18.10.0 (`intelligence-v2` e `route-v3`) nascono già sul nuovo layer.

### Asset fingerprintati

`tools/fingerprint_assets.py` genera asset SHA-256 in `dist/` con hash a 12 caratteri e i manifest `asset-manifest.json` / `js/asset-manifest.js`. Le pagine pubbliche e il Service Worker usano gli asset fingerprintati, eliminando il rischio di servire JS/CSS vecchi con nomi stabili e cache lunga.

### Nowcast 2.0

Il nowcast 0–90 minuti produce step ogni 5 minuti e fonde radar/fusion, traiettoria della cella e consensus. Espone probabilità, confidence, stima inizio/fine precipitazione, crescita/decadimento, cone of uncertainty e verifica rolling dell'ETA contro gli snapshot osservati precedenti. Radar e osservazioni restano prioritari sul brevissimo termine.

### Model Skill 2.0 + Confidence Timeline 2.0

Skill 2.0 usa i campioni verificati per località, modello, fenomeno e lead time con decay temporale e shrinkage per numerosità. I modelli senza storico partono con peso neutro; i bucket con almeno 30 campioni e sufficiente numerosità effettiva vengono marcati come maturi. Confidence 2.0 costruisce una timeline fino a 48 ore usando accordo, spread, freshness e skill verificata. Forecast Change viene classificato come **materiale** solo oltre soglie utili alla decisione.

### Meteo Copilot tool-based

Il Copilot non usa l'LLM per calcolare il meteo. `api/ai/copilot_tools.php` seleziona tool server-side per previsione corrente, Nowcast 2.0, Confidence Timeline e Personal Weather Twin. Quando i tool sono disponibili, il vecchio browser context non viene inviato al provider LLM: il modello riceve soltanto risultati strutturati/sanificati, fonti e timestamp. Coordinate precise, identificativi account/device e credenziali sono esclusi dal payload LLM.

### Personal Weather Twin

Le soglie personali diventano un motore decisionale per moto, bici, corsa, trekking, mare, outdoor, **cantiere, pendolarismo, bambini e animali**. Il risultato è una finestra consigliata con score/stato anziché una semplice percentuale di pioggia.

### Route Weather 3.0

Il percorso viene campionato fino a 24 punti e il meteo viene richiesto da `api/route/weather-v3.php`, non più direttamente dal browser. Restano il motore di rischio, crosswind/ghiaccio/visibilità/temporale e la valutazione delle partenze alternative; il nuovo modulo Route V3 pubblica cross-section, punto critico, rischio massimo e confronto partenza selezionata/migliore nello store centrale.

### ECMWF AIFS come sesta evidenza

Il consensus Intelligence passa da cinque a **sei** famiglie: ECMWF IFS, **ECMWF AIFS**, ICON, GFS, Météo-France e UKMO. AIFS viene trattato come ulteriore modello meteorologico AI indipendente. Non sostituisce radar/observations e non ottiene un peso privilegiato: Skill 2.0 deve prima verificarne le prestazioni locali. L'LLM OpenRouter/Groq resta separato dal calcolo forecast.

### Privacy / Cookie / Security

La Privacy Policy 18.10.0 documenta AIFS, il confine dei tool Copilot, Personal Weather Twin e il passaggio server-side delle coordinate campionate di Route Weather 3.0 verso Open-Meteo. La Cookie Policy chiarisce che le nuove feature riusano cookie tecnici/sessione e non introducono advertising, profilazione o analytics di terze parti. `SECURITY.md` e `ARCHITECTURE.md` diventano documenti versionati obbligatori di release.

### File principali

- `modules/esm/core/store.mjs`
- `modules/esm/domains/*.mjs`
- `modules/esm/features/intelligence-v2.mjs`
- `modules/esm/features/route-v3.mjs`
- `api/intelligence/v190_helpers.php`
- `api/ai/copilot_tools.php`
- `api/route/weather-v3.php`
- `tools/fingerprint_assets.py`
- `SECURITY.md`
- `ARCHITECTURE.md`

---

## 18.9.7.11 — favicon, Segnala bug, Radar e audit applicativo

Questa release è un hardening della 18.9.7.10: non cambia lo schema DB (**23**) e mantiene il principio per cui l'AI interpreta evidenze meteorologiche verificabili senza diventare la sorgente autorevole di allerta o previsione.

### Favicon e branding

Il file grafico del logo era già corretto, ma favicon e icone PWA avevano URL stabili mentre Apache le serviva con cache `immutable` di 30 giorni. Un browser che aveva già memorizzato la vecchia favicon poteva quindi continuare a mostrarla anche dopo il cambio del logo.

Correzione 18.9.7.11:

- rigenerate `favicon-32.png`, `apple-touch-icon.png`, `icon-192.png` e `icon-512.png` da `assets/logo.png`;
- aggiunto `assets/icons/favicon.ico` multipla risoluzione alla root;
- favicon, Apple Touch Icon e icone manifest sono versionate con `?v=18.9.7.11`;
- il Service Worker usa URL di branding versionati con il build corrente;
- `/assets/icons/favicon.ico` non viene più mantenuto `immutable`: può essere rivalidato ad ogni release.

In produzione è quindi sufficiente pubblicare la release e far attivare il nuovo Service Worker; non è necessario rinominare manualmente il logo ad ogni aggiornamento.

### Segnala bug: consegna email resa coerente

Il trasporto SMTP era già condiviso con le altre email, ma `Segnala bug` utilizzava obbligatoriamente `smtp_settings.from_email` anche come **destinatario**. Questo mescolava due responsabilità diverse: identità del mittente e casella effettivamente monitorata dal supporto.

La 18.9.7.11 risolve il destinatario esclusivamente lato server in questo ordine:

1. `METEONEXA_BUG_REPORT_EMAIL`, se valorizzato con un indirizzo valido;
2. `METEONEXA_PRIVACY_CONTACT_EMAIL`;
3. `smtp_settings.from_email`, solo come fallback retrocompatibile.

Il browser non può scegliere né sovrascrivere il destinatario. Restano invariati: rate limit IP/globali, honeypot, validazione MIME lato server, limiti allegati, SMTP TLS e fallback `mail()` quando esplicitamente consentito dal deployment.

Configurazione consigliata in produzione:

```text
METEONEXA_BUG_REPORT_EMAIL=support@dominio.tld
METEONEXA_PRIVACY_CONTACT_EMAIL=privacy@dominio.tld
```

Le due caselle possono coincidere, ma tenerle separate permette di gestire meglio supporto operativo e richieste privacy.

### Radar: voce menu che rimaneva con la rotellina

Il problema non era nel renderer Radar in sé. Il loader globale associava l'ultimo click su qualunque `<button>` all'operazione asincrona successiva; poiché anche le voci di navigazione sono pulsanti, `Radar` poteva ricevere `button-loading`, `disabled` e `aria-busy` e restare visivamente bloccato durante una richiesta lenta.

Correzione:

- i controlli di navigazione `[data-page]`, `.nav-link` e `.mobile-nav-link` sono esclusi dal loader del singolo pulsante;
- la navigazione continua a mostrare il preloader globale;
- i timeout e i guard già presenti in `ensureRadar()` restano attivi;
- la stessa protezione vale per tutte le voci menu, evitando regressioni analoghe in altre sezioni.

### Audit security

**Valutazione:** base buona e sopra la media per una PWA meteo consumer. Non sono emersi motivi per riscrivere l'architettura di sicurezza, ma ci sono aree di hardening da pianificare.

Punti già corretti/positivi:

- cookie/sessione con `HttpOnly`, `SameSite=Strict` e `Secure` sotto HTTPS;
- token sensibili conservati server-side in forma hash/cifrata;
- SMTP con TLS 1.2+, verifica certificato e credenziali cifrate a riposo;
- rate limiting per autenticazione, feedback e API sensibili;
- validazione input e MIME server-side per gli allegati;
- CSP restrittiva, `frame-ancestors 'none'`, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, COOP/CORP e HSTS;
- directory/file di configurazione, segreti, log e DB non esposti via Apache;
- endpoint guest separati dagli endpoint autenticati;
- trigger di eventi severi deterministici: l'LLM non può inventare o sopprimere un'allerta.

Hardening consigliato:

- eliminare progressivamente `style-src-attr 'unsafe-inline'` sostituendo gli style inline con classi o token CSS;
- valutare `HSTS includeSubDomains` e preload **solo** quando tutti i sottodomini sono definitivamente HTTPS;
- aggiungere Content Security Policy reporting e osservabilità degli errori runtime;
- automatizzare dependency/SCA scan e secret scan nella CI;
- aggiungere test di sicurezza su upload allegati, rate limit, session fixation/revoca e CORS/same-origin;
- ruotare periodicamente secret applicativo/SMTP/API con procedura documentata.

### Audit architettura

La separazione API PHP / PWA / worker / DB è sensata e l'app ha già un numero significativo di boundary server-side. Il principale debito tecnico è invece nel frontend: `js/app.js` è diventato molto grande e `js/advanced.js` / `js/suite.js` estendono diversi lifecycle globali. Questo aumenta il rischio di race condition, doppi listener e regressioni come quella del loader Radar.

Priorità architetturali:

1. dividere il frontend in moduli per dominio (`weather`, `radar`, `alerts`, `auth`, `privacy`, `feedback`, `ai`, `navigation`);
2. introdurre un piccolo event/store layer unico al posto di patch successive dello stato globale;
3. centralizzare build/versione e asset fingerprinting, evitando la versione duplicata in molti file;
4. adottare asset hashed/fingerprinted in produzione invece di file statici con nome stabile + cache lunga;
5. aggiungere tracing/error monitoring frontend-backend con correlation ID anonimo;
6. mantenere i provider esterni dietro gateway server-side quando trattano dati potenzialmente personali o quando servono chiavi;
7. continuare con regression test Playwright su guest, account, offline, PWA update e mobile.

### Cookie Policy

Il comportamento reale osservato è coerente con la Cookie Policy attuale:

- non risultano advertising cookie, profilazione o analytics di terze parti;
- gli strumenti persistenti servono a sessione, trusted device, lingua/preferenze, PWA/offline e sicurezza;
- il riquadro iniziale è un'**informativa** (“Ho capito”), non un falso banner di consenso;
- la geolocalizzazione usa il permesso nativo del browser e non viene trasformata in consenso cookie;
- le funzionalità AI/feedback non introducono un nuovo cookie di profilazione.

Finché questa situazione resta invariata, un CMP con “Accetta/Rifiuta marketing” non aggiunge valore. Se in futuro vengono introdotti analytics non strettamente necessari, advertising, fingerprinting o tracking cross-site, la Cookie Policy e la gestione del consenso dovranno essere estese prima del rilascio.

### Privacy Policy

L'informativa è sostanzialmente allineata alle funzioni presenti: autenticazione, dispositivi/accessi, preferenze, geolocalizzazione, feedback con allegati, push, provider meteo e uso opzionale dell'AI sono già trattati. In questa release è stato corretto il testo di `Segnala bug` per riflettere il nuovo destinatario server-side ed è stata aggiornata la data all'11 settembre 2026.

Controlli organizzativi da mantenere fuori dal codice:

- il titolare deve essere indicato con identità/ragione sociale e recapiti reali;
- basi giuridiche, tempi di conservazione e sub-responsabili devono corrispondere ai contratti effettivamente in uso;
- l'elenco provider/trasferimenti extra SEE va aggiornato quando cambia provider AI, push, email o meteo;
- eventuale DPO va indicato solo se realmente nominato;
- per richieste privacy va mantenuta una procedura operativa, non solo una pagina web.

Questa è una revisione tecnica di coerenza tra codice e informative, non un parere legale.

### Roadmap prodotto: come differenziare MeteoNexa da un'app meteo tradizionale

L'obiettivo non dovrebbe essere “aggiungere una chat AI”, ma costruire un prodotto che dica **cosa succede, quando, quanto è sicuro e cosa cambia per l'utente**, dimostrandolo con dati e storico di accuratezza.

MeteoNexa parte già da una base più avanzata di una classica app “icone + previsione”: nel codice sono presenti consensus multi-modello, Model Skill/accuracy, Weather Confidence Engine, Forecast Change, radar-motion/cell tracking con ETA e range di incertezza, lightning/satellite fusion, severe monitor, Decision Engine, Smart Alert e Route Weather 2.0. La roadmap seguente indica quindi **come portare questi moduli al livello successivo**, non come reimplementarli da zero.

#### Priorità P0 — vantaggio meteo reale

1. **Nowcast 0–90 minuti object-based 2.0**
   - estendere l'attuale cell tracking a traiettorie multi-frame più lunghe e stabili;
   - verificare automaticamente ETA previsto vs ETA osservato;
   - stimare inizio **e fine** della precipitazione sulla posizione, non solo l'impatto;
   - mostrare traiettoria, crescita/decadimento e cono di incertezza;
   - rendere ancora più forte la fusione fulmini/radar/satellite per temporali e grandine.

2. **Confidence Timeline**
   - trasformare l'attuale Weather Confidence Engine in una timeline oraria leggibile;
   - accordo/disaccordo modelli per ogni ora;
   - ensemble spread e freshness di ogni fonte;
   - affidabilità storica locale per lead time;
   - spiegazione “perché la previsione è cambiata”.

3. **Model Skill Engine 2.0**
   - evolvere lo skill già presente con verifica automatica previsione vs osservazione;
   - MAE/Brier score per località, fenomeno, modello e orizzonte;
   - pesi dinamici dei modelli con decay temporale e gestione sample-size;
   - backtest A/B prima di cambiare il peso produttivo;
   - nessun LLM nel calcolo numerico del forecast.

4. **AI forecast model come ulteriore evidenza**
   - integrare modelli AI meteorologici operativi, per esempio ECMWF AIFS dove licenza e distribuzione lo consentono;
   - confrontarli con NWP/ensemble tradizionali;
   - mostrare divergenze, non nasconderle.

#### Priorità P1 — AI utile all'utente

5. **Meteo Copilot vincolato ai tool**
   - l'AI interroga solo dati MeteoNexa strutturati;
   - risposta con timestamp, fonti, confidence e limiti;
   - niente numeri meteorologici inventati dal modello linguistico.

6. **Personal Weather Twin**
   - profili “corsa”, “bici”, “moto”, “cantiere”, “mare”, “bambini”, “animali”, “pendolarismo”;
   - soglie personali;
   - finestre migliori della giornata;
   - risposta del tipo “parti tra 35 minuti” invece del solo “40% pioggia”.

7. **Forecast Change Intelligence 2.0**
   - usare il Forecast Change già presente come motore eventi, non solo come pannello;
   - diff tra run successivi con soglie materialmente utili;
   - notifica solo se il cambiamento modifica una decisione dell'utente;
   - esempio: pioggia anticipata di 45 minuti, raffiche aumentate di 20 km/h, confidence scesa.

8. **Alert con lifecycle**
   - apertura, aggiornamento, escalation, de-escalation e chiusura;
   - deduplica semantica;
   - quiet hours;
   - motivo esplicito “perché ricevi questo avviso”.

#### Priorità P2 — esperienza superiore

9. **Route Weather Risk 3.0**
   - evolvere l'attuale Route Weather 2.0 con campionamento spazio-temporale più fitto;
   - meteo lungo strada/treno/bici/trekking;
   - rischio per posizione e ora stimata di passaggio;
   - cross-section/timeline pioggia, vento, ghiaccio e visibilità;
   - suggerimento di partenza alternativa quando il rischio cala in modo significativo.

10. **Sun & Cloud Window**
    - previsione di finestre di sole/nuvolosità a breve termine da satellite + modelli;
    - utile per outdoor, fotografia, fotovoltaico.

11. **Widget e quick actions**
    - widget PWA/installabili;
    - scorciatoie “Radar”, “Prossima pioggia”, “Casa”, “Lavoro”;
    - notifiche più informative senza aprire l'app.

12. **Accuracy scoreboard pubblico**
    - score per città e lead time con numerosità campione;
    - storico delle correzioni;
    - trasparenza come elemento di prodotto, non claim pubblicitario generico.

#### Cosa non fare come prima mossa

- non far “prevedere il meteo” direttamente a un LLM;
- non aggiungere community/social prima di avere moderazione, abuse controls e policy media;
- non moltiplicare provider senza una metrica di qualità e costi;
- non inviare più notifiche: inviare **meno notifiche ma più utili**;
- non nascondere l'incertezza: trasformarla in una feature comprensibile.

### Ordine di sviluppo consigliato

Per massimizzare il vantaggio competitivo:

- **Fase 1:** potenziare cell tracking/nowcast esistenti + verifica ETA + Forecast Change 2.0.
- **Fase 2:** Model Skill 2.0 + pesi calibrati + Confidence Timeline.
- **Fase 3:** Meteo Copilot tool-based + Personal Weather Twin sopra i dati già strutturati.
- **Fase 4:** Route Weather 3.0 + widget/quick actions.
- **Fase 5:** ulteriori fonti ufficiali e modelli AI meteorologici, valutati con lo stesso motore di skill prima di essere promossi in produzione.

La metrica guida non deve essere il numero di feature, ma: **errore locale verificato, precisione dell'ETA, alert utili/aperti, falsi positivi e tempo risparmiato all'utente**.

---

## 18.9.7.10 — nuovo logo meteo + Trust Brief operativo

Questa release parte dalla 18.9.7.8 e **non inserisce slogan commerciali nell’interfaccia**. Integra invece il logo meteo approvato e trasforma il concetto “cosa sta arrivando / quando / quali fonti concordano / affidabilità locale” in una funzione reale della Panoramica.

### Nuovo logo approvato

- `assets/logo-full.png`: lockup completo approvato (nuvola + sole + radar + wordmark MeteoNexa).
- `assets/logo.png`: icona meteo ricavata dallo stesso lockup e usata dove serve un simbolo compatto.
- favicon, Apple Touch Icon, PWA 192/512 e maskable rigenerate dallo stesso marchio.
- login desktop e boot splash usano il lockup completo; sidebar e superfici compatte usano l’icona + wordmark responsive.

### Trust Brief in Panoramica

Nuovo pannello **“Cosa sta arrivando”** disponibile a guest e utenti email. Non è testo marketing: legge i dati già prodotti dal motore meteorologico e mostra in modo sintetico:

- evento più rilevante / prossima precipitazione nelle ore successive;
- quando è previsto l’arrivo;
- quanti modelli freschi sono disponibili e la percentuale di accordo;
- affidabilità locale verificata per gli utenti email quando esistono abbastanza campioni storici;
- stato di apprendimento quando i campioni non sono ancora sufficienti.

Il pannello usa esclusivamente il motore deterministico già presente: severe monitor, forecast fusion, nowcast e storico di accuratezza. **L’AI non genera né modifica il trigger meteo.** Per gli utenti email c’è il pulsante “Chiedi all’AI”, che apre l’assistente con una richiesta vincolata alle evidenze MeteoNexa e chiede esplicitamente di dichiarare eventuali disaccordi tra fonti.

Catalogo i18n: **4.311 chiavi × 5 lingue = 21.555 righe**. Schema DB invariato: **23**.

### Privacy / Cookie

Questa funzione non introduce nuovi cookie, provider o categorie di dati: usa dati meteo già presenti nell’app e, solo su azione dell’utente email, lo stesso contesto AI già descritto nella Privacy Policy. Cookie Policy e Privacy Policy restano quindi sostanzialmente invariate; sono stati aggiornati solo i riferimenti di build/cache alla 18.9.7.10.

---
## Audit ereditato dalla 18.9.7.8 — security, architettura, privacy e responsive

Questa release parte dalla `18.9.7.7`, mantiene **schema DB 23** e chiude le incongruenze emerse da un audit trasversale prima di introdurre nuove funzioni commerciali.

### Correzioni architetturali e privacy

- Nota storica 18.9.7.10: `Segnala bug` usava `smtp_settings.from_email` anche come destinatario. **La 18.9.7.11 supera questa scelta** introducendo `METEONEXA_BUG_REPORT_EMAIL` e i fallback server-side descritti nella sezione release corrente; il browser continua a non poter scegliere il destinatario.
- la retention reale di `auth_access_history` è stata allineata alla policy: **massimo 30 giorni** anche nel prune globale (prima era rimasto un vecchio limite tecnico di 90 giorni); diagnostica e commenti sono coerenti a 30 giorni.
- la baseline e il package i18n contengono gli stessi testi legali a 30 giorni in IT/EN/ES/FR/DE.
- la geocodifica inversa non chiama più BigDataCloud direttamente dal browser: `api/location/reverse.php` è un gateway same-origin read-only, rate-limited, senza credenziali account/device; arrotonda le coordinate a 3 decimali prima del contatto server-side. BigDataCloud è stato rimosso dalla `connect-src` browser.
- `api/ui-config.php` calcola le capability email/AI dallo **stato runtime DB effettivo**, evitando che la UI dichiari un servizio disabilitato quando la credenziale è presente solo nel DB cifrato.

### Privacy e Cookie Policy

Le policy sono state aggiornate con una dichiarazione esplicita della geocodifica inversa server-side. La funzione non introduce nuovi cookie: dopo il consenso alla geolocalizzazione, il browser comunica solo con MeteoNexa; il backend inoltra a BigDataCloud coordinate approssimate. Restano invariati i confini guest/account, il monitor severo credentialless, il radar, NASA GIBS e OpenFreeMap già dichiarati.

### Responsive

La matrice E2E include ora anche orientamenti landscape reali: `800×360`, `844×390`, `932×430`, `1024×768` e `1180×820`, oltre ai portrait mobile/tablet e ai desktop fino a 4K. Il requisito è assenza di overflow orizzontale nelle pagine principali e nei dialog principali.

### Branding

L’audit 18.9.7.8 aveva introdotto un pittogramma geometrico sperimentale. Nella **18.9.7.11** quel segno è stato definitivamente sostituito dal logo meteo approvato dall’utente (nuvola, sole e radar), con asset PWA coordinati.

### Principio AI e affidabilità

Per eventi severi l'LLM **non è la sorgente autorevole del trigger**. Grandine, neve, fulmini, vento/ghiaccio e altri eventi restano rilevati da evidenze meteorologiche deterministiche (radar, lightning, modelli, nowcast, freshness). L'AI interviene sopra questo livello per spiegare, prioritizzare e trasformare le evidenze in indicazioni leggibili. Questo evita che una risposta generativa possa inventare o sopprimere un evento di sicurezza. Nella 18.9.7.11 gli eventi severi freschi/autorevoli entrano nel contesto sanificato dell'AI proattiva e possono attivare l'analisi automatica per un utente email che ha già abilitato la funzione; restano attivi i guard di deduplica e limite giornaliero. La Privacy Policy dichiara esplicitamente questo contesto.

### QA

È presente `qa/audit_18978_smoke.py`, che blocca regressioni su destinatario SMTP, retention 30 giorni, geocodifica same-origin, policy, capability DB, logo e matrice landscape. Oltre ai gate PHP/JS/SQLite/MySQL, la UI è stata verificata con Chromium headless a `360×800`, `390×844`, `430×932`, `768×1024`, `820×1180`, `800×360`, `844×390`, `932×430`, `1024×768`, `1180×820`, `1280×720`, `1440×900` e `1920×1080`: nessun overflow orizzontale rilevato nelle pagine principali. La suite Playwright multipiattaforma resta disponibile nel repository; Firefox non è stato eseguito nell'ambiente di build di questa release.

### Roadmap prodotto consigliata

Per differenziare MeteoNexa sul mercato, la priorità non dovrebbe essere aggiungere un LLM che "prevede" il tempo da solo, ma costruire un **AI Meteorologist explainable** sopra un motore osservativo/nowcast più forte. Roadmap consigliata:

1. nowcast radar 0–60 minuti con optical-flow/cell tracking e ETA locale per pioggia, grandine, neve e temporali;
2. lightning + radar + satellite fusion per tracciare celle temporalesche e direzione di arrivo;
3. AI proattiva che traduce evidenze fresche in un messaggio personale (`cosa`, `quando`, `quanto è affidabile`, `perché`, `cosa fare`) senza poter inventare il trigger;
4. alert intelligenti con sensibilità configurabile, deduplica, aggiornamento/cancellazione evento e motivazione "perché ti sto avvisando";
5. score pubblico di affidabilità per località e lead time, basato su verifiche reali e numerosità campionaria;
6. integrazione più profonda di fonti ufficiali/ARPA e radar ad alta risoluzione dove licenze/API lo consentono;
7. multi-location e route risk per casa, lavoro, famiglia, viaggio e attività;
8. segnalazioni community meteo con foto/video solo in una fase successiva, dopo moderazione, trust score e governance privacy.

---

## 18.9.7.7 — hotfix badge versione login

Questa release parte dalla `18.9.7.6`, mantiene **schema DB 23** e corregge un bug di rendering del badge versione nella schermata di accesso.

### Causa e fix

Il badge `v18.9.7.6` veniva mostrato correttamente dal markup per una frazione di secondo, ma il traduttore runtime generico interpretava la stringa tecnica `v18.9.7.6` come se fosse una chiave i18n (`x.y.z`) e la sostituiva con stringa vuota. Rimaneva quindi visibile solo il contenitore grafico.

- il badge login è ora marcato `data-i18n-skip="true"`;
- il traduttore DOM ignora esplicitamente il nodo e i discendenti marcati come contenuto tecnico non traducibile;
- `applyLoginWeatherBackdrop()` continua a riallineare il badge alla build corrente senza che il MutationObserver i18n lo cancelli;
- il fix è generale e impedisce lo stesso tipo di regressione per futuri valori tecnici esplicitamente esclusi dall'i18n;
- il background meteo dinamico della login resta invariato;
- SEO e tema chiaro della `18.9.7.6` restano invariati.

### Privacy / Cookie

Questo hotfix non introduce cookie, storage, provider, tracker o nuove finalità di trattamento. `privacy.html` e `cookie-policy.html` non richiedono modifiche sostanziali; i riferimenti agli asset sono stati riallineati alla build `18.9.7.7` per evitare cache miste.

### QA

È presente un regression gate dedicato che verifica che il badge versione sia escluso dall'i18n generico, rimanga valorizzato e sia coerente con la build applicativa.

---

## 18.9.7.6 — SEO indexabile e tema chiaro coerente

Questa release parte dalla `18.9.7.5`, mantiene **schema DB 23** e non introduce nuovi cookie, provider o finalità di trattamento.

### SEO della pagina principale

- `index.html` usa `lang="it"` come fallback server-side invece di `und`; l’i18n continua a sostituire la lingua a runtime.
- `title` e `description` hanno un fallback statico reale, quindi non risultano vuoti ai crawler che non eseguono JavaScript; a runtime restano localizzati da DB in IT/EN/ES/FR/DE.
- aggiunti `robots`, `googlebot`, canonical assoluto verso `https://www.meteonexa.com/`, Open Graph e Twitter Card;
- aggiunto markup strutturato `WebApplication` JSON-LD;
- aggiunti `robots.txt` e `sitemap.xml`; il sitemap pubblica la sola home canonica e `robots.txt` esclude API, QA, installer, diagnostica e proxy asset dalle aree da indicizzare;
- il canonical unifica i segnali SEO anche se produzione risponde sia a `meteonexa.com` sia a `www.meteonexa.com`.

La canonicalizzazione corrente è `www`: le navigazioni HTML dall’apex `meteonexa.com` vengono redirette con 308 a `www.meteonexa.com`; API e Service Worker non sono forzati cross-origin.

### Tema chiaro

È stato introdotto `css/light-theme.css`, caricato **dopo** `css/styles.css`, `css/advanced.css` e `css/suite.css`. Il tema chiaro non è più una piccola sovrascrittura del tema scuro ma un layer visuale centralizzato con palette e contrasto coerenti.

- sfondo generale grigio-azzurro molto chiaro, superfici bianche e bordi visibili;
- `glass-panel`, article, card, pannelli Intelligence/Advanced, Route, History, Devices, Bug report e modali sono distinguibili anche senza ombre forti;
- testo principale, secondario e muted sono stati scuriti per mantenere leggibilità su fondo chiaro;
- input, textarea, select custom, Choices, dropdown, range, tabelle, toolbar, toast e dialog hanno uno stato light dedicato;
- sidebar/topbar/mobile navigation hanno superfici separate e stato active leggibile;
- Radar conserva il contrasto della mappa ma usa controlli chiari;
- colori semantici di successo/warning/pericolo sono stati ricalibrati per superfici bianche;
- la login conserva **il background meteo dinamico** introdotto nelle release precedenti; il tema chiaro modifica solo leggibilità e superfici del form, non elimina la scena meteo;
- `css/standalone.css` è stato riallineato per Privacy, Cookie e Offline.

### Privacy / Cookie

La release non aggiunge cookie, storage, tracker, SDK pubblicitari né nuovi destinatari di dati. `privacy.html` e `cookie-policy.html` restano quindi sostanzialmente invariati; sono stati soltanto riallineati gli asset alla build `18.9.7.6`. Il monitor eventi severi e i provider dichiarati dalla `18.9.7.5` restano invariati.

### QA aggiuntivo

La release aggiunge un gate dedicato che verifica: metatag SEO non vuoti, canonical, Open Graph/Twitter, JSON-LD, `robots.txt`, `sitemap.xml`, caricamento del foglio light dopo i CSS feature e coerenza di versione/seed nel baseline SQLite.

---

## 18.9.7.5 — login stabile e monitor eventi severi

Questa release parte dalla `18.9.7.4` fornita dall’autore e mantiene **schema DB 23**. I fix sono stati applicati senza eliminare il background meteo dinamico della login.

### Login e navigazione

- Ripristinato/aggiunto il badge versione `v18.9.7.5` in basso a destra nella schermata di accesso.
- Il background della login **rimane dinamico** e continua a rappresentare l’ultimo meteo locale recente già presente nel browser.
- Eliminato il flash iniziale del sole: il markup parte in stato `loading`, `js/boot-clock.js` risolve la scena dalla sola cache locale prima del primo paint utile e la UI viene resa visibile solo dopo la scelta della scena.
- Nessuna richiesta di geolocalizzazione, Open-Meteo o altro provider viene effettuata dalla login per decidere lo sfondo. Se lo snapshot manca o è vecchio viene usata una scena neutra/notturna basata solo sull’ora del dispositivo.
- Logout e “cancella cache” non disegnano più una login intermedia prima del `location.replace()`: rimane un solo passaggio di navigazione, eliminando il doppio refresh percepito su desktop, tablet e mobile.

### Monitor eventi severi

È stato aggiunto un monitor read-only e account-independent per **grandine, temporali, neve, vento forte, ghiaccio, nebbia severa e caldo estremo**. La Panoramica mostra la card solo quando esiste un segnale fresco. Il controllo in foreground avviene circa ogni **2 minuti**; il worker Docker viene eseguito di default ogni **180 secondi (3 minuti)** e il dispatcher Push applica la stessa soglia minima per utente email. Si tratta quindi di monitoraggio *near-real-time*, non di una garanzia di rilevamento istantaneo.

Principi di affidabilità:

- grandine rilevata solo da evidenza specifica WMO `96/99` (temporale con grandine), non dalla sola probabilità di pioggia o dal CAPE;
- XWeather, se configurato, può rafforzare il contesto dei fulmini ma non inventa da solo un evento di grandine;
- neve usa anche il passo a 15 minuti per stimare l’arrivo quando disponibile; l’evidenza `minutely_15` può attivare l’evento anche se l’ora corrente è già stata superata dal campionamento orario, evitando di perdere l’arrivo imminente tra due slot;
- dati provider marcati stale/degradati **non possono generare un nuovo avviso severo**;
- le **allerte ufficiali** restano un canale distinto e prioritario rispetto ai segnali previsionali del monitor;
- l’endpoint pubblico `api/weather/severe.php` non riceve sessione/device id, usa coordinate arrotondate a 3 decimali e non salva la località come dato account.

### AI

Il modello/provider AI non viene più mostrato nei messaggi dell’assistente all’utente. Provider e modello restano disponibili solo internamente per dispatch/diagnostica autorizzata. La configurazione OpenRouter resta vincolata al modello gratuito già previsto dalla release.

### Privacy e Cookie

`privacy.html` e `cookie-policy.html` sono aggiornate al **10 settembre 2026**. Il Monitor eventi severi non introduce nuovi cookie: lo stato della card pubblica resta in memoria. La policy specifica inoltre l’uso server-side facoltativo di XWeather come supporto per i fulmini e chiarisce il comportamento del background dinamico della login senza chiamate pre-accesso.

---

## 1. Architettura

### Produzione

```text
Internet
   |
   | DNS A meteonexa.com -> 80.211.128.184
   | CNAME www -> meteonexa.com
   v
Caddy :80/:443
   |
   | HTTPS / reverse proxy
   v
meteonexa-web :80
   |
   +---- meteonexa-db (MySQL 8.4, solo rete Docker interna)
   |
   +---- runtime /var/lib/meteonexa

meteonexa-worker
   |
   +---- pipeline meteo
   +---- push dispatch
   +---- MySQL
```

Il database MySQL **non pubblica la porta 3306 su Internet**. Caddy, `meteonexa-web` e la rete Docker `proxy` sono gli unici elementi necessari per pubblicare il sito.

### Localhost

In sviluppo locale MeteoNexa usa SQLite. Se non esiste una configurazione MySQL valida (`METEONEXA_DB_DRIVER=mysql` o `database.json` con driver MySQL), il backend usa automaticamente SQLite.

La baseline da copiare nel runtime locale è:

```text
api/install/meteonexa-baseline.sqlite
```

---

## 2. Struttura server usata in produzione

Sul VPS Aruba:

```text
/opt/apps/
├── backups/
├── meteonexa/
└── proxy/
    ├── Caddyfile
    └── docker-compose.yml
```

Container principali:

```text
caddy
meteonexa-db
meteonexa-web
meteonexa-worker
```

Rete Docker condivisa con Caddy:

```text
proxy
```

La cartella runtime dell'app è montata nel container come:

```text
/var/lib/meteonexa
```

e contiene file specifici dell'installazione, ad esempio `.app-secret` e `database.json`. Questi file **non devono essere committati né inseriti nello ZIP pubblico**.

---

## 3. Preparazione VPS da zero

Configurazione usata sul VPS `apps-prod-01`:

- Ubuntu Server 24.04 LTS
- Docker Engine
- Docker Compose
- utente operativo `deploy`
- accesso SSH tramite chiave pubblica
- login root SSH disabilitato
- password SSH disabilitata
- UFW aperto solo su SSH/HTTP/HTTPS

Porte firewall:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
sudo ufw status
```

Hardening SSH applicato:

```text
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
```

L'utente `deploy` deve appartenere al gruppo `docker`:

```bash
sudo usermod -aG docker deploy
```

Creazione layout:

```bash
sudo mkdir -p /opt/apps/{meteonexa,proxy,backups}
sudo chown -R deploy:deploy /opt/apps
```

Creazione rete reverse proxy:

```bash
docker network create proxy
```

---

## 4. Caddy e HTTPS

Caddy gestisce automaticamente certificati TLS e rinnovi. Non servono Certbot né un certificato acquistato separatamente.

Per servire entrambi gli host mantenendo **www.meteonexa.com** come origin canonico, il reverse proxy accetta entrambi gli host; l’applicazione canonicalizza le normali navigazioni HTML dall’apex `meteonexa.com` a `www` con 308:

```caddy
meteonexa.com, www.meteonexa.com {
    reverse_proxy meteonexa-web:80
}
```

Questa configurazione permette contemporaneamente:

```text
https://meteonexa.com/
https://www.meteonexa.com/
```

Per applicarla sul VPS:

```bash
cat > /opt/apps/proxy/Caddyfile <<'EOF_CADDY'
meteonexa.com, www.meteonexa.com {
    reverse_proxy meteonexa-web:80
}
EOF_CADDY

docker exec caddy caddy validate --config /etc/caddy/Caddyfile
docker exec caddy caddy reload --config /etc/caddy/Caddyfile
```

Verifica:

```bash
curl -I https://www.meteonexa.com/
curl -I https://www.meteonexa.com/
```

`https://www.meteonexa.com/` deve servire l’app; la navigazione HTML su `https://meteonexa.com/` deve rispondere 308 verso `https://www.meteonexa.com/`. Entrambi gli host devono essere terminati da Caddy/TLS.

---

## 5. DNS Aruba

Record web usati:

```text
A       @       80.211.128.184
CNAME   www     meteonexa.com
```

Non aggiungere un record A pubblico per MySQL.

Per Brevo sono stati configurati i record richiesti dal pannello Brevo per autenticazione dominio, DKIM, DMARC e branded subdomain. I valori vanno sempre copiati dal proprio account Brevo perché possono cambiare tra account.

Il branded subdomain scelto è:

```text
mail.meteonexa.com
```

La propagazione può essere diversa tra i nameserver Aruba; prima di modificare record già corretti, verificare direttamente i nameserver autoritativi con `dig`.

---

## 6. Installazione nuova in produzione

Estrarre il progetto in:

```text
/opt/apps/meteonexa
```

Poi:

```bash
cd /opt/apps/meteonexa
chmod +x docker/*.sh
./docker/restore-runtime.sh
./docker/init-env.sh
docker compose config --quiet
docker compose up -d --build
```

`restore-runtime.sh` non usa più un `runtime-seed.tgz`: crea il runtime dalla baseline SQLite pulita e genera un nuovo `.app-secret` specifico del deployment.

Attendere MySQL healthy:

```bash
docker compose ps
```

Poi migrare SQLite -> MySQL:

```bash
./docker/migrate-mysql-cli.sh
./docker/verify-mysql.sh
```

Output atteso:

```text
driver=mysql host=db database=meteonexa user=meteonexa
app_version      20.1
database_driver  mysql
schema_version   28
```

Non esporre `.env`, `.app-secret` o `database.json`.

---

## 7. Aggiornare una release già installata

Prima fare un backup della configurazione e del database.

### Backup .env

```bash
cp /opt/apps/meteonexa/.env /opt/apps/backups/meteonexa.env.$(date +%Y%m%d-%H%M%S)
chmod 600 /opt/apps/backups/meteonexa.env.*
```

### Backup MySQL

```bash
cd /opt/apps/meteonexa
set -a
. ./.env
set +a

docker compose exec -T db sh -lc \
  'mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --single-transaction --routines --triggers "$MYSQL_DATABASE"' \
  > /opt/apps/backups/meteonexa-mysql-$(date +%Y%m%d-%H%M%S).sql
chmod 600 /opt/apps/backups/meteonexa-mysql-*.sql
```

### Aggiornamento codice

Non eseguire `docker compose down -v` durante un normale update.

Copiare il nuovo tree applicativo sopra `/opt/apps/meteonexa`, preservando `.env` e `runtime/`, quindi:

```bash
cd /opt/apps/meteonexa
chmod +x docker/*.sh
docker compose config --quiet
docker compose up -d --build --force-recreate web worker
docker compose ps
./docker/verify-mysql.sh
```

---

## 8. SMTP Brevo

Configurazione produzione:

```text
Server:      smtp-relay.brevo.com
Porta:       587
Encryption:  STARTTLS
Auth:        AUTH PLAIN
From:        alerts@meteonexa.com
From name:   MeteoNexa
```

Il login SMTP e la SMTP key sono segreti e non sono inclusi nel repository.

Dopo aver autenticato `meteonexa.com` su Brevo e creato una **SMTP key** nella sezione SMTP:

```bash
cd /opt/apps/meteonexa
./docker/configure-smtp.sh
```

Lo script:

1. valida login e formato della chiave;
2. salva i parametri in `.env` con permessi `600`;
3. ricrea `web` e `worker`;
4. sincronizza la password nel profilo `smtp_settings` del DB in forma cifrata;
5. verifica che la credenziale letta dal DB coincida con quella di environment.

La chiave SMTP **non deve essere messa nello ZIP**.

### Test SMTP diretto

```bash
docker compose exec -T web php -r '
require_once "/var/www/html/api/bootstrap.php";
require_once "/var/www/html/api/SmtpMailer.php";
$c=load_config();
try {
    $m=new SmtpMailer($c["smtp"]);
    $m->sendHtml("DESTINATARIO@example.com","Test SMTP MeteoNexa","<p>SMTP OK</p>","SMTP OK");
    echo "SMTP SEND: OK\n";
} catch(Throwable $e) {
    echo "SMTP SEND ERROR=".$e->getMessage()."\n";
}
'
```

`SMTP SEND: OK` conferma STARTTLS, autenticazione e invio.

Per le segnalazioni bug è consigliata una casella di supporto monitorata, separata dall'identità del mittente SMTP:

```text
METEONEXA_BUG_REPORT_EMAIL=support@dominio.tld
METEONEXA_PRIVACY_CONTACT_EMAIL=privacy@dominio.tld
```

`Segnala bug` risolve il destinatario solo lato server: `METEONEXA_BUG_REPORT_EMAIL` → contatto privacy → `smtp_settings.from_email`. Il browser non può indicare un destinatario. La UI pubblica usa il contatto privacy e, se omesso, il mittente SMTP come fallback, senza esporre credenziali SMTP.

---

## 9. Localhost con SQLite

### Requisiti

- PHP 8.3+ consigliato
- estensioni `pdo_sqlite`, `sqlite3`, `curl`, `openssl`, `mbstring`

La baseline distribuita corrente è già **schema 28** ed è sanitizzata:

```text
api/install/meteonexa-baseline.sqlite
```

Su Windows PowerShell, dalla cartella del progetto:

```powershell
$runtime = Join-Path (Split-Path $PWD -Parent) "meteonexa-runtime-local"
New-Item -ItemType Directory -Force $runtime | Out-Null
Copy-Item ".\api\install\meteonexa-baseline.sqlite" "$runtime\meteonexa.sqlite" -Force

$env:METEONEXA_STORAGE_PATH = $runtime
$env:METEONEXA_DB_DRIVER = "sqlite"
$env:METEONEXA_BASE_URL = "http://127.0.0.1:8080/"

php -S 127.0.0.1:8080
```

Aprire:

```text
http://127.0.0.1:8080/
```

Al primo utilizzo MeteoNexa genera un `.app-secret` locale separato. Il runtime locale deve restare fuori dalla document root e non va committato.

Per ripristinare localhost allo snapshot pulito della release:

```powershell
Remove-Item "$runtime\*" -Recurse -Force
Copy-Item ".\api\install\meteonexa-baseline.sqlite" "$runtime\meteonexa.sqlite"
```

Non copiare il `database.json` della produzione nel runtime locale: in assenza di quel file e con `METEONEXA_DB_DRIVER=sqlite`, localhost resta su SQLite.

---

## 10. Backup della release

### Codice

Conservare lo ZIP della release in un archivio esterno al VPS.

### Produzione MySQL

Usare `mysqldump` come indicato sopra. Un backup MySQL contiene dati reali e deve essere trattato come dato riservato.

### SQLite localhost

Il backup pulito della release è già incluso nel codice:

```text
api/install/meteonexa-baseline.sqlite
```

Non è incluso un `seed.tgz`: era un artefatto temporaneo usato durante la prima migrazione e non appartiene alla distribuzione pulita.

---

## 11. Pulizia del VPS dopo i deploy

Per eliminare ZIP e cartelle temporanee senza toccare applicazione, MySQL, Caddy o backup:

```bash
cd /opt/apps
sudo find /opt/apps -maxdepth 1 -type f \
  \( -name '*.zip' -o -name '*.tgz' -o -name '*.tar' -o -name '*.sha256' \) \
  -print -delete

sudo find /opt/apps -maxdepth 1 -type d -name '_meteonexa*' -print -exec rm -rf {} +

docker image prune -f
```

Controllo finale:

```bash
ls -lah /opt/apps
docker ps
docker volume ls
```

Non eseguire `docker system prune --volumes` e non eseguire `docker compose down -v` se vuoi conservare MySQL.

---

## 12. Worker

Controllo:

```bash
docker logs meteonexa-worker --tail 100
```

Output sano:

```text
MeteoNexa pipeline
{"ok":true,...}
MeteoNexa push dispatch
{"ok":true,...}
```

Ricerca errori:

```bash
docker logs meteonexa-worker --tail 200 2>&1 | \
  grep -iE 'fatal|unhandled_exception|cannot redeclare|STORAGE_TABLE_INVALID' \
  || echo 'OK: worker senza errori'
```

---

## 13. Privacy e Cookie Policy

La release documenta e gestisce separatamente:

- modalità ospite e account email;
- sessione e trusted device;
- storico accessi terminati con retention massima di 30 giorni;
- posizione approssimativa solo quando disponibile/autorizzata;
- preferenze locali e storage browser;
- notifiche push;
- segnalazioni bug e allegati;
- dati meteo, radar, mappe e servizi esterni;
- AI esterna opzionale;
- account sync;
- trasferimenti verso provider esterni;
- diritti GDPR e canale privacy configurabile.

La Privacy Policy non contiene più riferimenti al vecchio dominio o al vecchio brand. Il sito pubblico indicato è `www.meteonexa.com`; il contatto privacy viene letto dal deployment tramite `METEONEXA_PRIVACY_CONTACT_EMAIL`, con fallback al destinatario bug configurato.

La Cookie Policy distingue cookie/sessione, trusted-device e storage locale e chiarisce che molte funzioni non introducono cookie aggiuntivi.

Prima di un rilascio pubblico, il gestore deve verificare che il contatto privacy configurato sia realmente monitorato e che i testi legali descrivano i provider effettivamente abilitati nell'installazione.

---

## 14. Sicurezza

Principi applicati nella release:

- HTTPS con Caddy e rinnovo automatico TLS;
- HSTS in HTTPS;
- CSP restrittiva;
- `X-Content-Type-Options: nosniff`;
- `X-Frame-Options: DENY`;
- `Referrer-Policy`;
- `Cross-Origin-Resource-Policy` / `Cross-Origin-Opener-Policy`;
- API same-origin dove necessario;
- rate limit su OTP, login, dispositivi e bug report;
- session cookie HttpOnly/Secure in HTTPS;
- trusted device con prova device-bound;
- password SMTP cifrata nel DB e secret fuori dal document root;
- MySQL non esposto pubblicamente;
- log applicativi senza password, email complete o stack trace verso il client;
- installer protetto e disabilitabile;
- segreti esclusi dal pacchetto e dal source control.

Dopo il deploy controllare:

```bash
curl -I https://www.meteonexa.com/
curl -I https://www.meteonexa.com/
```

Verificare la presenza di CSP, HSTS e degli altri header di sicurezza.

---

## 15. QA prima del rilascio

Suite corrente / forward-compatible:

```bash
cd /opt/apps/meteonexa
./qa/run-all.sh
```

Il pacchetto operativo non contiene un runner storico: `qa/run-all.sh` verifica la release corrente tramite contratti funzionali forward-compatible (model consistency, provider server-owned, Product Metrics, Decision Timeline/Forecast Change, probabilistic nowcast, Public Local Accuracy, Watch My Plan e Copilot/Route orchestration). La cronologia delle release resta documentata solo in questo README.

Controlli importanti inclusi:

- lint PHP;
- sintassi JavaScript;
- schema SQLite/MySQL;
- integrità SQLite;
- traduzioni IT/EN/ES/FR/DE;
- auth e trusted-device;
- privacy/cookie;
- pipeline meteo;
- push;
- SMTP `AUTH PLAIN`;
- assenza di segreti nel pacchetto;
- assenza di riferimenti al vecchio brand/dominio.

Playwright E2E è separato e richiede le dipendenze browser:

```bash
cd qa
npm ci --no-audit --no-fund
npx playwright install chromium firefox
npm run test:e2e
```

---

## 16. Checklist rilascio

Prima di dichiarare una release pronta:

```text
[ ] QA PASS
[ ] nessun segreto nello ZIP
[ ] baseline SQLite integrity_check = ok
[ ] schema/database baseline = 28
[ ] MySQL healthy
[ ] verify-mysql.sh OK
[ ] worker senza fatal error
[ ] www.meteonexa.com HTTP 200
[ ] meteonexa.com navigazione HTML GET/HEAD -> 308 verso www.meteonexa.com
[ ] HTTPS valido su entrambi
[ ] SMTP SEND: OK
[ ] OTP reale ricevuto
[ ] bug report ricevuto dal destinatario configurato
[ ] Privacy Policy verificata
[ ] Cookie Policy verificata
[ ] DNS/DKIM/DMARC Brevo autenticati
[ ] ZIP/cartelle temporanee rimossi dal VPS
[ ] backup MySQL conservato fuori dal container
```

---

## 17. File da non distribuire

Non inserire mai nel pacchetto pubblico:

```text
.env
.app-secret
database.json
vapid.json
*.sql con dump produzione
runtime-seed.tgz
SMTP key
API key
password MySQL
backup con dati utenti
```

La release sorgente deve avere **un solo documento operativo: questo `README.md`**.

## 20.1 — P3 fase 3: decomposizione dei shell `js/app.js` e Suite

La terza tranche P3 riduce il codice ancora concentrato nei due shell classici senza cambiare il contratto funzionale. Il motore di visualizzazione (canvas setup, tooltip/interaction dei grafici, chart meteo, weather FX e grafici motion/trend) è ora autorevole in `modules/esm/domains/visualization.mjs`; `js/app.js` conserva soltanto il wiring esplicito del contesto e le chiamate ai metodi restituiti dalla factory. Questo porta `js/app.js` da circa **7.509 a 6.493 righe**, eliminando oltre mille righe di implementazione grafica dal bootstrap principale.

Suite delega invece API constants, storage keys, HTTP JSON wrapper, localizzazione/formattazione comune e helper data/geografici a `modules/esm/domains/suite-support.mjs`. `js/suite.js` passa da circa **3.066 a 2.946 righe** e rimane focalizzato sui flussi funzionali (modelli, route, assistant e UI della suite) invece di duplicare infrastruttura di base.

Entrambi i nuovi moduli partecipano al service graph P3, al bootstrap ESM e al fingerprinting. `qa/p3_shell_decomposition_smoke.mjs` impone budget di dimensione ai shell, verifica che le implementazioni estratte non ricompaiano nei file classici e controlla che i nuovi moduli siano presenti nel manifest immutable. `npm run check:shell` espone il gate e `check:full` lo include insieme a graph/DI/ESLint/esbuild. La revisione Service Worker è `p3-shell-decomposition-3`.

**Stato P3 fase 3:** shell ulteriormente alleggeriti e responsabilità grafiche/runtime condivise spostate in ESM con boundary espliciti. Le prossime tranche possono concentrarsi sui grandi blocchi funzionali ancora presenti in `js/app.js` (forecast/intelligence/history/device lifecycle) e sulla separazione dei controller Suite.

## 20.1 — P3 fase 4: forecast/history/intelligence, lifecycle e integrazioni Suite

La quarta tranche P3 separa dai shell classici altri blocchi funzionali ad alta responsabilità, mantenendo invariati API pubbliche, stato runtime e flussi UI. `js/app.js` passa da circa **6.493 a 4.940 righe** e `js/suite.js` da circa **2.946 a 2.307 righe**.

Le nuove responsabilità autorevoli sono:

- `modules/esm/domains/forecast-history.mjs`: forecast fusion, condizioni orarie fuse, riepiloghi/trust brief e storico meteo con rendering dei relativi grafici;
- `modules/esm/domains/model-intelligence.mjs`: caricamento e rendering Intelligence, confronto modelli, nowcast/confidence, snapshot, forecast changes e decision evidence;
- `modules/esm/domains/device-sessions.mjs`: storico dispositivi/sessioni, revoca/cancellazione, riconciliazione delle revoche remote e intestazioni di localizzazione approssimata;
- `modules/esm/domains/app-lifecycle.mjs`: lifecycle globale della pagina/app, foreground/focus/pageshow, coerenza storage/auth, cache sentinel e recovery del root view;
- `modules/esm/domains/suite-integrations.mjs`: archivio radar, lightning live, push remoto, Netatmo e relativo wiring post-app Advanced/Suite.

Il grafo P3 arriva a **26 moduli ESM / 29 servizi dichiarati**. `suite-integrations.mjs` viene installato nel tratto post-app del bootstrap, dopo `js/suite.js`, così può consumare in modo esplicito i servizi Suite/Advanced/date-picker già pubblicati. Il networking condiviso resta in `suite-support.mjs`; per compatibilità con il controller precedente mantiene `error.code`, `error.payload`, status HTTP e il timeout predefinito di 25 secondi per i flussi integrazione.

`qa/p3_shell_decomposition_smoke.mjs` impone ora budget più stretti: **5.100 righe massimo per `js/app.js`** e **2.400 per `js/suite.js`**, e verifica che forecast/history/intelligence, device/session lifecycle e Radar/Netatmo non ricompaiano nei shell. I nuovi moduli partecipano a fingerprinting, dependency graph, DI, hardcode/security scan e precache PWA. La revisione Service Worker della fase 4 era `p3-domain-decomposition-4`; la revisione corrente è documentata nella sezione P3 finale.

**Stato P3 fase 4:** i shell sono ora sotto 5.000/2.400 righe e le aree forecast/history/intelligence, session/device lifecycle e integrazioni Suite hanno ownership ESM esplicita. Il P3 resta aperto per gli ultimi blocchi di orchestrazione ancora concentrati in `js/app.js`/Suite, ma il confine tra shell e domini è ormai verificato automaticamente dalla QA.


## 20.1 — P3 finale: shell applicativi ridotti e ownership UI esplicita

Il P3 si chiude con l’estrazione degli ultimi due blocchi ad alta responsabilità rimasti nei shell classici. `js/app.js` delega ora condivisione meteo, clipboard/share sheet e l’intero flusso bug-report/media a `modules/esm/domains/app-utilities.mjs`; `js/suite.js` delega parsing intent, risposta locale/AI, evidenza Copilot, cronologia e UI Assistant a `modules/esm/domains/suite-assistant.mjs`. Entrambi sono servizi ESM installati dal bootstrap e partecipano a dependency graph, DI, static analysis, hardcode/security scan, fingerprinting e precache PWA.

I nuovi moduli usano dependency injection reale: `app-utilities` dichiara `feedback` e `metrics`; `suite-assistant` dichiara `ai`, `auth`, `copilot`, `guestAccess`, `intelligence`, `metrics` e `security`. Non eseguono lookup di servizi nominati tramite `window` e non ricreano compatibility global. Nel review finale è stato inoltre corretto un residuo in `app-lifecycle.mjs`: i due rami di recovery che leggevano implicitamente `SERVICES` ora dichiarano e consumano esplicitamente la dipendenza `security`.

I shell scendono da circa **4.940 a 4.637 righe per `js/app.js`** e da circa **2.307 a 1.343 righe per `js/suite.js`**. `qa/p3_shell_decomposition_smoke.mjs` impone ora budget permanenti di **4.700** e **1.400** righe e rifiuta la reintroduzione delle implementazioni estratte. Il grafo applicativo comprende **28 moduli domain/feature ESM** e almeno **31 servizi dichiarati**; la revisione PWA corrente è `p3-final-shells-5`.

`qa/p3_final_architecture_smoke.mjs` chiude il milestone verificando budget, bootstrap, registry, dependency injection del lifecycle, documentazione e revisione Service Worker.

**Stato P3: COMPLETATO.** I prossimi interventi possono essere classificati come P4/ottimizzazione evolutiva; non è necessaria un’altra tranche P3 per raggiungere il target di manutenibilità definito in questo refactoring.


## 20.1 — P4 fase 1: chiusura gap roadmap, platform hardening e quality gates

Il P4 parte da un audit esplicito della roadmap architetturale originale, separando i milestone frontend P1/P2/P3 dai punti infrastrutturali che erano rimasti aperti. Questa fase non modifica API pubbliche, provider meteo o UX; lo schema era **26** in questa tranche e passa a **27** nel P4 finale per la data-migration i18n: chiude invece i gap più sensibili su deployment, CI e gestione errori.

### Stato della roadmap originale — snapshot storico aggiornato fino a P4 fase 3

| Priorità | Intervento originale | Stato |
| --- | --- | --- |
| P0 | eliminare admin QA hardcoded e fallback SMTP→admin | **COMPLETATO** |
| P0 | pin/hash asset JS terzi MapLibre | **COMPLETATO** |
| P1 | spezzare progressivamente `js/app.js` | **COMPLETATO** (shell sotto 4.700 righe) |
| P1 | un solo stato frontend / rimuovere bridge legacy | **COMPLETATO** |
| P1 | spezzare `database.php` | **COMPLETATO** |
| P1 | migration DB esplicite | **COMPLETATO in P4 fase 2**: revisioni 16→27 in file numerati; adapter SQLite legacy isolato solo per upgrade pre-registry |
| P1 | formattare PHP compressi | **COMPLETATO in P4 fase 2** per i file legacy ad alta densità; PHP-CS-Fixer ora copre l'intero `api/` per mantenere lo standard |
| P2 | ES modules + esbuild/Vite | **COMPLETATO in P4 finale**: esbuild 0.28.2 produce gli ESM production, poi il fingerprinting content-addressed pubblica gli artifact |
| P2 | riorganizzare CSS | **COMPLETATO in P4 fase 3**: `css/styles.css` e `css/suite.css` sono artifact generati da partial ordinati con cascade byte-equivalent e gate dedicato |
| P2 | chiavi i18n semantiche | **COMPLETATO in P4 finale**: 1.221 chiavi hash migrate a nomi semantici, con migration DB 27 e mappa di compatibilità |
| P2 | typed error codes installer/backend | **COMPLETATO**: installer con eccezioni tipizzate; tutti i failure `respond()` backend auditati espongono un `code` macchina stabile |
| P2 | CI MySQL più completa | **COMPLETATO**: auth + schema/migration/trigger integration su MySQL 8.4 reale |
| P2 | PHPStan / ESLint / Semgrep / Trivy | **COMPLETATO come toolchain/gate**; PHPStan parte da un perimetro backend conservativo da ampliare progressivamente |
| P3 | production Docker artifact minimale | **COMPLETATO** |
| P3 | container web non-root/read-only | **COMPLETATO** |

### Installer: errori tipizzati

`install/InstallerException.php` introduce `MeteoNexaInstallerException` con `errorCode`, `translationKey` e context separati. L'installer non deduce più il messaggio pubblico cercando sottostringhe italiane nell'eccezione: gli errori attesi usano codici stabili (`INSTALL_KEY_INVALID`, `INSTALL_REQUIREMENT_MISSING`, `INSTALL_SMTP_CONFIG_INVALID`, ecc.) e una chiave i18n esplicita. Gli errori imprevisti restano fail-safe su `install.error.generic` e vengono loggati server-side.

### Docker production hardening

Il `Dockerfile` non usa più `COPY . /var/www/html`: l'immagine contiene solo entry point web, `api/`, `assets/`, `dist/`, installer minimo e le due console amministrative. Test, sorgenti JS/CSS non necessari al runtime, documentazione, workflow e tool di build non entrano nel document root dell'immagine.

`web` e `worker` girano come uid/gid `33:33`, con filesystem root read-only, `no-new-privileges`, capability drop e tmpfs limitati. Il web conserva soltanto `NET_BIND_SERVICE` per Apache sulla porta 80. Lo stato persistente applicativo è nel bind mount host `./runtime:/var/lib/meteonexa`; log Apache vanno a stdout/stderr e l'entrypoint fallisce se lo storage runtime non è scrivibile dall'utente non-root.

### Quality/security toolchain e CI

Sono aggiunti `composer.json`, `phpstan.neon`, `config/quality/php-cs-fixer.php` e `config/quality/semgrep.yml`. La CI installa PHPStan/PHP-CS-Fixer, esegue ESLint + verifica esbuild, esegue Semgrep 1.169.0 con regole locali e Trivy `v0.36.0` sia sul filesystem sia sull'immagine Docker finale. La scansione Trivy blocca finding HIGH/CRITICAL non ignorati.

Il job MySQL 8.4 esegue ora sia `qa/mysql_auth_integration.php` sia `qa/mysql_full_integration.php`: quest'ultimo ricrea lo schema reale, verifica le 47 tabelle, attraversa la catena di upgrade da schema 15 fino allo schema **28** e applica i capability sync idempotenti e verifica i trigger di revisione traduzioni.

`qa/p4_platform_hardening_smoke.py` protegge questi contratti anche nella suite zero-dependency locale. `npm run check:p4` espone lo stesso gate.

**Stato P4 fase 1:** platform/security hardening completato. Restano come gap della roadmap originale soprattutto la normalizzazione delle migration/PHP legacy, la decomposizione CSS e la migrazione graduale delle 1.221 chiavi i18n opache verso nomi semantici.


## 20.1 — P4 fase 2: migration per revisione e normalizzazione PHP legacy

La seconda tranche P4 chiudeva i due gap backend rimasti nella roadmap originale senza modificare, in quella tranche storica, lo schema allora corrente **26**, le API pubbliche o il comportamento applicativo. Il P4 finale introduce successivamente lo schema 27 esclusivamente per la migrazione delle chiavi i18n.

### Migration DB esplicite

`api/database/migrations.php` non contiene più la sequenza DDL MySQL storica inline. È ora un registry che carica e valida una directory di revisioni numerate:

```text
api/database/migrations/
├── 0016_smart_alert_ledger.php
├── 0017_verified_trust_and_saved_locations.php
├── 0018_device_login_challenges.php
├── 0019_access_history.php
├── 0020_observation_and_nowcast_evidence.php
├── 0021_account_sync.php
├── 0022_industrial_weather_pipeline.php
├── 0023_notification_lifecycle_and_email_templates.php
├── 0024_verification_schema.php
├── 0025_trust_and_predictive_schema.php
├── 0026_product_metrics_and_current_metadata.php
├── 0027_semantic_i18n_keys.php
└── legacy_sqlite_upgrade.php
```

Ogni revisione dichiara `version`, `name`, driver supportati e callback `up`. Il registry rifiuta revisioni mancanti/non consecutive, driver sconosciuti e mismatch tra l'ultima migration e `meteonexa_current_schema_version()`. In quello snapshot MySQL attraversava il registry 16→27; SQLite conserva `legacy_sqlite_upgrade.php` esclusivamente come adapter quarantinato per database creati prima del contratto per-revisione. Le revisioni future devono essere file autonomi e, dalla revisione 27 in avanti vengono applicate anche a SQLite dal runner esplicito.

Le sincronizzazioni schema 24/25/26 e la data-migration i18n 27 sono raggiungibili dalla stessa catena versionata; i capability sync successivi in `connection.php` restano intenzionalmente idempotenti come self-heal runtime.

### PHP legacy leggibile

Sono stati normalizzati **42 file PHP** che nella baseline P4 fase 1 contenevano i blocchi più compressi, inclusi account/preferences/locations, Intelligence, calibration/pipeline, Netatmo, privacy, diagnostics, radar, push e helper DB. La trasformazione è stata eseguita con un formatter conservativo basato su `token_get_all()`: prima di scrivere ogni file viene confrontata la sequenza completa dei token PHP significativi, quindi una trasformazione che alteri codice/stringhe/operatori viene rifiutata. Dopo la normalizzazione l'intero backend viene nuovamente sottoposto a `php -l`.

Il gate `qa/p4_backend_maintainability_smoke.py` impedisce il ritorno del vecchio pattern dei grandi endpoint compressi (file >4 KB in ≤15 righe), verificava le 12 migration consecutive 0016→0027 e importa realmente il registry con PHP. `config/quality/php-cs-fixer.php` copre ora tutto `api/` oltre all'installer, non soltanto `api/database/`.

Comandi dedicati:

```bash
npm run check:p4:backend
bash qa/run-all.sh
```

**Stato P4 fase 2:** migration/versioning backend e normalizzazione del PHP compresso della roadmap originale sono completati. Restano aperti soprattutto decomposizione CSS, migrazione delle chiavi i18n opache e il passaggio da verifica esbuild a un vero artifact bundle, se si decide che il bundle unico porta un beneficio operativo rispetto agli asset ESM content-addressed correnti.


## 20.1 — P4 fase 3: architettura CSS sorgente e cascade deterministica

La terza tranche P4 chiude il gap CSS della roadmap originale senza modificare l'output visuale. I due fogli legacy più grandi restano disponibili con gli stessi nomi pubblici (`css/styles.css` e `css/suite.css`) per compatibilità con fingerprinting, Service Worker e test, ma non sono più la sorgente da modificare direttamente.

Le sorgenti autorevoli sono ora ordinate per responsabilità e prefisso numerico sotto `styles/main/` e `styles/suite/`:

```text
styles/
├── main/
│   ├── 00-foundation.css
│   ├── 10-welcome-auth.css
│   ├── 20-app-shell-weather.css
│   ├── 30-dialogs-privacy.css
│   ├── 40-atmosphere-design-system.css
│   ├── 50-product-layout.css
│   ├── 60-weather-responsive.css
│   ├── 70-runtime-visibility-components.css
│   ├── 80-location-radar-overrides.css
│   ├── 90-product-polish.css
│   └── 99-reliability-patches.css
└── suite/
    ├── 00-suite-foundation.css
    ├── 20-suite-responsive.css
    ├── 40-ui-regression-fixes.css
    ├── 50-route-layout.css
    ├── 70-settings-archive-assistant.css
    ├── 80-intelligence-controls.css
    └── 99-ux-reliability-patches.css
```

`tools/build_css.py` concatena esclusivamente i partial nell'ordine numerico: non riordina selettori, non minifica e non modifica specificità. La decomposizione iniziale è stata verificata byte-per-byte rispetto ai bundle precedenti, quindi la cascade resta identica. `tools/fingerprint_assets.py` rigenera sempre i bundle CSS prima di calcolare gli hash content-addressed.

`qa/p4_css_architecture_smoke.py` verifica l'equivalenza aggregate↔partials, l'assenza di `@import` runtime, l'ordine deterministico, il wiring del build e la documentazione. Il comando dedicato è `npm run check:p4:css`. I fogli feature già piccoli (`css/advanced.css`, `css/intelligence.css`, `css/light-theme.css`, ecc.) restano indipendenti e non vengono inglobati artificialmente nel bundle principale.

**Stato P4 fase 3:** il punto originale “riorganizzare CSS” è completato. Restano aperti nella roadmap originale la migrazione delle chiavi i18n opache verso nomi semantici e, se ritenuto utile operativamente, il passaggio dalla sola verifica esbuild a un artifact bundle production.


### Audit P0→P3 post P4 fase 3 (snapshot storico, superato dal P4 finale)

L'audit della roadmap originale era stato ripetuto sul codice di quello snapshot, non sulle sole etichette dei milestone. Il risultato è: **P0 completo, P1 completo, P3 completo; in P2 restano due soli gap reali**.

| Priorità originale | Voce | Audit corrente |
| --- | --- | --- |
| P0 | admin QA hardcoded / SMTP→admin | **COMPLETO** |
| P0 | pin/hash MapLibre | **COMPLETO** |
| P1 | decomposizione `js/app.js` | **COMPLETO** |
| P1 | singolo stato frontend | **COMPLETO** |
| P1 | decomposizione `database.php` | **COMPLETO** |
| P1 | migration esplicite | **COMPLETO** |
| P1 | PHP legacy leggibile | **COMPLETO** |
| P2 | ES modules | **COMPLETO** |
| P2 | esbuild/Vite come vero build artifact production | **PARZIALE**: esbuild bundla/verifica il grafo in CI, ma la release usa ancora ESM content-addressed e non il bundle generato |
| P2 | CSS riorganizzato | **COMPLETO** |
| P2 | chiavi i18n semantiche | **APERTO**: restano **1.221** chiavi legacy `ui.*` / `code.*` opache su 4.653 chiavi totali |
| P2 | typed error codes installer/backend | **COMPLETO** |
| P2 | CI MySQL reale | **COMPLETO** |
| P2 | PHPStan / ESLint / Semgrep / Trivy | **COMPLETO come quality/security gate**; l'espansione del perimetro PHPStan oltre il backend critico è miglioramento evolutivo |
| P3 | Docker production minimale | **COMPLETO** |
| P3 | web non-root/read-only | **COMPLETO** |

Durante l'audit è stato corretto anche `api/system/status.php`: il controllo health non confronta più lo schema con il valore storico `25`, ma con `meteonexa_current_schema_version()` (**27** allora corrente), e il failure DB espone ora `DATABASE_UNAVAILABLE` con chiave messaggio stabile.

**Conclusione di quello snapshot:** in P4 fase 3 restavano i18n semantica ed esbuild production; entrambi sono chiusi nel P4 finale descritto sotto.


## 20.1 — P4 finale: i18n semantica + esbuild production

Il P4 finale chiude gli ultimi due gap della roadmap originale.

### 1. 1.221 chiavi i18n opache migrate a chiavi semantiche

Le chiavi legacy nel formato `ui.<hash>` / `code.<hash>` sono state eliminate dai cataloghi attivi. Le **4.653 chiavi** di quello snapshot restavano complete in **5 lingue** (**23.265 traduzioni**), ma le 1.221 chiavi opache sono ora leggibili e organizzate per dominio/contesto. La generazione è deterministica e usa dominio runtime, funzione/elemento proprietario e copy inglese come base del nome.

La compatibilità con installazioni esistenti è gestita da `api/install/i18n-key-map-20.1.json`, che contiene esattamente le **1.221** corrispondenze old→new. La migration `api/database/migrations/0027_semantic_i18n_keys.php` rinomina in-place le righe `translations` su MySQL e SQLite, preservando traduzioni eventualmente personalizzate dagli operatori. A conclusione di quella milestone P4 lo schema era quindi **27** e `translation_seed_version` era `20.1-semantic-i18n-v1`; la successiva migration `0028_semantic_i18n_residual.php` porta il contratto corrente a schema **28** e seed `20.1-semantic-i18n-v2`.

`qa/p4_i18n_semantic_smoke.py` impone: zero chiavi hash nei cataloghi attivi/baseline/sorgenti runtime, 4.653 chiavi × 5 lingue, mapping di 1.221 chiavi univoche e presenza della migration 27. La mappa old→new è migration data e viene intenzionalmente esclusa dal divieto di riferimenti legacy.

### 2. esbuild è ora il builder production reale

`tools/esbuild-production.mjs` usa **esbuild 0.28.2** per compilare tutti i moduli sotto `modules/esm/` con `bundle: true`, `format: esm`, target `es2022`, tree-shaking e minificazione. MeteoNexa mantiene intenzionalmente più artifact ESM invece di forzare un singolo bundle: il bootstrap risolve i servizi tramite asset manifest e il code ownership per dominio resta ispezionabile. Il requisito del bundler è comunque reale perché i `.mjs` pubblicati vengono presi da `.build/esbuild-production/`, non copiati direttamente dai sorgenti.

Il percorso release è:

```text
sorgenti ESM
   ↓ esbuild 0.28.2
.build/esbuild-production/modules/esm/...
   ↓ fingerprint SHA-256
 dist/modules/esm/<nome>.<hash>.mjs
   ↓ asset-manifest
 runtime / Service Worker
```

`npm run build:production` esegue `build:esm` e poi il fingerprint con `METEONEXA_REQUIRE_ESBUILD=1`: se un artifact esbuild manca, il build release fallisce. Il `Dockerfile` è ora multi-stage (`node:20-bookworm-slim` → `php:8.3-apache`) e costruisce il frontend prima di copiare nell'immagine runtime soltanto gli artifact allowlisted. La CI esegue esplicitamente lo stesso `npm run build:production` prima dei security gate e del Docker build.

`qa/p4_production_build_smoke.py` protegge questo contratto. `tools/fingerprint_assets.py` conserva un fallback sorgente esclusivamente per i test/ambienti zero-dependency, ma il percorso production e Docker richiede espressamente l'output esbuild.

### Audit finale della roadmap originale P0→P3

| Priorità | Intervento originale | Stato finale |
| --- | --- | --- |
| P0 | eliminare admin QA hardcoded e fallback SMTP→admin | **COMPLETATO** |
| P0 | pin/hash asset JS terzi MapLibre | **COMPLETATO** |
| P1 | spezzare progressivamente `js/app.js` | **COMPLETATO** — ~11.303 → 4.637 righe |
| P1 | un solo stato frontend / rimuovere bridge legacy | **COMPLETATO** |
| P1 | spezzare `database.php` | **COMPLETATO** |
| P1 | migration DB esplicite | **COMPLETATO** — 0016→0028 |
| P1 | formattare PHP compressi | **COMPLETATO** |
| P2 | ES modules + build esbuild/Vite | **COMPLETATO** — ESM nativi + esbuild production |
| P2 | riorganizzare CSS | **COMPLETATO** |
| P2 | chiavi i18n semantiche | **COMPLETATO** — 1.317/1.317 hash-like migrate, 0 residue |
| P2 | typed error codes installer/backend | **COMPLETATO** |
| P2 | CI MySQL più completa | **COMPLETATO** |
| P2 | PHPStan / ESLint / Semgrep / Trivy | **COMPLETATO come quality/security gate** |
| P3 | production Docker artifact minimale | **COMPLETATO** |
| P3 | container web non-root/read-only | **COMPLETATO** |

**Stato roadmap originale: COMPLETATA.** Non risultano gap aperti P0→P3 rispetto alla lista iniziale. Restano naturalmente miglioramenti evolutivi possibili (copertura PHPStan più ampia, riduzione ulteriore dei shell, E2E più estesi, performance budget), ma non sono debiti residui della roadmap originaria.



## Documento consolidato da `ARCHITECTURE.md`

# MeteoNexa Architecture — 20.1

## Release Candidate 20.1 — performance critical path

La RC mantiene il service registry/DI P3-P5 ma distingue il caricamento in tre livelli: Core + `PRE_APP_ESM` necessari a `js/app.js`, `SUITE_ESM` caricati dopo il bootstrap principale, e feature post-app. I CSS delle feature non sono render-blocking e vengono applicati dopo il primo paint. Il Service Worker separa `CRITICAL_SHELL` da `OPTIONAL_SHELL`; quest'ultima viene warmata in idle dall'app con concorrenza limitata. `visualization` dipende esplicitamente da `radarMotion`/`radarController`, senza service locator impliciti.

Budget RC: pre-app ESM sorgente <= 450 KB; CSS render-blocking sorgente <= 450 KB. Questi limiti sono protetti da `qa/release_candidate_smoke.py`.

## Architectural principles

MeteoNexa uses a server-authoritative weather architecture. The browser renders normalized evidence and sends user intent; provider selection, model normalization, confidence, nowcast authority, route evaluation, alert decisions and critical guardrails remain server-side. A shared event/store layer coordinates frontend modules without creating parallel weather engines.

The production data layer is MySQL; SQLite is the local/development baseline and rollback-compatible seed. Both expose schema version 28 and the same 47 application tables. Translation first paint uses a packaged static locale catalog with no PHP, database, session or cookie-acknowledgement dependency; the authoritative server catalog and operator overrides reconcile in background after the interface is already usable.

## Weather provider orchestration

`api/intelligence/quality_helpers.php` is the canonical six-model provider definition. IFS, AIFS, ICON, GFS, Météo-France and UKMO are fetched through server-owned orchestration with bounded timeouts, caching, stale handling and normalized evidence. Home, Intelligence and Advanced views consume the same canonical server consensus. Skill-weighted consensus is retained separately for internal decision support.

The frontend does not fetch the six forecast families directly. Expected model count is derived from the provider definition rather than a hardcoded denominator.

## Confidence, verification and local trust

Confidence is computed from the evidence actually available and cannot gain score because a model is missing. Verification storage supports temperature/rain/storm skill, forecast-run stability, radar ETA verification, predictive alert verification and decision verification. Public Local Accuracy exposes only aggregated metrics that pass minimum sample/contributor thresholds; user or device identifiers are never returned by the public endpoint.

## Radar and probabilistic nowcast

Radar3 is fail-closed. Before sufficient comparable verification history exists it remains probationary and Radar2 stays authoritative for ETA, critical push decisions and other guarded actions. Promotion requires the configured sample threshold and non-regression against the Radar2 benchmark; regression automatically restores the safer authority.

Probabilistic Nowcast combines the authoritative radar path with lightning, satellite, observations, model consensus, convective evidence, severe outlook and official alerts. Rain/storm/gust outputs use explicit probability semantics; hail remains a potential index rather than a falsely precise probability. ETA distributions are exposed only when the underlying track satisfies confidence and freshness guardrails.

## Decision Timeline and forecast change

Decision Timeline is a deterministic weather-only suitability layer for supported activity profiles. It consumes normalized forecast/nowcast evidence and produces hourly score, dominant risk, confidence, best window and alternative window. It does not infer traffic, trail state, road closure, sea-state observations or other unavailable facts.

Forecast Change compares stored forecast snapshots and emits only material changes: meaningful timing shifts, precipitation-probability changes, model-agreement changes or event-type changes. Small numerical noise is intentionally suppressed.

## Route Weather

`api/route/weather_engine.php` is the shared route evaluation engine. A route is sampled into bounded points, weather is evaluated server-side, crosswind/precipitation/visibility/icing/thunder risks are normalized and candidate departure windows are compared. The browser route page and AI orchestration both use this same engine.

Route coordinates are operational input to the MeteoNexa weather engine. The route analysis returned to AI contains only decision evidence such as risk, score, timing and critical-segment ratio/label; sampled coordinates are excluded from the language-model payload.

## AI Weather Copilot and Route/Decision orchestration

The Copilot is an explanation/orchestration layer, not a second meteorological brain.

`api/ai/orchestrator.php` resolves the user intent, selects required deterministic tools and combines:

- current forecast and canonical six-model consensus;
- confidence and local skill evidence;
- probabilistic nowcast when relevant;
- Decision Timeline and material forecast-change evidence;
- official warnings, severe/convective context and aggregate local trust evidence;
- Route Weather with wind/gust/crosswind evidence when a route intent is present.

The orchestrator first produces a deterministic decision object (`good`, `caution`, `avoid` or `learning`) with score, confidence, dominant risk, best/alternative window and route evidence. Only then may the configured language model explain that object. The system prompt explicitly forbids replacing deterministic decisions or inventing data, sources, ETA or risks.

The client displays the answer together with a compact evidence block, so score/confidence/route risk/source count remain inspectable instead of being hidden inside prose.

## Watch My Plan

Watch My Plan stores a bounded plan record in the account synchronization state: activity, saved-location reference, scheduled time, duration and evaluation state. It does not duplicate the saved location coordinates or accept free-form plan text. The worker reevaluates only plans entering the configured horizon, establishes a baseline first and notifies only on material deterioration or recovery, with cooldown protection.

## Product Metrics — storico

Product Metrics era first-party e aggregate-only nelle release precedenti. Nella revisione corrente il client, l’endpoint e il caricamento runtime sono rimossi: non vengono più inviati eventi di utilizzo. La struttura DB storica può restare presente durante gli upgrade di schema per compatibilità, ma non è letta o scritta dal runtime corrente.

## Traffic analytics

Il traffic analytics esterno è stato rimosso dalla 20.1 RC2 il 19 settembre 2026. Il runtime non carica SDK/script analytics e la CSP non consente più `plausible.io`.

## Runtime state and database boundaries

The frontend has one authoritative mutable runtime state, created by `modules/esm/core/runtime-state.mjs`. `modules/esm/core/store.mjs` attaches to that object and exposes immutable projected domain views/events; it no longer keeps a mirrored legacy snapshot or requires explicit synchronization calls. Infrastructure helpers that do not own business state, such as weather/location formatting and tooltips, live in dedicated core modules rather than in `js/app.js`.

`modules/esm/core/i18n-preferences.mjs` owns translation catalog rebasing, DOM translation/observation and preference synchronization while mutating the same authoritative runtime state. Account synchronization and authenticated account hydration live in `modules/esm/domains/account.mjs`; the email/OTP/trusted-device interaction flow lives in `modules/esm/domains/auth-flow.mjs`. Predictive radar image sampling and motion/ETA analysis live in `modules/esm/domains/radar-motion.mjs`. MapLibre lifecycle, live/forecast frame loading, fallback/recovery, camera synchronization, refresh/playback and radar page location controls live separately in `modules/esm/domains/radar-controller.mjs`. These modules receive their runtime dependencies explicitly from the bootstrap, so extraction does not create a second state container or hidden service locator.

Location discovery, reverse geocoding, city search, recent locations, favorites and location switching live in `modules/esm/domains/locations.mjs`. Navigation policy now lives with the navigation domain: `modules/esm/domains/navigation.mjs` owns guest/auth page policy, feature visibility, UI-config hydration and page transitions in addition to the navigation event contract. Cross-domain work (for example entering Radar or refreshing History) is supplied as explicit callbacks by the bootstrap, keeping `js/app.js` as an orchestrator rather than the owner of those domains.

Notification delivery and PWA capability now live in `modules/esm/domains/notifications.mjs`. The domain owns browser capability/permission detection, Notification Center rendering, authenticated inbox operations (`api/push/inbox.php`), local weather notification candidates, application badges, Service Worker notification delivery and the install/update lifecycle. Notification-specific UI event registration is also encapsulated there through `bindNotificationEvents()`. `js/sw.js` remains the infrastructure worker; `js/app.js` only creates the domain with explicit callbacks and starts its lifecycle. This closes the P1 bootstrap decomposition while retaining one authoritative runtime `state`.

P2 phase 1 introduced the explicit compatibility boundary; **P2 phase 2 converts that boundary to native ES modules**. `modules/esm/core/service-registry.mjs` exports the closed service locator and `modules/esm/core/runtime-api.mjs` exports the immutable cross-bundle Runtime API host. Their implementations are module-scoped; compatibility globals are installed only so the not-yet-migrated classic domains can continue to operate during the transition. The former `modules/core/service-registry.js` and `modules/core/runtime-api.js` sources no longer exist.

**P2 phase 3 moves the application core itself into native ES modules.** `store.mjs`, `runtime-state.mjs`, `tooltips.mjs`, `weather-utils.mjs` and `i18n-preferences.mjs` now live under `modules/esm/core/`; their former `modules/core/*.js` implementations are removed. Each core module exports its authoritative API plus an `install*()` function that publishes into the internal service registry. Compatibility globals are no longer created by default. The bootstrap installs the ESM core first and then installs the ESM domain graph in a deterministic order.

`modules/esm/bootstrap.mjs` is the native `type="module"` entry point. It resolves fingerprinted module URLs through the no-cache first-party asset manifest, installs the ESM boundaries/core, installs the ESM domains, loads the remaining classic application shells in their required order, and installs post-app ESM features at the matching lifecycle points. `js/app.js`, `js/advanced.js`, and the Suite date-picker bootstrap are safe whether they load before or after `DOMContentLoaded`.

P2 closes the transitional boundary: the service registry owns service storage internally and `publish()` does not create compatibility globals. `exposeLegacy()` is available only for an explicit exceptional bridge. Core, domains and features are ESM-authoritative; remaining classic top-level shells consume them through `MeteoNexaServices`/Runtime API rather than named globals.

The repository has two complementary quality layers. `tools/static-analysis.mjs` remains the zero-dependency release gate; ESLint 10 is configured for migrated ESM sources and esbuild is pinned as a development-only bundlability verifier. Neither ESLint nor esbuild is a browser/runtime dependency. `tools/fingerprint_assets.py` remains the immutable asset builder, and `.mjs` files participate in the same content-addressed `dist/` contract as first-party JS/CSS.

`api/database.php` is a stable facade only. Database concerns are split under `api/database/` into driver, crypto, schema synchronization, ordered migrations, connection/bootstrap, metadata, SMTP, AI settings and security-state maintenance. `api/database/migrations.php` owns the current schema version and historical upgrade path; connection code must not grow new inline schema revisions.

## Frontend modules and responsive contract

Frontend capabilities live under `modules/esm/domains/` and `modules/esm/features/`. Feature files use semantic names rather than release-numbered filenames. Intelligence articles reuse the common `glass-panel`/`panel-title` design contract and established responsive breakpoints. Copilot evidence is integrated in the existing assistant surface rather than introducing a visually separate article family.

Static JS/CSS is immutable and content-addressed under `dist/`. `tools/fingerprint_assets.py` rebuilds the manifest, rewrites public/administrative surfaces and generates the service-worker-safe subset. Logical source names remain readable in the repository.

MapLibre is an exception to the application-owned `dist/` pipeline because it is a third-party runtime dependency. The same-origin `api/vendor-asset.php` gateway resolves MapLibre only from a version-pinned npm package whose complete tarball must match a hardcoded SHA-512 integrity value before any executable entry is cached or served. Remote GeoJSON datasets use the same gateway as data only and are structurally validated rather than executed.

## Privacy and external services

Precise location is processed only where required for weather/route functionality. Browser-to-provider fan-out is avoided for the multi-model forecast path. Account, device and session data are kept out of public accuracy responses. User-behaviour Product Metrics are disabled/removed in the current runtime. AI provider payloads are minimized and exclude sampled route coordinates.

## Release contract

Current runtime contract:

- application: `20.1`;
- schema: `28`;
- translation seed: `20.1-semantic-i18n-v2`;
- production DB: MySQL;
- local baseline: SQLite;
- QA: current functional/regression gates only, with semantic names and no historical release gates in the runtime package.


## P2 final — ESM domains/features and internal service registry

All domain and feature implementations are authoritative `.mjs` files. Import is side-effect free; `bootstrap.mjs` invokes each module's `install()` explicitly. P3 phase 2 removes the per-module compatibility access entirely: domain/feature factories receive the real browser host only for Web/DOM APIs, a lazy `deps` view restricted to declared dependencies, and a `provided` view restricted to declared services. No domain/feature module reads or writes named `window.MeteoNexa*` services.

The only application-wide service boundary exposed to the main page is `window.MeteoNexaServices`. `js/app.js`, Advanced, Suite, Weather Intelligence, Product Metrics and Custom Controls may not access named `window.MeteoNexa*` services directly. Foundational pre-bootstrap runtimes (security/i18n) and standalone analytics are documented exceptions. The historical P2 final Service Worker shell revision was `p2-esm-services-3`; the current revision is documented in the latest P3 section below.


## P3 phase 1 — explicit service graph

P3 centralizes the transitional domain installer in `modules/esm/core/service-registry.mjs`. Domain/feature modules no longer duplicate Proxy/capture/publish boilerplate. Each module exports immutable `serviceNames` and `dependencies`; the latter documents lazy runtime consumers even when the concrete UI service is published later in the boot sequence. `qa/p3_dependency_graph_smoke.mjs` validates the graph, known service names and unique providers. This keeps the compatibility bridge in one infrastructure location and makes future direct dependency injection incremental and measurable.

## P3 direct dependency injection

`service-registry.mjs` is the single composition boundary for migrated modules. `installModule()` validates `provides` and `dependencies`, creates a lazy dependency view backed by the internal registry and accepts only declared writes through the provided-service view. This preserves late-bound UI services without a compatibility window and makes undeclared service publication a hard error. `qa/p3_dependency_injection_smoke.mjs` enforces this boundary across all domain/feature ESM modules.

## P3 shell decomposition

P3 phase 3 treats `js/app.js` and `js/suite.js` as composition shells rather than permanent homes for reusable implementation. `visualization.mjs` owns the chart interaction/rendering engine, weather canvas effects and trend/motion chart primitives; `js/app.js` injects only the runtime context required by that service. `suite-support.mjs` owns Suite's shared API/storage/network/locale/date/geospatial support and is instantiated by `js/suite.js` with Runtime API and security dependencies.

Both services are registered through the same `installModule()` graph used by the other domains. The shell budget is enforced by `qa/p3_shell_decomposition_smoke.mjs` so future work cannot silently move reusable implementation back into the classic entry files. The P3 phase 3 PWA shell revision was `p3-shell-decomposition-3`; the current revision is documented in the latest P3 section below.

## P3 phase 4 — domain decomposition and post-app integrations

P3 phase 4 moves forecast/history, model-intelligence, device/session lifecycle and global app lifecycle out of `js/app.js`, and moves the second Suite integration controller out of `js/suite.js`. The authoritative modules are `forecast-history.mjs`, `model-intelligence.mjs`, `device-sessions.mjs`, `app-lifecycle.mjs` and `suite-integrations.mjs`.

`bootstrap.mjs` installs the first four as pre-app services and installs `suiteIntegrations` after `js/suite.js` has published the Suite, Advanced and date-picker services. The latter therefore consumes late-bound dependencies through the same declared DI graph rather than relying on named globals or script-order side effects. Shared Suite transport/formatting remains in `suite-support.mjs`; integration requests preserve structured API errors (`code`, `payload`, HTTP status) and the former integration timeout contract.

The service graph now contains 26 ESM modules and 29 provided services. `qa/p3_shell_decomposition_smoke.mjs` enforces shell budgets of 5,100 lines for `js/app.js` and 2,400 lines for `js/suite.js` and rejects reintroduction of the extracted implementations. Current shell sizes are approximately 4,940 and 2,307 lines respectively. The P3 phase-4 shell revision was `p3-domain-decomposition-4`; the current RC revision is `rc1-runtimefix-12-lifecycle-guest`.


## P3 final — thin classic shells and explicit UI ownership

P3 closes with `app-utilities.mjs` owning weather sharing plus bug-report/media workflows and `suite-assistant.mjs` owning the Suite local/AI assistant, Copilot evidence rendering and assistant session UI. `js/app.js` and `js/suite.js` are now composition shells with enforced budgets of 4,700 and 1,400 lines respectively (current sizes about 4,637 and 1,343).

Both services use the normal `installModule()` dependency contract; Suite Assistant consumes `ai`, `auth`, `copilot`, `guestAccess`, `intelligence`, `metrics` and `security`, while App Utilities consumes `feedback` and `metrics`. No named MeteoNexa compatibility global is introduced. The final review also removed the last implicit `SERVICES` reference from `app-lifecycle.mjs`; `security` is now a declared dependency. The P3-final shell revision was `p3-final-shells-5`; the current RC revision is `rc1-runtimefix-12-lifecycle-guest`.

**P3 status: COMPLETE.** Future decomposition is optional/evolutionary rather than required to establish the service graph, DI boundary, ESM ownership and thin-shell maintenance model.


## P4 phase 1 — production platform boundary

The production container is now an allowlisted runtime artifact instead of a copy of the repository. Only public entry points, `api/`, `assets/`, fingerprinted `dist/`, the minimal installer and the protected QA/Diagnostics web entry points are copied into `/var/www/html`. Source tooling, test runners, repository metadata and documentation remain outside the image.

Apache and the worker run as uid/gid 33 with a read-only root filesystem. Runtime mutability is restricted to the documented host bind mount `./runtime:/var/lib/meteonexa` and bounded tmpfs mounts. Containers enable `no-new-privileges`; Linux capabilities are dropped, with only `NET_BIND_SERVICE` retained by the web service.

Installer failures now cross a typed boundary (`MeteoNexaInstallerException`) carrying a stable machine code and an explicit translation key. Public installer copy is no longer selected by parsing exception-message text.

CI has distinct release, real-MySQL, quality/security and browser gates. The MySQL gate validates auth plus the full schema/migration/trigger path on MySQL 8.4. The quality/security gate adds PHPStan, ESLint/esbuild verification, Semgrep local rules and Trivy scans of both source configuration and the final production image.


## P4 phase 2 — explicit migrations and PHP maintainability

At the historical P4 phase-2 milestone, database schema evolution had become version-file driven for revisions 16 through 26. `api/database/migrations.php` is the registry/runner; revision descriptors live under `api/database/migrations/NNNN_*.php`. The registry validates contiguous versions and supported drivers before execution. SQLite databases that predate the version-file contract use `legacy_sqlite_upgrade.php` as a quarantined compatibility adapter up to revision 26; future revisions are expected to use the explicit registry on both drivers.

Large minified/packed PHP endpoints have been expanded into reviewable physical structure without changing semantic PHP tokens. `qa/p4_backend_maintainability_smoke.py` prevents a return to large endpoints compressed into a handful of lines, and PHP-CS-Fixer is scoped to the complete `api/` tree plus installer code.


## P4 phase 3 — CSS source architecture

`css/styles.css` and `css/suite.css` are compatibility build artifacts, not editing surfaces. Authoritative CSS lives in ordered partials under `styles/main/` and `styles/suite/`. Numeric prefixes encode the existing cascade order; `tools/build_css.py` concatenates them without reordering, minification or selector rewriting. `tools/fingerprint_assets.py` invokes the CSS build before content hashing, so public immutable asset semantics remain unchanged.

`qa/p4_css_architecture_smoke.py` enforces exact aggregate/partial equivalence, deterministic ordering and the no-runtime-`@import` rule. Smaller feature/theme stylesheets remain independent layers.

## P4 final — semantic i18n and production frontend build

At the historical P4-final milestone, the active translation catalog contained 4,653 semantic keys across five locales. The 1,221 former `ui.<hash>` / `code.<hash>` identifiers are retained only in `api/install/i18n-key-map-20.1.json` as migration input. Schema revision 27 (`0027_semantic_i18n_keys.php`) migrates existing translation rows in place so operator-edited copy is preserved.

Production ESM assets are built by pinned esbuild 0.28.2 into `.build/esbuild-production/` and then content-addressed by `tools/fingerprint_assets.py`. `npm run build:production` requires these generated assets. Docker uses a Node frontend-build stage and copies only the resulting allowlisted runtime surface into the non-root PHP image. Source-copy fingerprinting remains only as a zero-dependency QA/development fallback.

**Original P0→P3 roadmap status: COMPLETE.**


## P5 audit correction and startup performance

The current release contract is schema **28** with `20.1-semantic-i18n-v2`. A second semantic migration (`0028_semantic_i18n_residual.php`) removes the 96 hash-like `html.*`, `attr.*` and `meta.*` keys that were not covered by the original `ui/code` matcher. Active catalogs now contain **4,639 semantic keys in five locales** and the QA rule rejects any retained migration-key reference in runtime sources.

Privacy/legal deployment identity is explicit configuration, never hardcoded application identity: controller legal name, address, privacy mailbox and optional DPO mailbox are safe public fields exposed by `api/ui-config.php`. Missing controller identity is rendered as a localized deployment warning. The configured external AI provider is exposed only as the non-secret provider name so the privacy page can show the matching provider notice.

Bootstrap networking is optimized without weakening deterministic initialization: pre-app ESM modules are imported concurrently with `Promise.all`, then their `install()` functions are awaited in the declared graph order. This removes the serial module-fetch waterfall while preserving service side effects and dependency ordering.



## Documento consolidato da `SECURITY.md`

# MeteoNexa Security — 20.1

## Security model

MeteoNexa treats the server as the authority for authentication, weather-provider orchestration, deterministic alert/decision logic, AI orchestration, account synchronization and persistent state. Client checks improve UX but never replace server authorization or validation.

## Authentication and sessions

Email authentication uses server-generated challenges, rate limiting and protected fallback handling. Session/trusted-device state is bounded and revocable. Sensitive values use deployment-secret-derived authenticated encryption where persistence is required. A deployment-key verifier prevents silently combining an existing protected database with the wrong application secret.

QA/Diagnostics authorization is server-side. Privileged identities come only from the deployment-owned `METEONEXA_QA_ADMIN_EMAILS` allowlist and deployment-bound HMAC identifiers already provisioned in `app_metadata`. SMTP username/from-address values are never interpreted as administrators. Administrative pages reuse the authenticated session and do not create a separate privileged browser credential.

## Same-origin, CSRF and request validation

State-changing APIs enforce the expected HTTP method, same-origin/CSRF controls where applicable, payload bounds and rate limits. Public/aggregate endpoints use explicit allowlists and reject unexpected fields. Account-only functions require an authenticated device session.

## AI boundary

AI Weather Copilot never becomes the authority for meteorological or safety-critical decisions. `api/ai/orchestrator.php` creates deterministic evidence and a deterministic decision before any language-model request. The provider receives only the minimized explanation context required for the response.

Structured Copilot evidence may include public warning summaries, convective-risk output, aggregate local trust metrics and route wind/crosswind values. No account/device identifiers are added to that evidence. Sampled route coordinates are intentionally excluded from the language-model payload. The system instruction requires the model to explain the supplied decision, not override it or invent weather values, sources, ETA or risk. API keys are server-side encrypted configuration and are not embedded in frontend assets.

## Route and location boundary

Route coordinates are accepted with strict count/shape/range limits and processed by the shared server weather engine. Returned AI route evidence contains risk/score/timing/critical-segment descriptors, not the sampled coordinate list. Saved plans reference an existing saved location rather than duplicating coordinates into a new record.

## Radar/nowcast safety guardrails

Radar3 promotion is evidence-gated. Insufficient comparison history leaves Radar3 probationary and keeps Radar2 authoritative. Stale radar, weak tracks or verification regression fail closed. Probabilistic outputs are bounded to valid ranges and hail potential is not labelled as calibrated probability.

## Data minimization

Il runtime corrente non raccoglie Product Metrics né pageview analytics. Plausible, il modulo analytics client e l’endpoint Product Metrics sono rimossi dalla superficie runtime; restano soltanto log e metriche tecniche necessarie ad affidabilità, sicurezza e verifica meteorologica. Public Local Accuracy restituisce esclusivamente statistiche aggregate sopra le soglie di pubblicazione e non espone identità dei contributor.

### Analytics utente

Nessun servizio di traffic analytics è caricato dal browser e nessun evento di utilizzo viene inviato a Plausible o a un endpoint Product Metrics. Il CSP corrente non include origini analytics esterne. Eventuali strutture DB storiche restano solo per compatibilità di upgrade e non vengono lette/scritte dal runtime corrente.

Watch My Plan stores only activity, saved-location reference, schedule/duration and evaluation state. Free-form plan text is not persisted. Notification changes are materiality/cooldown guarded to avoid noisy behavioral profiling.

## Database and secrets

Production uses MySQL; SQLite is intended for local/development baseline use. Schema self-healing is additive and current-capability based rather than release-number based. SMTP, AI-provider and integration secrets are never stored in plaintext when persisted. SQL parameters are bound; internal dynamic table/order identifiers are restricted to whitelists.

## Content Security Policy and browser surfaces

Browser pages and administrative surfaces use the configured security headers appropriate to their role. External resources are explicitly bounded. Fingerprinted static assets are immutable and manifest-validated so stale HTML cannot silently execute an unexpected MIME/type response. The packaged first-paint locale catalogs contain public translation copy only: no account, session, location or secret material is embedded in them, and loading them requires no credentials.

The main browser CSP keeps `script-src 'self'` and `connect-src` limited to same-origin plus the explicitly allowlisted weather/map providers. No Plausible or other analytics origin is present in the current CSP.

MapLibre executable assets remain same-origin to the browser, but are no longer trusted merely because a fixed CDN URL returned plausible JavaScript/CSS. `api/vendor-asset.php` fetches the pinned `maplibre-gl@5.24.0` npm package, verifies the complete package against its fixed SHA-512 npm integrity value, and only then reads the exact `dist/maplibre-gl.js` / `dist/maplibre-gl.css` entries into protected runtime cache. Cache filenames changed so assets created by the older CDN-only path cannot be reused after this hardening. The installer checks `PharData` and `zlib`, which are required to read the verified package archive. GeoJSON remains a non-executable remote-data path with strict structure and size validation.

## Logging and observability

Operational logging avoids plaintext credentials and minimizes personal data. Runtime metrics and verification tables have bounded retention/pruning paths. Security events are used to detect failures without turning technical logs into a permanent user activity profile.

## QA security contract

The packaged QA suite validates current security/privacy/runtime invariants only. Test names are semantic rather than tied to previous application releases. The suite includes syntax/lint, database integrity/parity, authentication/security smoke tests, provider/model consistency, Radar probation, nowcast/decision regressions, no-analytics enforcement, Watch My Plan safeguards, AI orchestration payload minimization and asset fingerprint integrity.

Browser E2E remains configured for Chromium and Firefox in CI, where browser installation and local HTTP navigation are available.


## P4 deployment hardening

The production Docker image is built from an explicit runtime allowlist; the repository is not copied wholesale into the Apache document root. The web and worker containers run as uid/gid 33, use a read-only root filesystem, enable `no-new-privileges`, drop Linux capabilities and persist mutable application state only in the dedicated runtime volume. Apache logs are emitted to container stdout/stderr. The web service retains only `NET_BIND_SERVICE` to bind port 80.

Installer-visible failures use typed machine codes and explicit i18n keys. Unexpected exceptions degrade to the generic public installer error instead of exposing or classifying raw exception text.

CI security gates now include PHPStan, ESLint/esbuild graph verification, local Semgrep rules and Trivy HIGH/CRITICAL scanning of the repository configuration and built production image. MySQL 8.4 integration covers the full schema/migration/trigger path in addition to authentication portability.

## P4 final release-build integrity

Production Docker builds compile ESM through pinned esbuild 0.28.2 before SHA-256 fingerprint publication. `METEONEXA_REQUIRE_ESBUILD=1` makes release fingerprinting fail closed when a compiled module is missing. The runtime image receives only artifacts from the frontend build stage and the existing explicit allowlist.

Translation schema revisions 27 and 28 use packaged old→new maps (1,221 primary keys plus 96 residual hash-like keys) to rename legacy opaque i18n identifiers without discarding existing operator-customized translations. Active catalogs now reject hash-like translation identifiers across all prefixes, not only `ui.*` / `code.*`.


## Privacy/legal deployment identity

The application does not hardcode or infer the legal identity of the data controller. Production operators configure `METEONEXA_LEGAL_CONTROLLER_NAME`, `METEONEXA_LEGAL_CONTROLLER_ADDRESS`, `METEONEXA_PRIVACY_CONTACT_EMAIL` and, where applicable, `METEONEXA_DPO_EMAIL`. `api/ui-config.php` exposes only these public contact fields plus a non-secret AI-provider name; credentials are never returned. The privacy page uses text-only DOM assignment for operator-provided identity values and shows a localized configuration warning if the required controller identity is incomplete.



## Documento consolidato da `RC-AUDIT.md`

# MeteoNexa 20.1 RC2 — audit conclusivo

## Verdetto

**RC2 pronta per staging/collaudo.** Il GO production resta bloccato finché CI completa, Playwright Chromium/Firefox, restore drill, test reali auth/SMTP/push/worker, security live e configurazione legale/privacy non risultano verdi.

## Architettura

- schema corrente 28 / 47 tabelle SQLite-MySQL;
- ESM + internal service registry + dependency injection;
- singolo runtime state frontend;
- migration per revisione fino a 0028;
- CSS sorgente in partial deterministici, bundle compatibility fingerprintati;
- Docker production allowlisted, non-root/read-only;
- RC: Suite-only ESM fuori dal pre-app critical path;
- RC: bug `visualization`/Radar corretto con DI esplicita e API restituita ad `js/app.js`.

## Security

QA security/hardening verde: session cookie HttpOnly/Secure/SameSite, trusted-device server-side, same-origin/CSRF guards, upload policy, rate limit, CSP/HSTS, MapLibre integrity, admin QA esplicito, secret/hardcode scan, Semgrep/Trivy/PHPStan/ESLint configurati in CI.

Nessun secret o admin privilegiato hardcoded rilevato dai gate di release.

## Privacy / Cookie

- Privacy Article 13 smoke: PASS;
- controller legale/address/privacy email sono configurazione deployment, non valori inventati/hardcoded;
- DPO opzionale;
- provider AI disclosure dinamica;
- Cookie Policy alignment: PASS;
- cookie runtime previsti: sessione auth, trusted device, client tecnico e lingua; nessun cookie pubblicitario/profilante introdotto;
- analytics utente rimossi: nessun Plausible runtime e nessun Product Metrics; restano soltanto metriche tecniche di affidabilità/sicurezza.

## Traduzioni / hardcode UI

- 4.645 chiavi × 5 lingue = 23.225 traduzioni;
- 0 chiavi hash-like attive;
- i18n reference smoke: PASS;
- nuovo `i18n_hardcoded_copy_smoke.py`: PASS su HTML e assegnazioni UI JS;
- policy alignment: PASS.

## Performance RC

- PRE_APP_ESM sorgente: ~499,8 KB → ~421,2 KB;
- CSS render-blocking sorgente: ~618 KB → ~420 KB (+ MapLibre CSS rimosso dal blocking path);
- js/app.js preloaded;
- feature CSS dopo first paint;
- MapLibre JS defer;
- Service Worker installa CRITICAL_SHELL e scalda OPTIONAL_SHELL in idle, 4 richieste concorrenti max;
- asset content-addressed riusano HTTP cache durante warmup.

## QA finale

`bash qa/run-all.sh` → **MeteoNexa 20.1 QA PASS**.

Include release audit, immutable asset contract, P0-P5, RC smoke, privacy/cookie, hardcode, i18n, auth/trusted device, Radar/Nowcast, AI/Copilot/Route, SQLite/MySQL contract e mobile regressions.

## Limiti di verifica del sandbox

- il PHP built-in server serve `/` con HTTP 200;
- `api/system/status.php` risponde 503 nel sandbox privo di DB runtime configurato (atteso);
- Chromium headless del sandbox non completa la navigazione loopback, quindi Playwright/browser E2E va eseguito in CI/staging;
- `package-lock.json` non è stato generato perché il registry npm non è raggiungibile dal sandbox. Le versioni top-level sono pinate; il lockfile resta raccomandato prima del GO production.



## Documento consolidato da `RELEASE-NOTES-RC1.md`

# MeteoNexa 20.1 RC1 — Release notes



## RC1 ESM bootstrap hotfix

- pubblicazione sincrona dei servizi `uiVisibility` e `guestAccess` durante l'installazione del modulo navigation, eliminando `METEONEXA_MODULE_SERVICE_NOT_PUBLISHED`;
- facade stabili idratate dal controller applicativo dopo il caricamento di `js/app.js`;
- badge login esplicito `v20.1 RC1` senza cambiare `app_version`/cache build semantica;
- revisione service worker `rc1-runtimefix-12-lifecycle-guest` per forzare il refresh della shell corretta.

## Obiettivo

RC1 consolida la roadmap P0→P5 in un candidato da provare in staging e, dopo i gate GO, rilasciare in produzione. Non introduce nuove tabelle o nuove categorie di dati: lo schema resta **28 / 47 tabelle**.

## Correzione bloccante trovata nell'audit RC

Durante il controllo runtime pre-release è emerso che `visualization.mjs` conservava un riferimento implicito a `SERVICES` e costruiva le funzioni Radar senza restituirle ad `js/app.js`. La sintassi era valida e i vecchi smoke test non lo rilevavano, ma in browser poteva causare `ReferenceError` nel percorso Radar/bootstrap.

RC1 corregge il contratto con DI esplicita (`radarMotion`, `radarController`) e binding esplicito delle funzioni Radar. `qa/release_candidate_smoke.py` protegge la correzione.

## Performance percepita

- Suite-only ESM rimossi dal critical path pre-`js/app.js`: circa **499,8 KB → 421,2 KB** sorgente pre-app.
- CSS render-blocking: circa **618 KB → 420 KB** sorgente, più MapLibre CSS rimosso dal percorso bloccante.
- `js/app.js` viene preloaded mentre Core/ESM vengono installati.
- feature CSS caricati dopo il primo paint.
- MapLibre JS parser-deferred.
- Service Worker: `CRITICAL_SHELL` all'installazione, `OPTIONAL_SHELL` warmata in idle con massimo 4 richieste concorrenti.
- asset content-addressed riusano la cache HTTP durante il warmup invece di essere riscaricati forzatamente.

## Audit release

- QA completa: PASS.
- schema: 28.
- tabelle: 47 SQLite/MySQL.
- traduzioni: **4.645 × 5 = 23.225**.
- chiavi i18n hash-like attive: 0.
- hardcoded UI copy gate: PASS.
- hardcoded secret/provider contract: PASS.
- Privacy Article 13: PASS.
- Cookie Policy alignment: PASS.
- CSP/security/auth/privacy regression gates: PASS.
- immutable asset contract: PASS.

## Verifica locale aggiuntiva

Il document root servito con PHP built-in server risponde `200` su `/`. Il health endpoint risponde `503` nel sandbox perché non è configurato un DB runtime reale: è un comportamento atteso del test locale isolato, non un failure della QA.

Il Chromium headless disponibile nel sandbox non completa la navigazione loopback (connessioni speculative restano aperte) e il tentativo è stato interrotto. La suite Playwright Chromium/Firefox deve quindi essere eseguita nella CI/staging prevista da `RELEASE-CHECKLIST.md`.

## Note prima del GO production

1. compilare identità legale/privacy nel `.env`;
2. eseguire CI completa e Playwright;
3. verificare che `package-lock.json`, `qa/package-lock.json` e `composer.lock` siano presenti e coerenti; nel contratto corrente sono obbligatori;
4. eseguire smoke test manuale e verificare rollback;
5. solo dopo marcare la RC come GO.



## Documento consolidato da `RELEASE-CHECKLIST.md`

# MeteoNexa 20.1 — Release Candidate checklist

Questa checklist è il percorso consigliato per provare e rilasciare la RC senza saltare i gate di sicurezza/compatibilità.

## 1. Preflight obbligatorio

- usare **solo** lo ZIP RC e non sovrascrivere una produzione esistente senza backup;
- creare `.env` da `.env.example` e valorizzare secret/DB/SMTP/provider AI;
- valorizzare `METEONEXA_LEGAL_CONTROLLER_NAME`, `METEONEXA_LEGAL_CONTROLLER_ADDRESS` e `METEONEXA_PRIVACY_CONTACT_EMAIL`; `METEONEXA_DPO_EMAIL` solo se applicabile;
- verificare che nessun secret sia committato nel repository;
- eseguire backup DB e volume runtime prima dell'upgrade;
- verificare che la migration DB arrivi a schema **28**.

## 2. Gate di build/QA

In un ambiente con accesso npm/Docker/MySQL:

```bash
npm ci --ignore-scripts --no-audit --no-fund
npm run check:full
npm run build:production
bash qa/run-all.sh
```

Eseguire inoltre la CI completa: MySQL 8.4 integration, PHPStan, PHP-CS-Fixer check, Semgrep, Trivy filesystem/image e Playwright Chromium + Firefox.

> Contratto corrente: `package-lock.json`, `qa/package-lock.json` e `composer.lock` sono presenti e obbligatori; la CI usa `npm ci` e rifiuta i fallback `npm install`.

## 3. Avvio RC

```bash
docker compose build --no-cache
docker compose up -d
```

Controllare:

```bash
docker compose ps
docker compose logs --tail=200 web
docker compose logs --tail=200 worker
```

## 4. Smoke test manuale

Verificare almeno:

- home/welcome e login OTP/trusted device;
- cambio lingua IT/EN/ES/FR/DE e tema dark/light;
- meteo corrente, hourly, dettagli e storico;
- cambio località, GPS, ricerca, preferiti e recenti;
- Radar: apertura mappa, live/forecast, slider, playback, zoom e ricerca città;
- Advanced/Intelligence, Copilot/Assistant e Route Weather;
- Notification Center, permessi push e installazione PWA;
- settings, dispositivi/sessioni, revoca accessi;
- privacy.html e cookie-policy.html con dati legali reali del deployment;
- modalità offline dopo almeno un caricamento completo online.

## 5. Controllo performance percepita

Su rete mobile simulata/DevTools verificare che:

- la home mostri lo shell senza attendere CSS Suite/Intelligence/MapLibre;
- `js/app.js` sia preloaded;
- i moduli Suite siano richiesti dopo il gruppo pre-app;
- il Service Worker installi prima `CRITICAL_SHELL` e scaldi `OPTIONAL_SHELL` dopo il load;
- non compaiano errori `ReferenceError`, soprattutto relativi a Radar/`SERVICES`.

## 6. Privacy/security pre-release

- Privacy Policy: nome legale, indirizzo e contatto privacy reali;
- Cookie Policy: nessun cookie/provider non dichiarato;
- CSP/HSTS/header security presenti su HTTPS;
- QA/Diagnostics non accessibili ad account non autorizzati;
- nessun admin QA hardcoded o privilegio derivato da SMTP;
- nessun secret/API key nel client o nello ZIP.

## 7. Rollback

Conservare sempre:

1. ZIP/revisione precedente;
2. dump DB precedente alla migration;
3. backup del volume runtime;
4. `.env` precedente.

In caso di regressione bloccante: fermare i container RC, ripristinare DB/volume compatibili e riavviare l'immagine precedente. Non fare downgrade dello schema sul DB corrente senza restore del backup.

## Criterio GO

Rilasciare la RC in produzione solo se: QA/CI completa verde, smoke test manuale completato, dati legali/privacy valorizzati, nessun errore console bloccante su Chromium/Firefox e rollback verificato.



## Documento consolidato da `styles/README.md`

# MeteoNexa CSS source layout

`css/styles.css` and `css/suite.css` are generated compatibility artifacts. Edit the ordered partials under `styles/main/` and `styles/suite/`, then run:

```bash
python3 tools/build_css.py
```

The build performs **concatenation only**: it does not reorder selectors, minify rules or change specificity. Numeric prefixes are part of the cascade contract and must remain ordered.

## Main bundle

- `00-foundation.css` — tokens, reset, shared buttons/loaders/network state.
- `10-welcome-auth.css` — public welcome/login shell.
- `20-app-shell-weather.css` — authenticated shell and baseline weather pages.
- `30-dialogs-privacy.css` — cookie/privacy/dialog surfaces.
- `40-atmosphere-design-system.css` — weather atmosphere and first design-system refinement.
- `50-product-layout.css` — product shell/layout redesign.
- `60-weather-responsive.css` — weather canvas/responsive layout rules.
- `70-runtime-visibility-components.css` — runtime visibility and feature components.
- `80-location-radar-overrides.css` — timezone/location/radar feature refinements.
- `90-product-polish.css` — later product/UI polish while preserving cascade order.
- `99-reliability-patches.css` — verified reliability/first-paint/mobile patches; intentionally last.

## Suite bundle

- `00-suite-foundation.css` — Suite panels and integration primitives.
- `20-suite-responsive.css` — initial responsive behavior.
- `40-ui-regression-fixes.css` — regression fixes and header alignment.
- `50-route-layout.css` — Route/Assistant layout and search controls.
- `70-settings-archive-assistant.css` — settings, archive radar and Assistant refresh.
- `80-intelligence-controls.css` — Intelligence/custom controls/quality UI.
- `99-ux-reliability-patches.css` — final readability and async-state fixes.

Other focused stylesheets (`css/advanced.css`, `css/intelligence.css`, `css/light-theme.css`, etc.) stay independent because they are already small, feature-scoped layers.
## GitHub Actions evidence — RC2 remediation 2

The real GitHub Actions pipeline on `Fosrur/meteonexa` is now being used as the production-readiness gate. Run 2 confirmed the MySQL 8.4 backup/restore drill end-to-end. The remaining CI findings were release-engineering issues rather than product regressions: the tracked Composer lockfile had been omitted from the repository, runtime staging ownership changed before chmod, the MySQL integration assertion still expected migrations 16..27 instead of the current 16..28 registry, and PHPStan did not scan the shared symbol-definition files used by `api/database/*`. RC2 remediation 2 restores the lockfile, makes runtime ownership/mode preparation privilege-safe, aligns the MySQL migration assertion with schema 28, and adds PHPStan symbol discovery for the shared storage/i18n/email-template functions. The full local `qa/run-all.sh` suite remains green after these changes.


## 20.1 RC2 — P2 remediation (2026-09-20)

P0 e P1 restano chiusi. La passata P2 completa i punti non bloccanti rimasti: export PDF storico reale (download `application/pdf`, non più `window.print()`), gate di deploy sul workflow GitHub Actions verde per lo SHA esatto, correzione mojibake del README, riduzione del CSS parser-blocking tramite defer di `css/advanced.css` e ampliamento dei controlli i18n contro copy UI hardcoded. Il report tecnico non normativo aggiornato è `docs/docs/reports/ARCHITECTURE-SECURITY.md`; `readme.md` resta l'unica source of truth del prodotto.

Il deploy production richiede ora `METEONEXA_GITHUB_REPOSITORY` e, per repository privati, `METEONEXA_GITHUB_TOKEN` con accesso in sola lettura allo stato Actions. Se la run `MeteoNexa QA` del commit da pubblicare non è `completed/success`, il deploy viene interrotto prima di backup/build/avvio container.

### Deploy automatico GitHub → VPS (20.1 RC2)

Il rilascio production è automatico **solo dopo** il workflow `MeteoNexa QA` verde su un push a `main`. Il workflow `.github/workflows/deploy-production.yml` viene attivato da `workflow_run`, rifiuta run obsolete se `main` nel frattempo è avanzato, entra sulla VPS con una chiave SSH dedicata e distribuisce esattamente `github.event.workflow_run.head_sha`.

Segreti GitHub richiesti (Settings → Secrets and variables → Actions): `METEONEXA_VPS_HOST`, `METEONEXA_VPS_USER`, `METEONEXA_VPS_PORT` (facoltativo, default 22), `METEONEXA_VPS_SSH_KEY`, `METEONEXA_VPS_KNOWN_HOSTS`. Non riutilizzare la deploy key VPS→GitHub: la chiave GitHub Actions→VPS deve essere dedicata e la sua pubblica va aggiunta a `/home/deploy/.ssh/authorized_keys`.

Sequenza: QA verde → SSH VPS → deploy exact-SHA → maintenance ON → backup/restore drill → build/container replacement → health/MySQL → maintenance resta ON → smoke live esterno → maintenance OFF. Se il deploy o lo smoke live falliscono, la maintenance resta attiva finché rollback/fix non è stato verificato.

La pagina maintenance usa gli asset stabili `/css/maintenance.css`, `/js/maintenance.js`, `/assets/...`, mantiene il look atmosferico/glass dell'app e usa le chiavi i18n `maintenance.*`; effettua inoltre un probe periodico e ricarica automaticamente l'app quando il rilascio torna disponibile.

### Browser readiness — correzione regressione CI

`meteonexa:ready` resta il contratto storico di app pronta e non viene più subordinato al bootstrap differito Suite/Assistant. La run GitHub successiva al primo fix aveva reso quel segnale dipendente dall'handshake ESM e Chromium/Firefox finivano tutti in timeout su `waitForMeteoNexaReady`.

Per le sole funzionalità che richiedono il layer interattivo completo viene usato `meteonexa:interactive-ready`, pubblicato a fine `modules/esm/bootstrap.mjs`. I test Assistant guest aspettano questo secondo segnale; tutti gli altri test browser continuano a usare `meteonexa:ready`. In questo modo il fix della race Assistant non altera il comportamento storico dell'app né il gate generale dei browser.

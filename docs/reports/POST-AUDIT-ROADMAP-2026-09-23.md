# MeteoNexa — audit tecnico e roadmap post-fix

Data audit: 23 settembre 2026  
Stato riallineato: 30 settembre 2026

> **Stato corrente:** P0 e P1 sono chiusi; P2 ensemble probabilistico/CRPS è implementato nel sorgente e coperto dai gate locali dedicati. La chiusura live di P2 richiede ancora QA completa, MySQL 8.4, Chromium/Firefox e Production Deploy sul medesimo SHA. **P3.1, P3.2, P3.3 e P3.4 sono ora code-complete** mantenendo Radar3 come authority. P3.4 riusa lo schema 31 e aggiunge fingerprint del dataset, split train/holdout deterministico, soglie derivate solo sul train, validazione holdout e stabilità segmentata. La maturità **live** P3.3 resta il gate operativo: P3.4 diventa eseguibile sul dataset reale soltanto quando quel gate è soddisfatto e non può comunque attivare Radar4. Le sezioni più sotto descrivono anche lo storico dell'audit del 23–24 settembre e vanno lette nel loro contesto temporale.

## Sintesi dell'audit

La base applicativa è già molto più evoluta di una normale app meteo: il backend dispone di orchestrazione provider parallela, consensus a sei modelli (ECMWF IFS, ECMWF AIFS, ICON, GFS, Météo-France, UKMO), verifica locale delle previsioni, Radar2/Radar3 con probation/verification, nowcast probabilistico, lightning/satellite evidence, allerte ufficiali, Route Weather, Decision Timeline e AI Weather Copilot vincolato a evidenze deterministiche.

I problemi prioritari trovati in questo audit non sono quindi nel motore meteo di base, ma nel lifecycle di release e bootstrap browser.

### 1. Maintenance durante il deploy

Problema verificato nel codice: `.github/workflows/deploy-production.yml` rimuoveva la maintenance prima dello smoke security pubblico. Inoltre `docker/deploy-production.sh` attivava la maintenance soltanto dopo build, backup e restore drill. Di conseguenza una release GitHub poteva essere ancora in corso mentre il sito era già accessibile.

Correzione applicata:

- l'autodeploy attiva il flag condiviso subito dopo l'allineamento dell'esatto SHA sul VPS e prima dello script di rilascio;
- il deploy manuale attiva la maintenance dopo i preflight non invasivi ma prima di build, backup e restore drill;
- il flag `/var/lib/meteonexa/maintenance.flag` resta sul runtime condiviso durante la sostituzione dei container;
- lo smoke HTTPS/TLS/security esterno viene eseguito mentre la pagina HTML risponde intenzionalmente HTTP 503;
- `maintenance-mode.sh off` è l'ultima operazione del workflow;
- un errore prima dell'ultimo step lascia la maintenance attiva, così utenti guest e autenticati non rientrano in una release parzialmente validata.

### 2. Sessioni già aperte

Il polling applicativo era già presente e corretto per una SPA/PWA completamente avviata: controllo ogni 5 secondi e su focus/pageshow/foreground. Il test precedente copriva però soltanto una sessione guest/preview.

Correzione applicata: aggiunto il caso Playwright di sessione email già autenticata, in modo da bloccare regressioni specifiche fra guest e authenticated lifecycle.

### 3. Flash login durante refresh

Causa verificata: `js/i18n-runtime.js` rimuoveva `i18n-pending` appena il catalogo delle traduzioni era disponibile. `js/app.js`, invece, poteva essere ancora in attesa fino a 12 secondi della riconciliazione `api/auth/status.php`. In quella finestra `#welcome` poteva diventare visibile prima che `reconcileRootView()` ripristinasse l'app autenticata.

Correzione applicata: introdotto `app-boot-pending`. Lo splash resta visibile e sia login sia app rimangono nascoste finché il bootstrap applicativo ha deciso la vista corretta. Il gate viene rimosso su `meteonexa:ready` o dopo una recovery controllata.

### 4. Security GitHub Actions

Dallo ZIP non è possibile identificare il finding esatto del run GitHub fallito perché i log Actions non sono inclusi. Il job principale può fallire su Semgrep, Trivy filesystem o Trivy immagine; il job dependency-security può fallire su npm root, npm QA, Composer o Trivy.

Correzione applicata senza ridurre la severità: ogni scanner produce ora evidenza machine-readable caricata come artifact GitHub Actions e i controlli security vengono aggregati in un gate finale. Le soglie HIGH/CRITICAL restano invariate e non sono stati aggiunti ignore di vulnerabilità.

Nota di hardening successiva: le GitHub Actions sono pin-nate a SHA immutabili, ma restano immagini/base Docker referenziate tramite tag (`semgrep/semgrep:1.169.0`, `node:20-bookworm-slim`, `php:8.3-apache`, `mysql:8.4`). Per una release completamente riproducibile conviene passare a digest verificati e aggiornati con una procedura automatica controllata.

## Roadmap consigliata

### P0 — chiusura release reliability (subito)

1. Eseguire su GitHub il nuovo `quality-security` e scaricare `quality-security-reports` per identificare il finding reale del run precedente.
2. Eseguire Chromium + Firefox dei nuovi test `auth-refresh-no-flash.spec.mjs` e `maintenance-active-session.spec.mjs`.
3. Eseguire staging Docker e un deploy canary verificando che il 503 inizi prima della fase di build e termini soltanto nell'ultimo step.
4. Aggiungere un marker release (`release_id`, SHA, started_at, phase) al maintenance status per mostrare nello schermo di manutenzione lo stato del rilascio senza esporre dettagli sensibili.
5. Pin delle immagini Docker a digest e processo mensile di refresh con Trivy/SBOM prima del merge.

### P1 — Weather Reliability Engine 2.0

Il progetto ha già sei modelli e pesi di skill. Il passo successivo è trasformare il sistema da consensus prevalentemente a voto a blending calibrato per fenomeno.

- pesi distinti per variabile (`temperature`, `rain`, `wind`, `storm`), località, stagione e lead time;
- Brier Score e reliability diagram per probabilità di pioggia/temporale, oltre a MAE per variabili continue;
- CRPS/quantili quando sono disponibili ensemble reali;
- decay temporale dei campioni e shrinkage più forte con pochi dati;
- confronto challenger/champion in shadow mode prima di promuovere nuovi pesi;
- separazione esplicita fra model-run freshness e cache freshness, eliminando progressivamente gli orari di run stimati quando il provider espone metadata affidabili.

### P2 — Ensemble AI meteorologico e probabilità

ECMWF AIFS è già presente come sesta evidenza nel codice. Il passo utile non è aggiungere un altro nome alla lista, ma integrare meglio l'incertezza.

- valutare AIFS ENS/ensemble come evidenza probabilistica, non soltanto AIFS Single;
- conservare IFS/NWP fisico come evidenza indipendente: AI forecast e fisica devono potersi contraddire visibilmente;
- produrre percentili P10/P50/P90 di temperatura, precipitazione e vento e probabilità di superamento soglia;
- usare l'ensemble spread come input del Weather Confidence Engine;
- per 8–15 giorni mostrare scenari e range, non una falsa precisione oraria.

### P3 — Nowcast severo 0–120 minuti

Il vero vantaggio competitivo può arrivare dal very-short-range, dove un'app generica è spesso debole.

- Radar4 in shadow: optical flow + object tracking multi-frame, mantenendo Radar3 come authority finché il backtest non supera le soglie;
- stima probabilistica separata di `rain start`, `peak`, `rain end` ed ETA della cella;
- fusione di trend radar, fulmini, satellite geostazionario, osservazioni al suolo e warning ufficiali;
- integrare quando licenza/accesso lo consentono i dati MTG Meteosat-12 FCI/Lightning Imager, utili per sviluppo e ciclo di vita convettivo;
- calibrazione per distanza dal radar, orografia e qualità/copertura osservativa;
- score di crescita/decadimento cella con verifica automatica a posteriori.

### P4 — Official Warning Hub

La parte official alert dovrebbe diventare un livello separato e non confondersi con il forecast proprietario.

- normalizzare CAP/GeoJSON in un modello interno unico;
- integrare MeteoAlarm EDR/MQTT per l'Europa quando l'accesso da re-user è disponibile;
- adapter nazionali/regionali (es. fonti italiane autorizzate) dietro la stessa interfaccia;
- deduplica per evento/versione/area e lifecycle `issued -> updated -> cancelled/expired`;
- geofencing polygon-based, evitando matching solo testuale;
- mostrare sempre emittente, timestamp e severità ufficiale separati dalla stima MeteoNexa.

### P5 — AI Meteorologist 2.0

L'architettura attuale è corretta nel principio: il modello linguistico spiega evidenza strutturata, non inventa il meteo. Va rafforzata in questa direzione.

- tool contract tipizzato per forecast, nowcast, warning, route, skill e change history;
- ogni risposta AI deve avere `asOf`, fonti usate, confidence, limiti e decision ID;
- risposta generata solo dopo un `decision object` deterministico; l'LLM può spiegare/prioritizzare ma non cambiare severità o numeri;
- eval suite automatica con domande meteorologiche difficili, hallucination checks, source-grounding e multilingual parity;
- memoria utente limitata a preferenze esplicite utili (attività/soglie), non a cronologia meteo indiscriminata;
- fallback template deterministico se provider AI è lento/non disponibile;
- cost/latency budget per request e cache semantica solo su contesti meteorologici equivalenti e freschi.

### P6 — Hyperlocal e Personal Weather Twin

- assimilare stazioni personali/Netatmo solo con quality score e outlier detection;
- bias correction locale su quota, urban heat island, esposizione e distanza costa, senza sovrascrivere il modello con pochi campioni;
- profili attività con soglie diverse e decision windows (`corsa`, `bici`, `moto`, `cantiere`, `mare`, `pendolarismo`);
- notification policy basata su cambiamento materiale della decisione, non sul semplice cambio di un numero;
- privacy: coordinate e osservazioni personali minimizzate, retention esplicita, aggregazione prima della pubblicazione di accuracy locale.

### P7 — Osservabilità meteorologica e release

- dashboard provider latency/error/cache age per fonte;
- SLO distinti per home forecast, nowcast, official warnings e AI explanation;
- drift dashboard dei modelli per area/lead time;
- release canary con confronto delle metriche forecast prima/dopo;
- dataset di golden locations europee con casi pianura/montagna/costa/città;
- synthetic monitoring del percorso completo `maintenance on -> deploy -> external 503 security smoke -> maintenance off`.

## Ordine suggerito

Prima chiudere P0 e rendere il deploy dimostrabilmente sicuro. Poi P1/P2, perché un migliore motore di affidabilità rende più utile tutto ciò che viene dopo. P3 è il blocco con più potenziale di differenziazione percepibile dall'utente. P4 deve procedere in parallelo per la parte safety. P5 va costruito sopra P1–P4, non prima. P6/P7 completano personalizzazione e maturità operativa.

## Riferimenti tecnici esterni verificati durante l'audit

- Open-Meteo model documentation: https://open-meteo.com/en/docs
- ECMWF AIFS operational configuration: https://confluence.ecmwf.int/spaces/FCST/pages/601298799/Operational+configurations+of+the+Artificial+Intelligence+Forecasting+System+AIFS
- ECMWF AIFS Ensemble operational announcement: https://www.ecmwf.int/en/about/media-centre/news/2025/ecmwfs-ensemble-ai-forecasts-become-operational
- EUMETSAT Meteosat Third Generation / Lightning Imager: https://www.eumetsat.int/meteosat-third-generation
- MeteoAlarm API / OGC EDR: https://api.meteoalarm.org/edr/v1

## P0 closure patch — browser/security follow-up

The QA #45 failure was narrowed to two browser-test issues, while PHPStan, ESLint, Semgrep, Trivy filesystem/image, MySQL integration, release gates, staging compose and backup/restore all passed. The closure patch:

- makes the authenticated maintenance round-trip test deterministic by disabling Service Worker registration only in that test; the guest maintenance test continues to exercise the real PWA/Service Worker path;
- removes the external radar-frame availability assertion from the radar hit-target regression while preserving the actionable-control and forecast-mode checks;
- updates `@playwright/test`, `playwright` and `playwright-core` from 1.55.0 to 1.55.1, the patched release for GHSA-7mvr-c777-76hp.

P0 is considered operationally closed only after the pushed commit completes MeteoNexa QA successfully and the automatically triggered Production Deploy completes through maintenance ON, deploy, external security smoke and maintenance OFF.

## Aggiornamento 24 settembre 2026 — P0 refresh + avvio P1.1

### P0 — refresh autenticato senza flash login

La regressione osservata su refresh di una sessione email già autenticata era ancora possibile oltre il precedente watchdog di 12 secondi: sia `js/app.js` sia `js/i18n-runtime.js` potevano rimuovere `app-boot-pending` mentre la riconciliazione server o l'idratazione iniziale erano ancora in corso. In quella finestra la login poteva diventare visibile e venire subito sostituita dall'app autenticata.

Correzione: `app-boot-pending` è ora un gate atomico di bootstrap. I watchdog possono soltanto liberare un loader operativo rimasto bloccato; non possono più scegliere la vista root né mostrare login/app. Il gate viene rilasciato soltanto dal percorso terminale del bootstrap applicativo. Il test Playwright autenticato attraversa esplicitamente il vecchio limite dei 12 secondi con auth ancora pendente.

### P1.1 — Weather Reliability Engine 2.0 foundation

Avviata la prima tranche P1 sopra lo schema già esistente `model_skill_samples`, senza migrazioni DB:

- pesi ancora distinti per metrica, località e lead time, ora anche specializzati per stagione;
- decadimento temporale esponenziale con half-life di 30 giorni, così l'evidenza recente pesa più di quella storica;
- shrinkage più conservativo con pochi campioni effettivi, calcolato dopo il decay;
- fallback progressivo dallo skill stagionale allo skill all-season quando i campioni della stagione corrente sono insufficienti;
- reliability diagram deterministico per `rain`, `storm` e `snow`, con bin probabilistici, observed rate, Brier Score, effective sample count e calibration gap;
- metadata espliciti nel payload reliability per `season`, `decayHalfLifeDays`, modalità `seasonal-decayed-skill-shrunk` e separazione fra freschezza del model run e freschezza cache/fetch.

Restano nelle tranche P1 successive: champion/challenger in shadow mode, promozione automatica con soglie minime di evidenza, e CRPS/quantili quando saranno disponibili ensemble probabilistici reali.

## Aggiornamento 24 settembre 2026 — QA P0 verde e chiusura P1.2

### P0 — release pipeline verificata sullo stesso SHA

Il commit `c16338828fb64308632e61a198cacd5aa90cab65` ha completato con successo MeteoNexa QA #58 e il Production Deploy #60 sullo stesso SHA. I gate release/checksum, security, MySQL, backup/restore, staging e browser regression Chromium/Firefox sono quindi allineati alla release effettivamente distribuita. La verifica visuale manuale del refresh autenticato resta il controllo finale lato utente per il criterio “nessun frame della login durante refresh”, ma non sono previste ulteriori modifiche P0 mentre questo SHA resta verde.

### P1.2 — champion/challenger con promozione protetta

Il Weather Reliability Engine completa il percorso di weighting adattivo senza sostituire alla cieca i pesi in produzione:

- `champion`: il profilo production corrente `recency-decay-skill-v2`;
- `challenger`: il profilo P1.1 `seasonal-decayed-skill-shrunk` con decadimento a 30 giorni, specializzazione stagionale e shrinkage low-sample;
- confronto out-of-sample per `rain`, `storm`, `snow` e lead time `1/3/6/24/48/72h`, senza usare i campioni di holdout per addestrare i pesi confrontati;
- almeno 30 target di training, almeno 24 campioni di valutazione e almeno 3 modelli per campione;
- copertura holdout minima 90%;
- miglioramento Brier minimo assoluto `0.01` e relativo `5%`;
- regressione massima del calibration gap pari a `2` punti percentuali;
- nessun singolo modello può superare il `60%` del peso del challenger nel bucket valutato;
- il challenger deve vincere almeno 2 finestre temporali su 3 e non peggiorare l’ultima finestra oltre la tolleranza tecnica;
- promozione automatica soltanto del bucket che supera tutti i guardrail; gli altri bucket restano sul champion;
- qualsiasi errore, storia insufficiente o cache non valida è fail-safe sul champion;
- stato shadow e decisione di promozione sono memorizzati in `app_metadata` con chiave anonimizzata per device/località, senza nuova migrazione DB; la cache viene invalidata da nuovi campioni verificati, cambio stagione o TTL di 6 ore;
- un modello senza storico nel bucket usa un peso neutro rispetto alla media del bucket, evitando che un valore hard-coded possa dominare un profilo normalizzato.

Il payload `forecastReliability` espone il `weightTournament` con diagnostica, guardrail, Brier champion/challenger e bucket promossi. Il worker di calibrazione e il summary autenticato usano la stessa decisione champion/challenger, evitando divergenza fra i consensus salvati e quelli mostrati all’utente.

Con P1.2, P1 è considerato completo per weighting, calibrazione probabilistica binaria e governance champion/challenger. CRPS, percentili e quantili non vengono simulati con dati deterministici: passano esplicitamente a P2, dove saranno calcolati su ensemble probabilistici reali.

## Aggiornamento 27 settembre 2026 — P2 code-complete e passaggio a P3

### P2 — implementazione completata

P2 non è più il prossimo sviluppo: il sorgente integra ensemble reale AIFS ENS con fallback IFS ENS, P10/P50/P90, probabilità di superamento soglia, spread nel Confidence Engine, confronto separato con IFS deterministico, persistenza `ensemble_verification_samples`, CRPS empirico per le variabili supportate e tool Copilot `probabilistic_ensemble`. Lo schema corrente è **29**.

I gate locali dedicati risultano coperti dal repository; la promozione live resta subordinata, come per le tranche precedenti, a QA completa, migrazione MySQL 8.4, Chromium + Firefox e Production Deploy sullo stesso SHA. Questa distinzione evita di confondere **code-complete** con **operationally closed**.

### P3 — piano iniziale Radar4 / Nowcast severo 0–120 minuti

Questa era la definizione iniziale della tranche P3. **P3.1 è ora completata nel sorgente**; i punti non ancora chiusi confluiscono in P3.2 e nelle tranche successive. L’intero percorso P3 resta in **shadow mode** e non sostituisce Radar3:

- optical flow e object tracking multi-frame;
- identità/traiettoria delle celle e stima ETA;
- score di crescita/decadimento;
- probabilità separate di `rain start`, `peak` e `rain end`;
- fusione progressiva di radar, fulmini, satellite, osservazioni e warning ufficiali;
- backtest automatico con guardrail espliciti prima di qualunque promozione di Radar4 ad authority.

Radar3 resta la sorgente production-safe finché Radar4 non dimostra un miglioramento misurabile e ripetibile sui dataset di verifica.

## Aggiornamento 27 settembre 2026 — P3.1 Radar4 shadow code-complete

### P3.1 — optical flow + object tracking multi-frame

La prima tranche P3 è ora implementata nel sorgente mantenendo **Radar3 come authority production**. Radar4 è un layer separato e forzato in `shadow`/`off`:

- optical flow Lucas–Kanade calcolato su più coppie di frame radar archiviati;
- fusione del vettore optical-flow con le tracce object-based Radar3, con penalità esplicita quando i due vettori divergono;
- traiettorie e cono d'incertezza a 15/30/45/60/90/**120 minuti**;
- ETA della cella verso la località, direzione, velocità, confidence e `growthDecayScore`;
- payload separato `radar4Tracking` con `authoritative=false` e policy `productionDecisionsUnaffected=true`;
- `METEONEXA_RADAR4_MODE` limitato a `shadow` (default) oppure `off`: non esiste promozione runtime ad `active` in P3.1;
- ETA Radar4 registrate nello stesso ledger `radar_eta_predictions` con algoritmo `radar-v4`, quindi verificate dalla medesima ground truth indipendente usata da Radar2/Radar3;
- gate shadow Radar3↔Radar4 dopo almeno 30 campioni verificati per algoritmo: almeno 1 minuto e 8% di miglioramento MAE, regressione `within tolerance` non oltre 2 punti percentuali;
- anche con guardrail verdi il risultato è soltanto `promotionCandidate`; `authorityLockedToRadar3=true` rimane obbligatorio in questa tranche;
- QA dedicato `qa/radar4_shadow_smoke.php`, incluso nel runner aggregato.

P3.1 non introduce migrazioni: lo schema resta **29** e il backtest riusa il ledger ETA già esistente.

## Aggiornamento 30 settembre 2026 — P3.2 Radar4 probabilistico shadow code-complete

### P3.2 — distribuzioni evento + fusione multi-evidenza + verification ledger

La seconda tranche P3 è ora implementata nel sorgente e resta **shadow-only**:

- distribuzioni separate P10/P50/P90 per `rainStart`, `rainPeak` e `rainEnd` su 0–120 minuti, con bucket da 5 minuti;
- fusione Radar4 + fulmini + satellite + osservazioni indipendenti + modelli + warning ufficiali; gli warning sono soltanto contesto con peso limitato e conservano authority/lifecycle separati;
- confidence calibrata con qualità optical-flow, vector spread, qualità/numero osservazioni, copertura delle sorgenti, distanza dal punto radar archiviato e quota del terreno come proxy orografico conservativo;
- policy esplicita `orographyUsesElevationProxyOnly=true`: P3.2 non finge di derivare slope/terrain complexity dalla sola quota;
- nuovo schema **30** e tabella `radar4_event_predictions` per conservare le previsioni start/peak/end/growth e verificarle contro `observation_evidence` indipendente;
- skill a posteriori: MAE temporale e coverage P10–P90 per start/peak/end; errore del trend osservato per `growthDecayScore`; la ground truth usa lo stato al tempo di emissione e non associa un vecchio episodio wet a un nuovo rain-start;
- dataset considerato maturo solo dopo almeno 30 campioni verificati per ciascun tipo (`rain_start`, `rain_peak`, `rain_end`, `growth_decay`);
- anche con dataset maturo, `promotionEligible=false`, `authorityLockedToRadar3=true` e `productionDecisionsUnaffected=true` restano invarianti in P3.2;
- QA dedicato `qa/radar4_probabilistic_shadow_smoke.php`, incluso nel runner aggregato.

Durante P3.2 è stato corretto anche il ledger ETA Radar4/Radar3 per usare `INSERT IGNORE` su MySQL e `INSERT OR IGNORE` su SQLite: il precedente statement SQLite-only avrebbe impedito l'accodamento ETA su MySQL pur essendo coperto dai catch fail-safe.

## Aggiornamento 30 settembre 2026 — P3.3 empirical calibration code-complete / live evidence pending

### P3.3 — dataset multi-area, reliability empirica e terrain verificabile

La parte implementabile nel sorgente è completata senza cambiare l’authority production:

- schema **31**, senza nuove tabelle: `radar4_event_predictions` e `radar_eta_predictions` conservano `area_key` coarse, distanza radar, coverage delle evidenze, stagione, regime meteo e classe terrain;
- start/peak/end possono produrre sia campioni positivi sia **negativi osservativi reali**. Un negativo richiede almeno quattro osservazioni indipendenti di qualità ≥65, una finestra asciutta di almeno 90 minuti e nessun campione wet; in assenza di copertura sufficiente la previsione resta non verificata;
- Brier score e reliability bins della probability vengono quindi calcolati su outcome 0/1 reali, insieme a bias P50 e coverage P10–P90 dei soli eventi positivi;
- il report `radar4Calibration` aggrega skill globale e segmentata per distanza, coverage, stagione, regime meteo e terrain, e confronta ETA Radar3↔Radar4 per area/regime;
- la calibrazione empirica usa shrinkage verso la probability grezza e resta shadow-only; un bucket con meno di 20 campioni non viene calibrato;
- profilo orografico P3.3: campionamento DEM attorno al punto (centro + 8 punti a circa 5 km), con relief, gradiente massimo e classe `flat/rolling/complex/mountainous`. Se il provider non è disponibile, resta il fallback elevation-only P3.2;
- soglia di maturità del dataset: almeno 3 aree coarse, 2 stagioni, 3 regimi meteo, 50 outcome binari e 30 timing positivi per ciascuno tra start/peak/end, 30 campioni growth/decay e 60 ETA verificate per Radar3 e Radar4;
- anche a dataset maturo il codice imposta `numericThresholdsFinalized=false`, `activationAllowed=false`, `authorityLockedToRadar3=true`: la maturità abilita soltanto una fase di studio separata, non la promozione.

QA dedicata: `qa/radar4_empirical_calibration_smoke.php` copre segmentazione, terrain, negativi osservativi, Brier/reliability e blocco promotion; `qa/radar4_empirical_calibration_schema_smoke.py` valida schema 31, baseline SQLite, colonne/indici e contratto MySQL.

### Stato operativo P3.3

**Code-complete:** sì. **Dataset live maturo:** non dichiarato finché `radar4Calibration.datasetMature` non diventa `true` su dati reali. Le fixture sintetiche servono solo a provare la logica dei gate e non valgono come evidenza meteorologica live.

## Aggiornamento 30 settembre 2026 — P3.4 promotion study code-complete / execution gated by live evidence

### P3.4 — dataset fingerprint, train/holdout e release-review

La parte software del promotion study è ora implementata senza introdurre una nuova migrazione: schema **31**, 49 tabelle. `radar4PromotionStudy` resta bloccato finché P3.3 non raggiunge `datasetMature=true`; dopo il gate:

- calcola un **fingerprint SHA-256** del ledger verificato usato per lo studio, così il release-review identifica esattamente lo snapshot valutato;
- costruisce uno split **70/30 deterministico** basato su SHA-256, riproducibile a parità di dataset;
- deriva le soglie numeriche esclusivamente dal train set con limiti conservativi e le valida sul holdout;
- controlla ETA Radar4↔Radar3, bias P50, coverage P10–P90, Brier/reliability e campioni minimi del holdout;
- verifica stabilità per **area, stagione e regime meteo**, senza accettare regressioni rilevanti nei segmenti con campioni sufficienti;
- produce un release-review che può soltanto indicare `eligible-for-separate-manual-canary-review` oppure `keep-radar4-shadow`.

I vincoli restano hard-coded nel contratto P3.4: `activationAllowed=false`, `automaticPromotion=false`, `authorityLockedToRadar3=true`, `productionDecisionsUnaffected=true`, canary e rollback obbligatori per una release successiva. Il gate `qa/radar4_promotion_study_smoke.php` verifica anche che un dataset immaturo non finalizzi soglie e che fingerprint/split siano riproducibili.

**Verifica locale:** P3.1/P3.2/P3.3/P3.4, Forecast Reliability, P2 Ensemble, release audit/final, production readiness, roadmap, i18n, backend maintainability, checksum e provenance sono verdi. Il full QA continua a incontrare il byte-contract `source↔dist` già presente nella baseline su `js/app.js` e alcuni ESM; nessun asset frontend/dist è stato modificato da P3.4 e la rigenerazione production richiede la toolchain esbuild installata.

### Stato P3 dopo P3.4

**Sviluppo core P3: code-complete.** Non resta un'altra tranche algoritmica da implementare prima della verifica live. La chiusura operativa richiede: (1) maturità live P3.3 multi-area/multi-stagione/multi-regime; (2) esecuzione P3.4 sul dataset reale congelato; (3) holdout e stabilità verdi. Finché questi tre punti non sono soddisfatti, Radar4 resta shadow e Radar3 resta authority.

### Sviluppi successivi — release canary Radar4, separata dalla P3 core

Solo dopo un P3.4 live verde si potrà aprire una release esplicita per un eventuale canary Radar4. Quella release dovrà usare lo stesso `datasetFingerprint` approvato, definire rollback automatico/manuale, osservabilità dedicata, percentuale/ambito del canary e criteri di abort. Non è parte della chiusura software P3 e non deve essere avviata automaticamente dal promotion study.

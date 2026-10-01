# MeteoNexa — sistema qualità post-roadmap

Data: 1 ottobre 2026

## Stato

La roadmap post-audit **P0→P7 è conclusa lato sviluppo software**. Non viene aperta automaticamente una P8. Il ciclo successivo è gestito come **continuous quality + improvement backlog**: ogni modifica deve avere una metrica o un contratto osservabile prima/dopo e non può indebolire i gate live P2/P3 già separati dal code-complete.

Il sistema automatico descritto qui è **implementato**, non soltanto pianificato.

## Quality runner autorevole

`tools/quality-runner.py` è il runner zero-dependency dei gate repository. Il catalogo è `qa/quality-suite.json`; `qa/continuous-quality.sh` e il legacy-compatible `qa/run-all.sh` sono soltanto entry point.

Ogni esecuzione produce:

- `quality-report.json`: risultato machine-readable, commit, branch, durata e stato di ogni gate;
- `quality-report.md`: riepilogo umano con failure tail e confronto opzionale con una baseline precedente;
- `quality-junit.xml`: output standard per tooling CI/test analytics;
- `logs/<gate>.log`: stdout/stderr completo di ogni singolo gate;
- `quality-history.jsonl`: storico append-only delle esecuzioni nello stesso workspace.

Il runner **non si ferma al primo test fallito** salvo richiesta esplicita `--fail-fast`: una CI rossa restituisce quindi l'intero insieme di regressioni rilevate nella stessa esecuzione.

### Livelli

- `npm run qa:fast` — **12 gate**: self-contract del quality system, P3/P4/P5/P6/P7, schema/roadmap e syntax. È il loop locale rapido.
- `npm run qa:release` — fast + **8 gate release/integrity** (**20 gate effettivi**), inclusi release audit, production readiness, deploy contract, P2 release quality, final release, provenance e checksum. Va eseguito sulla working tree finale/committata.
- `npm run qa:full` — **85 gate di regressione** catalogati individualmente. Sostituisce il precedente comportamento `set -e` di `qa/run-all.sh`, che nascondeva i failure successivi al primo.

Il vecchio `bash qa/run-all.sh` resta supportato ma delega al runner `full`.

### Confronto prima/dopo

Per misurare un miglioramento contro un report precedente:

```bash
npm run qa:fast -- --baseline-report /percorso/quality-report.json
```

Il Markdown/JSON evidenzia status regressions, status improvements e delta di durata per gate. I delta temporali sono informativi: non diventano da soli motivo di promozione o rollback senza una soglia esplicita approvata.

## GitHub Actions

`.github/workflows/meteonexa-tests.yml` usa il sistema automaticamente su push, pull request, workflow manuale e schedulazione giornaliera.

- il job release esegue `qa:fast`, ricostruisce gli asset production e poi `qa:full`;
- i report continuous-quality vengono caricati sempre come artifact con retention 30 giorni;
- il Markdown viene aggiunto al GitHub Job Summary tramite `GITHUB_STEP_SUMMARY`;
- MySQL 8.4 resta un job reale separato;
- PHPStan/ESLint/esbuild/Semgrep/Trivy, backup/restore e staging hardened restano gate separati;
- Chromium e Firefox producono report HTML e **JUnit distinti** per regressione generale e maintenance-exclusive, caricati sempre come evidence per 30 giorni;
- il live security check resta schedulato/manuale.

Il gate `qa/continuous_quality_contract_smoke.py` protegge il sistema stesso: verifica catalogo, mode/script npm, reporter JSON/Markdown/JUnit/history e wiring CI. La full suite deve mantenere almeno 60 gate catalogati per impedire una riduzione accidentale della copertura.

## Regola di release

Nessuna metrica singola promuove automaticamente una release o Radar4. Canary e promotion study possono soltanto aprire una review manuale. Il deploy conserva il percorso maintenance ON → exact SHA deploy → external security smoke in 503 → maintenance OFF → live smoke.

P2 resta operationally closed solo dopo i gate live sullo stesso SHA. P3 Radar4 resta shadow finché la maturità empirica e il Promotion Study reale non sono verdi; Radar3 resta authority fino a una release canary separata e manualmente approvata.

## Chiusura dei quattro punti post-roadmap

Al **1 ottobre 2026** i quattro punti di chiusura sono implementati:

1. **Build reproducibility — CHIUSO.** `tools/reproducible-build.py` sceglie esbuild `0.28.2` quando presente e, in ambienti offline, un fallback ESM source-preserving. Il fallback è ammesso solo finché i moduli non contengono static import; il relativo gate lo verifica. Due build consecutive devono produrre gli stessi digest. `dist/build-attestation.json` registra source/manifest/dist SHA-256. Il vecchio `source↔dist` è rimosso.
2. **Trend cross-run + flakiness — CHIUSO.** Lo storico contiene stato e durata per gate; `tools/quality-trends.py` produce `quality-trends.json/.md` con failure rate, P50/P95, transizioni e timing regression. La CI tenta di recuperare lo storico dal precedente artifact tramite API GitHub; l'assenza di storico non blocca il run. Nessuna quarantena automatica.
3. **Property/mutation testing — CHIUSO.** Le invarianti coprono probability/timing Radar4, Wilson interval, geofencing warning, AI decision ID e guardrail metric-driven. Il mutation gate modifica realmente il helper critico in file temporanei e richiede **100% mutant-kill** sulla baseline corrente.
4. **Metric-driven weather improvements — CHIUSO come ciclo sicuro di ottimizzazione.** P7 provider health/SLO/drift produce un improvement backlog e candidate weights. Solo drift maturo può ridurre il peso candidato di un modello; i pesi restano normalizzati e `productionApplied=false`. Ogni applicazione reale resta subordinata al canary/golden comparison.

### Stato finale del quality system

- `qa:fast`: **12/12** gate sulla baseline di chiusura;
- `qa:release`: **20 gate effettivi** (12 fast + 8 release/integrity);
- `qa:full`: **85 gate** catalogati; la verifica di chiusura ha ottenuto **85/85 PASS**. Nell'ambiente di lavoro è stata eseguita in segmenti per il limite temporale del tool, senza saltare gate;
- build offline a due passaggi: **PASS**;
- `asset-contract`: **PASS**;
- mutation score baseline: **5/5 = 100%**.

## Dopo la chiusura

Non esiste una P8 implicita e non resta un backlog software obbligatorio per dichiarare completato il progetto. Restano soltanto:

- gate operativi/live che richiedono il commit pubblicato, infrastruttura reale o campioni meteorologici reali;
- manutenzione dipendenze/security;
- eventuali miglioramenti futuri scelti dai dati P7 e validati con il quality system;
- una nuova roadmap solo se verrà richiesta una capability sostanziale nuova.

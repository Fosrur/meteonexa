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

- `npm run qa:fast` — **9 gate**: self-contract del quality system, P3/P4/P5/P6/P7, schema/roadmap e syntax. È il loop locale rapido.
- `npm run qa:release` — fast + **7 gate release/integrity**, inclusi release audit, production readiness, deploy contract, P2 release quality, final release, provenance e checksum. Va eseguito sulla working tree finale/committata.
- `npm run qa:full` — **80 gate di regressione** catalogati individualmente. Sostituisce il precedente comportamento `set -e` di `qa/run-all.sh`, che nascondeva i failure successivi al primo.

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

## Backlog miglioramenti successivi

Ora che il test system è disponibile, il lavoro successivo non viene numerato P8. Le priorità vengono ordinate da dati misurati:

1. **Build reproducibility:** eliminare il disallineamento storico `source ↔ dist` e rendere riproducibile anche il build locale offline/cache-aware.
2. **Trend cross-run:** consolidare i report conservati dalla CI in una serie storica consultabile per durata, failure rate e flakiness, senza usare la storia come scorciatoia per ignorare test rossi.
3. **Flaky-test detection:** identificare test intermittenti Chromium/Firefox e backend; nessuna quarantena silenziosa, ogni eccezione deve avere owner/issue e scadenza.
4. **Property/mutation testing:** aumentare la capacità di trovare regressioni nei motori critici (probabilità, warning lifecycle, decision object, Radar4) oltre agli smoke test a esempi fissi.
5. **Metric-driven weather improvements:** usare P7 observability e i ledger P1/P2/P3 per ordinare accuratezza, calibrazione, latency/provider reliability e qualità delle notifiche in base al beneficio misurabile.

Una nuova roadmap viene aperta soltanto per una capability sostanziale nuova, non per la manutenzione di questi gate.

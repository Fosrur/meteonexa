# MeteoNexa — Project Closure

Data: 1 ottobre 2026

## Esito

**PROJECT SOFTWARE CLOSED — roadmap P0→P7 e backlog tecnico finale completati.**

Il progetto entra in maintenance/continuous-quality. Non esiste una P8 automatica. I gate live che dipendono da infrastruttura o da dati meteorologici reali non vengono simulati e restano esplicitamente separati dalla completezza del software.

## Quattro punti finali

### 1. Build reproducibility

- doppio-pass deterministico;
- esbuild production pinned `0.28.2`;
- fallback offline source-preserving ammesso solo senza static import ESM;
- SHA-256 attestation sorgenti/manifest/dist;
- `asset-contract` verde;
- storico `source↔dist` risolto.

### 2. Trend e flakiness cross-run

- gate status/duration salvati per run;
- failure/pass rate, P50/P95, transizioni, flaky e timing regression;
- recupero fail-soft dello storico dal precedente artifact GitHub Actions;
- nessuna quarantena silenziosa.

### 3. Property e mutation testing

- property test deterministici/randomizzati sui motori critici;
- mutation testing reale su helper metric-driven;
- baseline: **5/5 mutant killed — 100%**.

### 4. Meteo improvement loop guidato da P7

- provider health, SLO e model drift producono priorità misurabili;
- candidate weights penalizzano solo drift con campioni sufficienti;
- candidate normalizzati;
- nessuna applicazione production automatica;
- canary/golden comparison obbligatorio.

## Evidenza QA finale

- `qa:fast`: **12/12 PASS**;
- full catalog: **85 gate**;
- verifica di chiusura: **85/85 PASS**;
- build reproducibility two-pass: **PASS**;
- asset contract: **PASS**;
- P0→P7 regression: **PASS**.

L'esecuzione full locale è stata suddivisa in segmenti unicamente per il limite temporale dell'ambiente di esecuzione; tutti gli indici 1–85 sono stati eseguiti e verificati. La CI continua a eseguire la suite completa senza questa segmentazione.

## Condizioni ancora operative, non sviluppo aperto

1. P2 richiede i gate live sullo stesso SHA pubblicato prima di essere dichiarato operationally closed.
2. P3 Radar4 deve maturare su evidenza meteorologica reale e superare il promotion study reale prima di qualunque canary.
3. Deploy, SMTP, push, browser e provider reali restano verifiche di esercizio/release.

Queste condizioni non riaprono la roadmap software.

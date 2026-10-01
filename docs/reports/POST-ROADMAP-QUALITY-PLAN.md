# MeteoNexa — piano qualità post-roadmap

Data: 1 ottobre 2026

La roadmap post-audit P0→P7 termina con P7. Da questo punto non viene aperta automaticamente una P8: il lavoro successivo viene gestito come **continuous quality + improvement backlog**, separando bug/regressioni, miglioramenti misurabili e nuove capability che richiederebbero una nuova roadmap esplicita.

## Sistema automatico

- `qa/continuous-quality.sh fast`: guardrail rapidi P5/P6/P7 + P4/P3 + sintassi, adatti allo sviluppo locale.
- `qa/continuous-quality.sh release`: aggiunge release audit, production readiness, deploy contract, P2 release quality, final release e checksum.
- `qa/continuous-quality.sh full`: usa la suite completa `qa/run-all.sh`.
- `.github/workflows/meteonexa-tests.yml` resta il gate autorevole su push, pull request, esecuzione manuale e schedulazione giornaliera; ricostruisce gli asset production, esegue la suite corrente e valida separatamente MySQL 8.4.

## Backlog miglioramenti: criteri

Un miglioramento entra nel backlog solo se ha una metrica osservabile prima/dopo. Le aree prioritarie sono accuratezza meteo, affidabilità provider, latenza, calibrazione probabilistica, qualità nowcast, costo/latency AI, rilevanza delle notifiche e regressioni UX. P3 Radar4 continua in shadow finché l'evidenza live non soddisfa i gate già definiti; P2/P3 non vengono dichiarati live senza i rispettivi gate operativi.

## Regola di release

Nessuna metrica singola promuove automaticamente una release o Radar4. Canary e promotion study possono solo aprire una review manuale; il deploy mantiene maintenance ON → exact SHA deploy → external security smoke in 503 → maintenance OFF → live smoke.

#!/usr/bin/env python3
from pathlib import Path
import sys
root=Path(__file__).resolve().parents[1]
files={name:(root/name).read_text(encoding="utf-8") for name in ["js/app.js","js/advanced.js","js/suite.js","modules/esm/domains/suite-support.mjs","js/config.js"]}
fusion=(root/"api/weather/fusion.php").read_text(encoding="utf-8")
err=[]
for name,text in files.items():
    for token in ["CONFIG.ECMWF_API","CONFIG.AIFS_API","CONFIG.ICON_API","CONFIG.DWD_ICON_API","CONFIG.GFS_API","CONFIG.METEOFRANCE_API","CONFIG.UKMO_API"]:
        if token in text: err.append(f"browser provider fan-out remains in {name}: {token}")
    if "https://api.open-meteo.com/v1/ecmwf" in text or "https://api.open-meteo.com/v1/dwd-icon" in text or "https://api.open-meteo.com/v1/gfs" in text or "https://api.open-meteo.com/v1/meteofrance" in text or "ukmo_seamless" in text:
        err.append(f"direct model provider URL remains in browser file: {name}")
if "weatherFusion: 'api/weather/fusion.php'" not in files["modules/esm/domains/suite-support.mjs"]: err.append("suite support server weather fusion endpoint missing")
if "loadForecastFusion({ force })" not in files["js/app.js"]: err.append("app Intelligence does not reuse server fusion")
if "loadForecastFusion({ force })" not in files["js/advanced.js"]: err.append("Advanced does not reuse server fusion")
if "'models'=>$modelEvidence" not in fusion: err.append("server normalized model evidence missing")
print("Server model source: "+("PASS" if not err else "FAIL"))
for e in err: print(" - "+e)
sys.exit(bool(err))

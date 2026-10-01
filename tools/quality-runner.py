#!/usr/bin/env python3
"""MeteoNexa zero-dependency continuous-quality runner.

Runs the catalogued fast/release/full gates, writes machine-readable JSON,
human-readable Markdown, JUnit XML and per-gate logs. The runner deliberately
continues after failures so a single CI run reports the complete failure set.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys
import time
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
CATALOG = ROOT / "qa" / "quality-suite.json"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=("fast", "release", "full"), nargs="?", default="fast")
    parser.add_argument("--report-dir", default=os.getenv("QUALITY_REPORT_DIR", ".quality-reports/latest"))
    parser.add_argument("--fail-fast", action="store_true")
    parser.add_argument("--baseline-report", help="Optional previous quality-report.json used for status/duration comparison")
    parser.add_argument("--history-file", default=os.getenv("QUALITY_HISTORY_FILE"), help="Optional shared cross-run quality-history.jsonl")
    return parser.parse_args()


def now_utc() -> str:
    return dt.datetime.now(dt.timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def git_value(*args: str) -> str | None:
    try:
        return subprocess.check_output(["git", *args], cwd=ROOT, text=True, stderr=subprocess.DEVNULL).strip() or None
    except Exception:
        return None


def fingerprint_catalog(catalog: dict) -> str:
    blob = json.dumps(catalog, sort_keys=True, separators=(",", ":")).encode()
    return hashlib.sha256(blob).hexdigest()


def effective_gates(catalog: dict, mode: str) -> list[dict]:
    if mode == "fast":
        return list(catalog["modes"]["fast"])
    if mode == "release":
        return [*catalog["modes"]["fast"], *catalog["modes"]["release"]]
    # Full is the exhaustive post-build regression suite. Release remains a
    # separate pre-build integrity mode because its checksum gate validates the
    # committed artifact set before CI rebuilds production assets.
    return list(catalog["modes"]["full"])


def tail(text: str, lines: int = 25) -> str:
    chunks = text.rstrip().splitlines()
    return "\n".join(chunks[-lines:])



def compare_reports(current: dict, baseline_path: str | None) -> dict | None:
    if not baseline_path:
        return None
    path = Path(baseline_path)
    if not path.is_absolute():
        path = ROOT / path
    baseline = json.loads(path.read_text(encoding="utf-8"))
    previous = {g["id"]: g for g in baseline.get("gates", [])}
    deltas = []
    regressions = []
    improvements = []
    for gate in current.get("gates", []):
        old = previous.get(gate["id"])
        if not old:
            continue
        old_duration = float(old.get("durationSeconds") or 0.0)
        new_duration = float(gate.get("durationSeconds") or 0.0)
        delta = new_duration - old_duration
        pct = (delta / old_duration * 100.0) if old_duration > 0 else None
        deltas.append({
            "id": gate["id"],
            "beforeSeconds": round(old_duration, 3),
            "afterSeconds": round(new_duration, 3),
            "deltaSeconds": round(delta, 3),
            "deltaPercent": round(pct, 1) if pct is not None else None,
        })
        if old.get("status") == "pass" and gate.get("status") != "pass":
            regressions.append(gate["id"])
        if old.get("status") != "pass" and gate.get("status") == "pass":
            improvements.append(gate["id"])
    return {
        "baseline": str(path),
        "baselineCommit": baseline.get("git", {}).get("sha"),
        "statusRegressions": regressions,
        "statusImprovements": improvements,
        "durationDeltas": deltas,
    }

def write_junit(path: Path, report: dict) -> None:
    suite = ET.Element(
        "testsuite",
        name=f"meteonexa-quality-{report['mode']}",
        tests=str(report["summary"]["total"]),
        failures=str(report["summary"]["failed"]),
        errors="0",
        skipped="0",
        time=f"{report['summary']['durationSeconds']:.3f}",
        timestamp=report["startedAt"],
    )
    for gate in report["gates"]:
        case = ET.SubElement(
            suite,
            "testcase",
            classname=f"meteonexa.{gate['category']}",
            name=gate["id"],
            time=f"{gate['durationSeconds']:.3f}",
        )
        if gate["status"] != "pass":
            failure = ET.SubElement(case, "failure", message=gate["failureReason"])
            failure.text = gate.get("failureTail") or gate["failureReason"]
        out = ET.SubElement(case, "system-out")
        out.text = f"log={gate['log']}\ncommand={gate['command']}"
    ET.ElementTree(suite).write(path, encoding="utf-8", xml_declaration=True)


def markdown(report: dict) -> str:
    s = report["summary"]
    icon = "✅" if s["failed"] == 0 else "❌"
    lines = [
        f"# MeteoNexa quality report — {report['mode']}",
        "",
        f"{icon} **{s['status'].upper()}** — {s['passed']}/{s['total']} gate passed — {s['durationSeconds']:.2f}s",
        "",
        f"- Commit: `{report.get('git', {}).get('sha') or 'n/a'}`",
        f"- Branch: `{report.get('git', {}).get('branch') or 'n/a'}`",
        f"- Started: `{report['startedAt']}`",
        f"- Catalog SHA-256: `{report['catalogSha256']}`",
        "",
        "| Gate | Category | Status | Duration |",
        "|---|---|---:|---:|",
    ]
    for gate in report["gates"]:
        status = "PASS" if gate["status"] == "pass" else "FAIL"
        lines.append(f"| `{gate['id']}` | {gate['category']} | {status} | {gate['durationSeconds']:.2f}s |")
    comparison = report.get("comparison")
    if comparison:
        lines += ["", "## Comparison with baseline", ""]
        if comparison["statusRegressions"]:
            lines.append("- Status regressions: " + ", ".join(f"`{x}`" for x in comparison["statusRegressions"]))
        else:
            lines.append("- Status regressions: none")
        if comparison["statusImprovements"]:
            lines.append("- Status improvements: " + ", ".join(f"`{x}`" for x in comparison["statusImprovements"]))
        slow = sorted(comparison["durationDeltas"], key=lambda x: x["deltaSeconds"], reverse=True)[:5]
        if slow:
            lines += ["", "Largest duration deltas:", ""]
            for item in slow:
                pct = "n/a" if item["deltaPercent"] is None else f"{item['deltaPercent']:+.1f}%"
                lines.append(f"- `{item['id']}`: {item['deltaSeconds']:+.3f}s ({pct})")
    failures = [g for g in report["gates"] if g["status"] != "pass"]
    if failures:
        lines += ["", "## Failures", ""]
        for gate in failures:
            lines += [
                f"### `{gate['id']}`",
                "",
                f"Reason: **{gate['failureReason']}**",
                "",
                "```text",
                gate.get("failureTail") or "No output captured.",
                "```",
                "",
            ]
    return "\n".join(lines).rstrip() + "\n"


def main() -> int:
    args = parse_args()
    catalog = json.loads(CATALOG.read_text(encoding="utf-8"))
    gates = effective_gates(catalog, args.mode)
    report_dir = Path(args.report_dir)
    if not report_dir.is_absolute():
        report_dir = ROOT / report_dir
    logs_dir = report_dir / "logs"
    logs_dir.mkdir(parents=True, exist_ok=True)

    started_iso = now_utc()
    start_total = time.monotonic()
    results: list[dict] = []

    print(f"== MeteoNexa continuous quality: {args.mode} ({len(gates)} gates) ==", flush=True)
    for index, gate in enumerate(gates, 1):
        gate_id = gate["id"]
        command = [str(x) for x in gate["command"]]
        timeout = int(gate.get("timeout", 300))
        print(f"[{index:02d}/{len(gates):02d}] {gate_id}: {shlex.join(command)}", flush=True)
        started = time.monotonic()
        status = "pass"
        rc: int | None = 0
        reason = ""
        try:
            proc = subprocess.run(
                command,
                cwd=ROOT,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                text=True,
                errors="replace",
                timeout=timeout,
                env=os.environ.copy(),
            )
            output = proc.stdout or ""
            rc = proc.returncode
            if rc != 0:
                status = "fail"
                reason = f"exit code {rc}"
        except subprocess.TimeoutExpired as exc:
            output = (exc.stdout or "") + ("\n" + exc.stderr if exc.stderr else "")
            if isinstance(output, bytes):
                output = output.decode(errors="replace")
            status = "fail"
            rc = None
            reason = f"timeout after {timeout}s"
        except FileNotFoundError as exc:
            output = str(exc)
            status = "fail"
            rc = None
            reason = "command not found"
        duration = time.monotonic() - started
        log_rel = f"logs/{gate_id}.log"
        (report_dir / log_rel).write_text(output, encoding="utf-8")
        result = {
            "id": gate_id,
            "category": gate.get("category", "uncategorized"),
            "status": status,
            "durationSeconds": round(duration, 3),
            "exitCode": rc,
            "timeoutSeconds": timeout,
            "command": shlex.join(command),
            "log": log_rel,
        }
        if status != "pass":
            result["failureReason"] = reason or "failed"
            result["failureTail"] = tail(output)
        results.append(result)
        print(f"      {status.upper()} {duration:.2f}s", flush=True)
        if args.fail_fast and status != "pass":
            break

    duration_total = time.monotonic() - start_total
    failed = sum(1 for g in results if g["status"] != "pass")
    summary = {
        "status": "pass" if failed == 0 and len(results) == len(gates) else "fail",
        "total": len(results),
        "planned": len(gates),
        "passed": sum(1 for g in results if g["status"] == "pass"),
        "failed": failed,
        "durationSeconds": round(duration_total, 3),
    }
    report = {
        "schemaVersion": 1,
        "mode": args.mode,
        "startedAt": started_iso,
        "finishedAt": now_utc(),
        "catalogSha256": fingerprint_catalog(catalog),
        "git": {
            "sha": git_value("rev-parse", "HEAD"),
            "branch": git_value("rev-parse", "--abbrev-ref", "HEAD"),
            "dirty": bool(git_value("status", "--porcelain")),
        },
        "environment": {
            "ci": bool(os.getenv("CI")),
            "githubRunId": os.getenv("GITHUB_RUN_ID"),
            "githubRunAttempt": os.getenv("GITHUB_RUN_ATTEMPT"),
        },
        "summary": summary,
        "gates": results,
    }
    report["comparison"] = compare_reports(report, args.baseline_report)

    (report_dir / "quality-report.json").write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
    md = markdown(report)
    (report_dir / "quality-report.md").write_text(md, encoding="utf-8")
    write_junit(report_dir / "quality-junit.xml", report)

    github_summary = os.getenv("GITHUB_STEP_SUMMARY")
    if github_summary:
        with open(github_summary, "a", encoding="utf-8") as handle:
            handle.write(md + "\n")

    history_path = Path(args.history_file) if args.history_file else report_dir.parent / "quality-history.jsonl"
    if not history_path.is_absolute():
        history_path = ROOT / history_path
    history_path.parent.mkdir(parents=True, exist_ok=True)
    history_record = {
        "finishedAt": report["finishedAt"],
        "mode": report["mode"],
        "gitSha": report["git"]["sha"],
        "catalogSha256": report["catalogSha256"],
        "gateStatuses": {g["id"]: g["status"] for g in results},
        "gateDurations": {g["id"]: g["durationSeconds"] for g in results},
        **summary,
    }
    with history_path.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(history_record, separators=(",", ":")) + "\n")
    trend_dir = report_dir.parent
    subprocess.run([sys.executable, str(ROOT / "tools" / "quality-trends.py"), "--history", str(history_path), "--out-dir", str(trend_dir), "--mode", args.mode], cwd=ROOT, check=False)

    print(f"Summary: {summary['status'].upper()} {summary['passed']}/{summary['total']} in {summary['durationSeconds']:.2f}s")
    if failed:
        print("Failed gates: " + ", ".join(g["id"] for g in results if g["status"] != "pass"))
    print(f"Report: {report_dir / 'quality-report.md'}")
    print(f"JUnit: {report_dir / 'quality-junit.xml'}")
    print(f"History: {history_path}")
    return 0 if summary["status"] == "pass" else 1


if __name__ == "__main__":
    sys.exit(main())

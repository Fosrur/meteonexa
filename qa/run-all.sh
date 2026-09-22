#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
run_py(){ python3 "$ROOT/qa/$1" "${@:2}"; }
run_php(){ php "$ROOT/qa/$1" "${@:2}"; }
echo "== MeteoNexa 20.1 QA =="
run_py release_audit.py "$ROOT"
run_py architecture_layout_smoke.py "$ROOT"
run_py maintenance_release_smoke.py "$ROOT"
run_py automatic_deploy_contract_smoke.py
run_py no_legacy_release_refs.py
run_py asset_contract.py
run_py model_consistency_smoke.py
run_py traffic_analytics_removed_smoke.py
run_py radar_global_proxy_smoke.py
run_py server_model_source_smoke.py
run_py feature_regression_smoke.py
run_py runtime_lifecycle_guest_smoke.py
run_py runtime_user_regressions_smoke.py
run_py dependency_lock_smoke.py
run_py workflow_supply_chain_smoke.py "$ROOT"
run_py backup_restore_contract_smoke.py
run_py release_flow_contract_smoke.py
run_py release_preflight_contract_smoke.py
run_py production_readiness_smoke.py "$ROOT"
run_py release_provenance_smoke.py "$ROOT"
run_py roadmap_contract_smoke.py
run_py roadmap_20_1_completion_smoke.py
run_py watch_plan_smoke.py
run_py copilot_orchestration_smoke.py
run_php copilot_decision_smoke.php
run_py smtp_auth_plain_smoke.py
run_php engine_smoke.php
run_php severe_weather_engine_smoke.php
run_php intelligence_quality_smoke.php
run_php forecast_reliability_smoke.php
run_php nowcast_fusion_smoke.php
run_php probabilistic_nowcast_smoke.php
run_php radar3_production_gate_smoke.php
run_php weather_replay_smoke.php
run_php decision_route_account_sync_smoke.php
run_php privacy_proof_smoke.php
run_py qa_admin_provision_smoke.py "$ROOT"
run_py p0_security_refactor_smoke.py "$ROOT"
run_py p1_maintainability_smoke.py "$ROOT"
run_py p2_toolchain_smoke.py "$ROOT"
node "$ROOT/qa/p2_esm_runtime_smoke.mjs"
node "$ROOT/qa/p2_core_esm_smoke.mjs"
node "$ROOT/qa/p2_domain_esm_smoke.mjs"
node "$ROOT/qa/p3_dependency_graph_smoke.mjs"
node "$ROOT/qa/p3_dependency_injection_smoke.mjs"
node "$ROOT/qa/p3_shell_decomposition_smoke.mjs"
node "$ROOT/qa/p3_final_architecture_smoke.mjs"
run_py p4_platform_hardening_smoke.py "$ROOT"
run_py p4_backend_maintainability_smoke.py "$ROOT"
run_py p4_css_architecture_smoke.py "$ROOT"
run_py p4_i18n_semantic_smoke.py "$ROOT"
run_py p4_production_build_smoke.py "$ROOT"
run_py p5_audit_performance_smoke.py "$ROOT"
run_py p6_production_ux_security_smoke.py "$ROOT"
run_py release_final_smoke.py "$ROOT"
run_py privacy_article13_smoke.py
run_py i18n_ai_home_alert_smoke.py
run_py i18n_reference_smoke.py
run_py i18n_hardcoded_copy_smoke.py "$ROOT"
run_py i18n_runtime_literal_smoke.py "$ROOT"
run_py p2_release_quality_smoke.py "$ROOT"
run_py policy_alignment_smoke.py
run_py hardcode_contract_smoke.py
run_py mobile_bootstrap_smoke.py
run_py mobile_guest_alerts_private_smoke.py
run_py golden_auth_legacy_smoke.py
run_php sql_rewrite_smoke.php
echo "MeteoNexa 20.1 QA PASS"
echo "Browser E2E: cd qa && npm ci && npx playwright install chromium firefox && npm run test:e2e"

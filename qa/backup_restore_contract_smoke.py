#!/usr/bin/env python3
from pathlib import Path
import sys
ROOT=Path(__file__).resolve().parents[1]
read=lambda p:(ROOT/p).read_text(encoding='utf-8')
compose=read('docker-compose.yml'); staging=read('docker/staging-up.sh'); backup=read('docker/backup-production.sh')
restore=read('docker/restore-production-backup.sh'); drill=read('docker/verify-backup-restore.sh'); wrapper=read('docker/restore-production.sh')
checks={
 'runtime is parameterized host bind for web+worker': compose.count('${METEONEXA_RUNTIME_DIR:-./runtime}:/var/lib/meteonexa')>=2,
 'mysql remains a named volume':'meteonexa_mysql:/var/lib/mysql' in compose and 'meteonexa_mysql:' in compose,
 'backup includes runtime mysql metadata and checksum':all(x in backup for x in ('runtime.tar.gz','mysql.sql.gz','metadata.txt','SHA256SUMS','sha256sum')),
 'restore consumes the canonical compressed backup format':all(x in restore for x in ('METEONEXA_RESTORE_CONFIRM','verify-backup-restore.sh','runtime.tar.gz','mysql.sql.gz')),
 'legacy restore command delegates to canonical restore':'restore-production-backup.sh' in wrapper,
 'staging uses exactly production compose with isolated runtime':all(x in staging for x in ('docker compose --env-file','COMPOSE_PROJECT_NAME','METEONEXA_RUNTIME_DIR','./runtime-staging')) and '-f docker-compose' not in staging,
 'no divergent staging compose exists':not (ROOT/'docker-compose.staging.yml').exists(),
 'restore drill imports into real MySQL 8.4 and checks schema 28':all(x in drill for x in ('mysql:8.4','gzip -dc','schema_version','[ "$SCHEMA" = "28" ]')),
}
failed=[k for k,v in checks.items() if not v]
for k,v in checks.items(): print(f"[{'OK' if v else 'FAIL'}] {k}")
if failed: print('Backup/restore contract FAILED: '+', '.join(failed),file=sys.stderr);sys.exit(1)
print('Backup/restore contract PASS')

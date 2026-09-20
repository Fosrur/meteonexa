<?php
declare(strict_types=1);

return [
    'version' => 27,
    'name' => 'semantic-i18n-keys',
    'drivers' => ['mysql', 'sqlite'],
    'up' => static function (PDO $pdo, int $schemaVersion, string $driver): int {
        if ($schemaVersion >= 27) {
            return $schemaVersion;
        }

        $mapPath = dirname(__DIR__, 2) . '/install/i18n-key-map-20.1.json';
        $raw = is_file($mapPath) ? file_get_contents($mapPath) : false;
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        $mapping = is_array($payload) && is_array($payload['mapping'] ?? null) ? $payload['mapping'] : null;
        if (!is_array($mapping) || count($mapping) !== 1221) {
            throw new RuntimeException('I18N_SEMANTIC_KEY_MAP_INVALID');
        }

        $read = $pdo->prepare('SELECT locale, translation, updated_at FROM translations WHERE text_key=:old_key');
        $delete = $pdo->prepare('DELETE FROM translations WHERE text_key=:old_key');
        $upsertSql = $driver === 'mysql'
            ? 'INSERT INTO translations(locale,text_key,translation,updated_at) VALUES(:locale,:new_key,:translation,:updated_at) '
                . 'ON DUPLICATE KEY UPDATE translation=VALUES(translation),updated_at=VALUES(updated_at)'
            : 'INSERT INTO translations(locale,text_key,translation,updated_at) VALUES(:locale,:new_key,:translation,:updated_at) '
                . 'ON CONFLICT(locale,text_key) DO UPDATE SET translation=excluded.translation,updated_at=excluded.updated_at';
        $upsert = $pdo->prepare($upsertSql);

        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
        }
        try {
            foreach ($mapping as $oldKey => $newKey) {
                if (!is_string($oldKey) || !is_string($newKey)
                    || preg_match('/^(?:ui|code)\.[0-9a-f]{12,}$/', $oldKey) !== 1
                    || preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/', $newKey) !== 1) {
                    throw new RuntimeException('I18N_SEMANTIC_KEY_MAP_INVALID');
                }

                $read->execute([':old_key' => $oldKey]);
                $rows = $read->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $upsert->execute([
                        ':locale' => (string)$row['locale'],
                        ':new_key' => $newKey,
                        ':translation' => (string)$row['translation'],
                        ':updated_at' => (string)($row['updated_at'] ?: gmdate('c')),
                    ]);
                }
                if ($rows) {
                    $delete->execute([':old_key' => $oldKey]);
                }
            }

            meteonexa_write_schema_version($pdo, 27);
            if ($startedTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $error) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        return 27;
    },
];

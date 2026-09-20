<?php
declare(strict_types=1);

/**
 * MeteoNexa database facade.
 *
 * Keep this file as the stable include used by endpoints/tests. Concrete
 * responsibilities live under api/database/ so connection, schema, crypto,
 * SMTP/AI settings and security-state maintenance can evolve independently.
 */
require_once __DIR__ . '/storage_helpers.php';
require_once __DIR__ . '/backend_i18n.php';
require_once __DIR__ . '/email_templates.php';

require_once __DIR__ . '/database/driver.php';
require_once __DIR__ . '/database/crypto.php';
require_once __DIR__ . '/database/schema.php';
require_once __DIR__ . '/database/migrations.php';
require_once __DIR__ . '/database/connection.php';
require_once __DIR__ . '/database/metadata.php';
require_once __DIR__ . '/database/smtp.php';
require_once __DIR__ . '/database/ai.php';
require_once __DIR__ . '/database/security.php';

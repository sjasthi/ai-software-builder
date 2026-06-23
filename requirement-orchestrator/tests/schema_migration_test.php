<?php
/**
 * =============================================================================
 *  schema_migration_test.php
 *  Week 6 / FP4  —  Database Scaffolding & System Initialization
 *  Owner: Port (Kenan)
 * =============================================================================
 *
 *  WHAT THIS VERIFIES (the FP4 "Verification Proof" for the schema):
 *    1. The migration creates the three core tables:
 *         sessions, conversation_log, domain_state
 *    2. The session token column enforces a UNIQUE constraint
 *         (a duplicate token insert must be rejected by the database).
 *    3. The domain_state JSON column round-trips an 8-domain object
 *         (write -> read -> decode -> deep-equality, zero data loss).
 *
 *  INTEGRATION — uses the team's real files, no duplication:
 *    config/database.php (Cox)   -> getDB(): PDO   (shared connection)
 *    config/local.php    (team)  -> putenv()s the DB_* credentials (gitignored)
 *    config/schema.sql   (Jaffer)-> the migration, executed against a TEST db
 *
 *  The test is schema-aware of Jaffer's design: children link to sessions by
 *  the numeric session_id (FK), tokens are CHAR(64) with a length CHECK, and
 *  the JSON lives in a column the test auto-detects. It resolves foreign keys
 *  automatically, so it adapts if column names shift.
 *
 *  SAFETY: runs only against a dedicated "<DB_NAME>_test" database, which it
 *  drops & recreates each run. A guard aborts before any DDL if the connection
 *  is ever not on a *_test database — real/dev data can never be touched.
 *
 *  HOW TO RUN (Windows / XAMPP), from the requirement-orchestrator/ folder:
 *      C:\xampp\php\php.exe tests\schema_migration_test.php
 *
 *  Credentials come from config/local.php (or the environment):
 *      DB_HOST (localhost)  DB_NAME (requirement_orchestrator)
 *      DB_USER (root)       DB_PASS ('')   Optional: DB_PORT, RO_TEST_DB.
 *
 *  EXIT CODES:  0 = all passed | 1 = a failure | 2 = blocked (missing artifact)
 *  Requires PHP 8.0+ and the pdo_mysql extension (bundled with XAMPP).
 * =============================================================================
 */

declare(strict_types=1);
error_reporting(E_ALL);

const TABLES = ['sessions', 'conversation_log', 'domain_state'];

$ROOT        = dirname(__DIR__);
$CONFIG_FILE = $ROOT . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php'; // Cox
$LOCAL_FILE  = $ROOT . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'local.php';    // team credentials
$SCHEMA_FILE = $ROOT . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'schema.sql';   // Jaffer (repo location)

// The canonical 8-domain object used for the JSON round-trip assertion.
$DOMAIN_SAMPLE = [
    'pain_points'       => 'COVERED',
    'data_sources'      => 'OPEN',
    'data_access'       => 'COVERED',
    'end_result'        => 'OPEN',
    'stakeholders'      => 'COVERED',
    'audience_type'     => 'OPEN',
    'current_process'   => 'COVERED',
    'interaction_model' => 'OPEN',
];

// ----------------------------------------------------------------------------
// Tiny test harness (no external dependency on purpose — easy for anyone to run)
// ----------------------------------------------------------------------------
$RESULTS = ['pass' => 0, 'fail' => 0, 'skip' => 0];

function line(string $tag, string $msg): void { echo str_pad($tag, 8) . $msg . PHP_EOL; }
function check(bool $cond, string $name, string $detail = ''): bool {
    global $RESULTS;
    if ($cond) { $RESULTS['pass']++; line('[PASS]', $name); }
    else       { $RESULTS['fail']++; line('[FAIL]', $name . ($detail !== '' ? "  -> $detail" : '')); }
    return $cond;
}
function skip(string $name, string $why): void {
    global $RESULTS; $RESULTS['skip']++; line('[SKIP]', "$name  -> $why");
}
function info(string $msg): void { line('[INFO]', $msg); }
function finish(): never {
    global $RESULTS;
    echo str_repeat('-', 62) . PHP_EOL;
    printf("RESULT: %d passed, %d failed, %d skipped%s",
        $RESULTS['pass'], $RESULTS['fail'], $RESULTS['skip'], PHP_EOL);
    if ($RESULTS['fail'] > 0)                       exit(1);
    if ($RESULTS['pass'] === 0 && $RESULTS['skip']) exit(2);
    exit(0);
}

echo "=== Schema Migration Test (FP4 / Week 6) — owner: Port ===" . PHP_EOL;
echo str_repeat('-', 62) . PHP_EOL;

// ----------------------------------------------------------------------------
// Step 0 — Resolve configuration (same DB_* source Cox's getDB() reads).
// ----------------------------------------------------------------------------
if (is_file($LOCAL_FILE)) { require_once $LOCAL_FILE; } // putenv()s the DB_* creds

$DB_HOST = getenv('DB_HOST') ?: 'localhost';
$DB_USER = getenv('DB_USER') ?: 'root';
$DB_PASS = getenv('DB_PASS'); if ($DB_PASS === false) { $DB_PASS = ''; }
$DB_PORT = getenv('DB_PORT') ?: '';
$BASE_DB = getenv('DB_NAME') ?: 'requirement_orchestrator';
$TEST_DB = getenv('RO_TEST_DB') ?: ($BASE_DB . '_test');

// Hard safety rail: the test only ever runs against a *_test database.
if (!str_ends_with($TEST_DB, '_test')) {
    check(false, 'SAFETY: test database name ends in "_test"', "refusing to run against `$TEST_DB`");
    finish();
}

// ----------------------------------------------------------------------------
// Step 1 — Bootstrap: drop & recreate the isolated test database for a clean
//          slate (handles every table the schema defines, not just the core 3).
// ----------------------------------------------------------------------------
try {
    $bootDsn = "mysql:host=$DB_HOST" . ($DB_PORT !== '' ? ";port=$DB_PORT" : '') . ";charset=utf8mb4";
    $boot = new PDO($bootDsn, $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $boot->exec("DROP DATABASE IF EXISTS `$TEST_DB`");                 // safe: name validated *_test
    $boot->exec("CREATE DATABASE `$TEST_DB`
                 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    info("Fresh test database ready: `$TEST_DB` on $DB_HOST.");
} catch (PDOException $e) {
    check(false, 'Connect to MySQL server', $e->getMessage());
    info('Is MySQL running? If root needs a password, set DB_PASS in config/local.php.');
    finish();
}

// Point Cox's getDB() at the isolated test DB BEFORE its first call.
putenv("DB_NAME=$TEST_DB");
$_ENV['DB_NAME'] = $TEST_DB;

// ----------------------------------------------------------------------------
// Step 2 — Retrieve the connection through Cox's config/database.php (getDB()).
// ----------------------------------------------------------------------------
if (!is_file($CONFIG_FILE)) { skip('config/database.php (Cox) present', "not found at $CONFIG_FILE"); finish(); }
require_once $CONFIG_FILE;
if (!function_exists('getDB')) {
    check(false, 'config/database.php exposes getDB()', 'getDB() is not defined');
    finish();
}
try { $pdo = getDB(); }
catch (PDOException $e) { check(false, 'getDB() establishes a connection', $e->getMessage()); finish(); }
check($pdo instanceof PDO, 'config/database.php getDB() returns a PDO');

try {
    check((int)$pdo->query('SELECT 1')->fetchColumn() === 1, 'getDB() connection is usable (SELECT 1)');
} catch (PDOException $e) { check(false, 'getDB() connection is usable (SELECT 1)', $e->getMessage()); finish(); }

// SAFETY GUARD — confirm getDB() actually landed on the isolated test DB.
$currentDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
if (!str_ends_with($currentDb, '_test')) {
    check(false, 'SAFETY: connected to an isolated *_test database',
          "getDB() connected to `$currentDb` — refusing to run destructive test");
    finish();
}
check(true, "SAFETY: connected to isolated test DB `$currentDb`");

// ----------------------------------------------------------------------------
// Step 3 — Apply Jaffer's real migration (config/schema.sql) to the test DB.
// ----------------------------------------------------------------------------
if (!is_file($SCHEMA_FILE)) {
    skip('Apply config/schema.sql', "not found at $SCHEMA_FILE (Jaffer's deliverable)");
    finish();
}
try {
    foreach (split_sql((string)file_get_contents($SCHEMA_FILE)) as $stmt) {
        if (preg_match('/^\s*(USE|CREATE\s+DATABASE|DROP\s+DATABASE)\b/i', $stmt)) { continue; }
        $pdo->exec($stmt);
    }
    check(true, 'Apply config/schema.sql (migration executes without error)');
} catch (PDOException $e) {
    check(false, 'Apply config/schema.sql (migration executes without error)', $e->getMessage());
    finish();
}

// ----------------------------------------------------------------------------
// Step 4 — Assertion A: the three core tables exist (+ expected columns).
// ----------------------------------------------------------------------------
$existing = $pdo->query(
    "SELECT table_name FROM information_schema.tables WHERE table_schema = " . $pdo->quote($TEST_DB)
)->fetchAll(PDO::FETCH_COLUMN);
$existing = array_map('strtolower', $existing);

foreach (TABLES as $t) { check(in_array($t, $existing, true), "Table `$t` exists"); }
if (array_diff(TABLES, $existing)) { finish(); }

$colCache = [];
foreach (TABLES as $t) { $colCache[$t] = column_meta($pdo, $TEST_DB, $t); }

check(has_col($colCache['sessions'], 'session_token') || (bool)find_token_col($colCache['sessions']),
      'sessions has a session-token column');
check(has_col($colCache['conversation_log'], 'role')
      && (has_col($colCache['conversation_log'], 'message')
          || has_col($colCache['conversation_log'], 'content')),
      'conversation_log has role + message/content columns');
$jsonCol = find_json_col($colCache['domain_state']);
check($jsonCol !== null, 'domain_state has a JSON column', 'expected a JSON column');

// ----------------------------------------------------------------------------
// Step 5 — Assertion B: UNIQUE constraint on the session token.
// ----------------------------------------------------------------------------
$tokenCol = has_col($colCache['sessions'], 'session_token')
    ? 'session_token' : find_token_col($colCache['sessions']);

if ($tokenCol === null) {
    skip('UNIQUE session-token constraint', 'could not identify the token column');
} else {
    $token = make_token(); // 64 hex chars — satisfies CHAR(64) + length CHECK
    try {
        insert_row($pdo, $TEST_DB, 'sessions', $colCache, [$tokenCol => $token]);
    } catch (PDOException $e) {
        check(false, 'UNIQUE session-token constraint (baseline insert)', $e->getMessage());
        finish();
    }
    try {
        insert_row($pdo, $TEST_DB, 'sessions', $colCache, [$tokenCol => $token]); // duplicate
        check(false, 'UNIQUE session-token constraint rejects duplicates',
              'duplicate token was accepted (no UNIQUE index?)');
    } catch (PDOException $e) {
        $isDup = ($e->getCode() === '23000') || str_contains($e->getMessage(), 'Duplicate');
        check($isDup, 'UNIQUE session-token constraint rejects duplicates',
              'insert failed but not with a duplicate-key error: ' . $e->getMessage());
    }
}

// ----------------------------------------------------------------------------
// Step 6 — Assertion C: JSON round-trip in domain_state (zero data loss).
//          insert_row auto-resolves the session_id foreign key.
// ----------------------------------------------------------------------------
if ($jsonCol === null) {
    skip('domain_state JSON round-trip', 'no JSON column identified');
} else {
    try {
        $json = json_encode($DOMAIN_SAMPLE, JSON_THROW_ON_ERROR);
        $row  = insert_row($pdo, $TEST_DB, 'domain_state', $colCache, [$jsonCol => $json]);

        $pk   = primary_key($colCache['domain_state']) ?? $jsonCol;
        $stmt = $pdo->prepare("SELECT `$jsonCol` FROM `domain_state` WHERE `$pk` = ? LIMIT 1");
        $stmt->execute([$row['id']]);
        $stored = $stmt->fetchColumn();

        // MySQL's JSON type normalizes key order, so compare order-independently
        // (sort keys on both sides) — we're verifying values survive, not order.
        $decoded  = json_decode((string)$stored, true);
        $expected = $DOMAIN_SAMPLE;
        if (is_array($decoded)) { ksort($decoded); }
        ksort($expected);
        $intact = is_array($decoded)
                  && count($decoded) === count($DOMAIN_SAMPLE)
                  && $decoded === $expected;

        check($intact, 'domain_state JSON round-trip (8 domains, values intact)',
              'retrieved: ' . var_export($decoded, true));
    } catch (PDOException | JsonException $e) {
        check(false, 'domain_state JSON round-trip (8 domains, exact match)', $e->getMessage());
    }
}

finish();


// =============================================================================
// Helpers
// =============================================================================

/** A 64-hex-char token (satisfies CHAR(64) and a CHAR_LENGTH = 64 CHECK). */
function make_token(): string { return bin2hex(random_bytes(32)); }

/** Naive-but-sufficient SQL splitter: strips comments, splits on ';'. */
function split_sql(string $sql): array {
    $sql = preg_replace('!/\*.*?\*/!s', '', $sql) ?? $sql;
    $out = [];
    $buf = '';
    foreach (preg_split('/\r?\n/', $sql) as $raw) {
        $trim = ltrim($raw);
        if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) { continue; }
        $buf .= $raw . "\n";
        if (preg_match('/;\s*$/', $raw)) { $out[] = trim($buf); $buf = ''; }
    }
    if (trim($buf) !== '') { $out[] = trim($buf); }
    return array_values(array_filter($out, static fn($s) => $s !== ''));
}

/** Column metadata for a table keyed by lowercase column name. */
function column_meta(PDO $pdo, string $db, string $table): array {
    $stmt = $pdo->prepare(
        "SELECT column_name, data_type, column_type, is_nullable,
                column_default, extra, column_key
         FROM information_schema.columns
         WHERE table_schema = ? AND table_name = ?"
    );
    $stmt->execute([$db, $table]);
    $meta = [];
    foreach ($stmt->fetchAll() as $r) {
        $r = array_change_key_case($r, CASE_LOWER);
        $meta[strtolower($r['column_name'])] = $r;
    }
    return $meta;
}

/** Foreign keys for a table: [localCol => [refTable, refCol]]. */
function fk_map(PDO $pdo, string $db, string $table): array {
    $stmt = $pdo->prepare(
        "SELECT column_name, referenced_table_name, referenced_column_name
         FROM information_schema.key_column_usage
         WHERE table_schema = ? AND table_name = ? AND referenced_table_name IS NOT NULL"
    );
    $stmt->execute([$db, $table]);
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $r = array_change_key_case($r, CASE_LOWER);
        $map[strtolower($r['column_name'])] = [$r['referenced_table_name'], $r['referenced_column_name']];
    }
    return $map;
}

function has_col(array $cols, string $name): bool { return isset($cols[strtolower($name)]); }

/** Finds a column that looks like a session-token, if any. */
function find_token_col(array $cols): ?string {
    foreach (['session_token', 'token'] as $cand) {
        if (isset($cols[$cand])) { return $cols[$cand]['column_name']; }
    }
    foreach ($cols as $name => $_) {
        if (str_contains($name, 'token')) { return $cols[$name]['column_name']; }
    }
    return null;
}

/** Finds the JSON column (native json, or text used to store JSON). */
function find_json_col(array $cols): ?string {
    foreach ($cols as $c) { if ($c['data_type'] === 'json') { return $c['column_name']; } }
    foreach ($cols as $name => $c) {
        if (in_array($c['data_type'], ['longtext', 'text', 'mediumtext'], true)
            && (str_contains($name, 'json') || str_contains($name, 'state') || str_contains($name, 'domain'))) {
            return $c['column_name'];
        }
    }
    return null;
}

function primary_key(array $cols): ?string {
    foreach ($cols as $c) { if (strtoupper((string)$c['column_key']) === 'PRI') { return $c['column_name']; } }
    return null;
}

/**
 * Inserts a row, supplying values only for columns that REQUIRE one
 * (NOT NULL, no default, not auto-increment) merged with $overrides.
 * Foreign-key columns are resolved automatically by inserting a parent row and
 * using its referenced value — so children that link by id (or token) both work.
 * Returns ['id' => lastInsertId, 'ref' => [col => value, ...]].
 */
function insert_row(PDO $pdo, string $db, string $table, array &$colCache, array $overrides, int $depth = 0): array {
    if ($depth > 5) { throw new RuntimeException("FK resolution too deep at `$table`"); }
    if (!isset($colCache[$table])) { $colCache[$table] = column_meta($pdo, $db, $table); }
    $cols = $colCache[$table];
    $fks  = fk_map($pdo, $db, $table);

    $ovr = [];
    foreach ($overrides as $k => $v) { $ovr[strtolower($k)] = $v; }

    $fields = [];
    $marks  = [];
    $values = [];

    foreach ($cols as $name => $c) {
        $isAuto     = str_contains(strtolower((string)$c['extra']), 'auto_increment');
        $generated  = str_contains(strtolower((string)$c['extra']), 'generated');
        $hasDefault = $c['column_default'] !== null;
        $nullable   = strtoupper((string)$c['is_nullable']) === 'YES';

        if (array_key_exists($name, $ovr)) {
            $fields[] = "`{$c['column_name']}`"; $marks[] = '?'; $values[] = $ovr[$name];
            continue;
        }
        if (isset($fks[$name])) {
            [$refTable, $refCol] = $fks[$name];
            $parent = insert_row($pdo, $db, $refTable, $colCache, [], $depth + 1);
            $fields[] = "`{$c['column_name']}`"; $marks[] = '?';
            $values[] = $parent['ref'][strtolower($refCol)] ?? $parent['id'];
            continue;
        }
        if ($isAuto || $generated || $hasDefault || $nullable) { continue; }
        $fields[] = "`{$c['column_name']}`"; $marks[] = '?'; $values[] = fabricate_value($c);
    }

    $sql = "INSERT INTO `$table` (" . implode(', ', $fields) . ') VALUES (' . implode(', ', $marks) . ')';
    $pdo->prepare($sql)->execute($values);
    $id = $pdo->lastInsertId();

    // Capture this row's column values so a child FK can reference any of them.
    $ref = [];
    $pk  = primary_key($cols);
    if ($pk !== null) {
        $sel = $pdo->prepare("SELECT * FROM `$table` WHERE `$pk` = ? LIMIT 1");
        $sel->execute([$id]);
        foreach (($sel->fetch(PDO::FETCH_ASSOC) ?: []) as $k => $v) { $ref[strtolower($k)] = $v; }
    }
    return ['id' => $id, 'ref' => $ref];
}

/** Produces a plausible value for a required column based on its type/name. */
function fabricate_value(array $c): mixed {
    $name    = strtolower((string)$c['column_name']);
    $type    = strtolower((string)$c['data_type']);
    $colType = strtolower((string)$c['column_type']);

    if (str_contains($name, 'token')) { return make_token(); }

    return match (true) {
        str_starts_with($type, 'enum') || str_starts_with($colType, 'enum') => enum_first($colType),
        in_array($type, ['int','bigint','smallint','tinyint','mediumint','decimal','float','double'], true) => 1,
        in_array($type, ['datetime','timestamp'], true) => date('Y-m-d H:i:s'),
        $type === 'date' => date('Y-m-d'),
        $type === 'time' => date('H:i:s'),
        $type === 'json' => '{}',
        default          => 'test_' . bin2hex(random_bytes(4)),
    };
}

/** Extracts the first allowed value from an enum(...) column type. */
function enum_first(string $colType): string {
    if (preg_match("/^enum\\((.*)\\)$/i", $colType, $m)) {
        return trim(explode(',', $m[1])[0] ?? "''", " '");
    }
    return 'USER';
}

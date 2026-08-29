<?php
/**
 * One-time, idempotent integration CONFIG bootstrap (CLI only).
 *
 *   php modules/se_core/tests/integration_config_cli.php --apply
 *   php modules/se_core/tests/integration_config_cli.php            (dry-run)
 *
 * Fixes the four screenshot defects that are DATA/CONFIG, not code:
 *   1. Create a "Meta Lead Ads" lead source and set it as the default lead
 *      source for every brand (replacing the wrong "Google" default).
 *   2. Record the Meta app owner label (the Azin Business Portfolio).
 *   3. Seed the configured WhatsApp identifiers (WABA / phone-number id /
 *      display number) so Integration Health recognises the number instead of
 *      reporting "none".
 *   4. Clear the stale/false "last successful fetch" option that the old
 *      reconcile heartbeat wrote on every cron run with no token.
 *
 * Never prints or touches a secret. Raw mysqli (no CI framework), mirroring
 * migrate_cli.php. Re-runnable: every write is guarded.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

ini_set('log_errors', '0');
ini_set('display_errors', 'stderr');

$root = realpath(__DIR__ . '/../../../') . '/';
if (!is_file($root . 'application/config/app-config.php')) {
    fwrite(STDERR, "app-config.php not found; run this on the deployed host.\n");
    exit(2);
}

define('BASEPATH', $root . 'system/');
define('APPPATH', $root . 'application/');
define('FCPATH', $root);
define('ENVIRONMENT', 'production');
require $root . 'application/config/app-config.php';

function pfx() { return defined('APP_DB_PREFIX') ? APP_DB_PREFIX : 'tbl'; }
if (!function_exists('db_prefix')) {
    function db_prefix() { return defined('APP_DB_PREFIX') ? APP_DB_PREFIX : 'tbl'; }
}

$db = []; $active_group = 'default'; $query_builder = true;
require $root . 'application/config/database.php';
$c  = $db['default'];
$my = @new mysqli($c['hostname'], $c['username'], $c['password'], $c['database']);
if ($my->connect_errno) { fwrite(STDERR, "connect failed\n"); exit(2); }
$my->set_charset('utf8mb4');

$apply = in_array('--apply', $argv, true);
$p = pfx();
echo "mode: " . ($apply ? 'APPLY' : 'DRY-RUN') . "\n";

/* ---- option helpers (tbloptions: columns name, value) -------------------
 * Production PHP's mysqli is built without mysqlnd, so mysqli_stmt::get_result()
 * is unavailable. Every value written here is a trusted constant from this
 * script (never user input), so escaped query() is safe and portable. */
function opt_get($my, $p, $name) {
    $q = $my->query("SELECT value FROM `{$p}options` WHERE name='" . $my->real_escape_string($name) . "' LIMIT 1");
    $r = $q ? $q->fetch_row() : null;
    return $r ? $r[0] : null;
}
function opt_upsert($my, $p, $name, $value, $apply) {
    $cur = opt_get($my, $p, $name);
    if ($cur === (string) $value) { echo "  option {$name}: already correct\n"; return; }
    if (!$apply) { echo "  option {$name}: WOULD set -> {$value}\n"; return; }
    $n = $my->real_escape_string($name); $v = $my->real_escape_string((string) $value);
    if ($cur === null) {
        $my->query("INSERT INTO `{$p}options` (name, value, autoload) VALUES ('{$n}','{$v}',1)");
    } else {
        $my->query("UPDATE `{$p}options` SET value='{$v}' WHERE name='{$n}'");
    }
    echo "  option {$name}: set -> {$value}\n";
}
function opt_clear($my, $p, $name, $apply) {
    $cur = opt_get($my, $p, $name);
    if ($cur === null || $cur === '') { echo "  option {$name}: already clear\n"; return; }
    if (!$apply) { echo "  option {$name}: WOULD clear (was stale)\n"; return; }
    $my->query("UPDATE `{$p}options` SET value='' WHERE name='" . $my->real_escape_string($name) . "'");
    echo "  option {$name}: cleared (removed stale false 'successful fetch')\n";
}

/* ---- brands ------------------------------------------------------------- */
$brands = [];
$res = $my->query("SELECT id, name FROM `{$p}se_brands` WHERE active=1 ORDER BY id ASC");
while ($res && $row = $res->fetch_assoc()) { $brands[] = $row; }
echo "active brands: " . count($brands) . "\n";
foreach ($brands as $b) { echo "  brand id={$b['id']} name={$b['name']}\n"; }

/* ---- 1) "Meta Lead Ads" lead source ------------------------------------ */
$srcName = 'Meta Lead Ads';
$srcEsc  = $my->real_escape_string($srcName);
$q = $my->query("SELECT id FROM `{$p}leads_sources` WHERE name='{$srcEsc}' LIMIT 1");
$srcRow = $q ? $q->fetch_row() : null;
$srcId = $srcRow ? (int) $srcRow[0] : 0;

if ($srcId) {
    echo "lead source 'Meta Lead Ads': exists (id={$srcId})\n";
} elseif ($apply) {
    $my->query("INSERT INTO `{$p}leads_sources` (name) VALUES ('{$srcEsc}')");
    $srcId = (int) $my->insert_id;
    echo "lead source 'Meta Lead Ads': created (id={$srcId})\n";
} else {
    echo "lead source 'Meta Lead Ads': WOULD create\n";
}

/* ---- 2) default source per brand + app owner + 4) stale clear ---------- */
opt_upsert($my, $p, 'se_meta_app_owner_label', 'Azin Business Portfolio (1360984722912404)', $apply);
opt_clear($my, $p, 'se_meta_last_reconcile_at', $apply);   // was a false heartbeat
// NOTE: se_meta_last_fetch_ok_at is intentionally left unset — no authenticated
// fetch has happened yet, so "Last successful fetch" must read as "—".

foreach ($brands as $b) {
    if ($srcId) {
        opt_upsert($my, $p, 'se_meta_default_source_' . (int) $b['id'], (string) $srcId, $apply);
    }
}

/* ---- 3) seed configured WhatsApp identifiers --------------------------- */
$waba  = '1398503638806590';
$pnid  = '1290456080816587';
$disp  = '+90 547 120 70 70';
$brandForWa = $brands ? (int) $brands[0]['id'] : 0;   // single-clinic: the one active brand

if ($brandForWa <= 0) {
    echo "whatsapp seed: SKIP (no active brand)\n";
} else {
    $pnidEsc = $my->real_escape_string($pnid);
    $wabaEsc = $my->real_escape_string($waba);
    $dispEsc = $my->real_escape_string($disp);
    $q = $my->query("SELECT id, state FROM `{$p}se_wa_numbers` WHERE phone_number_id='{$pnidEsc}' LIMIT 1");
    $waRow = $q ? $q->fetch_assoc() : null;

    if ($waRow) {
        echo "whatsapp number {$pnid}: exists (state={$waRow['state']})\n";
        if ($waRow['state'] === 'test' && $apply) {
            $id = (int) $waRow['id'];
            $my->query("UPDATE `{$p}se_wa_numbers` SET state='configured', waba_id='{$wabaEsc}', display_number='{$dispEsc}', last_updated=NOW() WHERE id={$id}");
            echo "  -> promoted to state='configured'\n";
        }
    } elseif ($apply) {
        $bid = (int) $brandForWa;
        $my->query("INSERT INTO `{$p}se_wa_numbers` (brand_id, waba_id, phone_number_id, display_number, state, date_created) VALUES ({$bid},'{$wabaEsc}','{$pnidEsc}','{$dispEsc}','configured',NOW())");
        echo "whatsapp number {$pnid}: seeded (brand {$brandForWa}, state='configured')\n";
    } else {
        echo "whatsapp number {$pnid}: WOULD seed (brand {$brandForWa})\n";
    }
}

echo "done.\n";

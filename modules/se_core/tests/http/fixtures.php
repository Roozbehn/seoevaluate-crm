<?php
/**
 * HTTP-tier framework: origin transport, real-DB access, synthetic fixtures,
 * synthetic secret files, guaranteed cleanup, and the per-case result matrix.
 *
 * Loaded ONLY by run_http.php (CLI, on the deployed host, from OUTSIDE the
 * document root). Everything synthetic is in the reserved id range
 * (>= 900000) or carries a ZZTEST marker, and is removed by se_http_cleanup()
 * which runs in a finally block, in a shutdown handler, and as the
 * `--cleanup` CLI mode.
 *
 * SAFETY
 * ------
 * - Requests go to the ORIGIN over CURLOPT_RESOLVE; any host other than the
 *   CRM aborts. One cookie jar is used so the whole run owns at most a couple
 *   of session rows, which cleanup deletes by their exact ids.
 * - DB credentials are read from the untracked app-config and never printed.
 * - Secret files are random per run, never logged, installed only for the
 *   run, and removed (with a directory-level sweep assertion) at the end.
 * - No fixture ever enables an integration, and no Page/CAPI token is ever
 *   installed, so every live-send path in the application stays gated.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

ini_set('log_errors', '0');
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);
date_default_timezone_set('UTC');

define('SE_HTTP_HOST', 'crm.roozbeh.com.tr');
define('SE_HTTP_ORIGIN', '57.129.84.98');
define('SE_HTTP_BASE', 'https://' . SE_HTTP_HOST);

/* Reserved synthetic range — far above anything production will reach. */
define('SE_HTTP_ID_BASE', 900000);
define('SE_HTTP_BRAND_A', 900101);   // distinct from run_db's 900001/900002
define('SE_HTTP_BRAND_B', 900102);

$SE_HTTP_ROOT = realpath(__DIR__ . '/../../../../') . '/';

if (!is_file($SE_HTTP_ROOT . 'application/config/app-config.php')) {
    fwrite(STDERR, "app-config.php not found; run this on the deployed host.\n");
    exit(2);
}

define('BASEPATH', $SE_HTTP_ROOT . 'system/');
define('APPPATH', $SE_HTTP_ROOT . 'application/');
define('FCPATH', $SE_HTTP_ROOT);
define('ENVIRONMENT', 'production');
define('SE_TESTING', true);
define('SE_TESTING_REAL_DB', true);

require $SE_HTTP_ROOT . 'application/config/app-config.php';

function db_prefix() { return defined('APP_DB_PREFIX') ? APP_DB_PREFIX : 'tbl'; }

/* ---------------------------------------------------------------------------
 * Real DB connection (credentials never printed).
 * ------------------------------------------------------------------------- */

$db = []; $active_group = 'default'; $query_builder = true;
require $SE_HTTP_ROOT . 'application/config/database.php';
$SE_HTTP_DBC = $db['default'];

$SE_HTTP_CONN = @new mysqli($SE_HTTP_DBC['hostname'], $SE_HTTP_DBC['username'],
    $SE_HTTP_DBC['password'], $SE_HTTP_DBC['database']);

if ($SE_HTTP_CONN->connect_errno) { fwrite(STDERR, "db connect failed\n"); exit(2); }
$SE_HTTP_CONN->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_OFF);

function se_conn() { return $GLOBALS['SE_HTTP_CONN']; }

function se_sql($sql)
{
    $r = se_conn()->query($sql);
    if ($r === false) {
        throw new RuntimeException('SQL failed: ' . se_conn()->error . ' :: ' . substr($sql, 0, 120));
    }
    return $r;
}

function se_scalar($sql)
{
    $row = se_sql($sql)->fetch_row();
    return $row ? $row[0] : null;
}

function se_count_where($table, $where = '1=1')
{
    return (int) se_scalar('SELECT COUNT(*) FROM `' . db_prefix() . $table . '` WHERE ' . $where);
}

function se_fetch_row($sql)
{
    return se_sql($sql)->fetch_assoc();
}

function se_esc($v) { return "'" . se_conn()->real_escape_string((string) $v) . "'"; }

/** COUNT(*) of EVERY table in the schema. */
function se_all_table_counts()
{
    $out = [];
    foreach (se_sql('SHOW TABLES') as $row) {
        $t = array_values($row)[0];
        $out[$t] = (int) se_scalar('SELECT COUNT(*) FROM `' . $t . '`');
    }
    return $out;
}

/**
 * The CI database-session table(s) — their rows churn with every live web
 * request. The config names the save path without a prefix ('sessions') while
 * the actual storage table is the prefixed one (tblsessions), so both names
 * are treated as session tables wherever they exist.
 */
function se_session_tables()
{
    $candidates = [db_prefix() . 'sessions', 'sessions'];

    if (defined('SESS_SAVE_PATH')) {
        $candidates[] = (string) SESS_SAVE_PATH;
        $candidates[] = db_prefix() . (string) SESS_SAVE_PATH;
    }

    $out = [];

    foreach (array_unique($candidates) as $t) {
        if (se_sql('SHOW TABLES LIKE ' . se_esc($t))->num_rows > 0) {
            $out[] = $t;
        }
    }

    return $out;
}

/* ---------------------------------------------------------------------------
 * In-process module surface over the REAL connection (run_db.php pattern),
 * so async processing (routing/parking/status transitions) can be driven
 * deterministically instead of waiting for the live cron. get_option /
 * update_option are MEMORY stubs: in-process code can never write a live
 * option row.
 * ------------------------------------------------------------------------- */

$GLOBALS['se_test'] = [
    'staff_id' => 0, 'is_admin' => false, 'permissions' => [],
    'options' => [], 'activity' => [], 'denied' => null,
];

class SeRealLoader { public function helper($x) {} public function model($x) {} public function library($x) {} public function view($x, $y = null) {} }
class SeRealCI { public $db; public $load; public function __construct($db) { $this->db = $db; $this->load = new SeRealLoader(); } }

require_once __DIR__ . '/../real_db.php';
require_once __DIR__ . '/../net_kill.php';

$GLOBALS['se_real_db'] = new SeRealDb($GLOBALS['SE_HTTP_CONN']);
$GLOBALS['se_test_ci'] = new SeRealCI($GLOBALS['se_real_db']);

function &get_instance() { return $GLOBALS['se_test_ci']; }

function get_staff_user_id() { return $GLOBALS['se_test']['staff_id']; }
function is_staff_logged_in() { return $GLOBALS['se_test']['staff_id'] > 0; }
function is_admin($staff_id = '') { return $GLOBALS['se_test']['is_admin']; }
function staff_can($cap, $feature = null, $staff_id = '')
{
    if ($GLOBALS['se_test']['is_admin']) { return true; }
    return !empty($GLOBALS['se_test']['permissions'][$feature . '.' . $cap]);
}
function staff_cant($cap, $feature = null, $staff_id = '') { return !staff_can($cap, $feature, $staff_id); }
function get_option($name) { return $GLOBALS['se_test']['options'][$name] ?? ''; }
function update_option($name, $value) { $GLOBALS['se_test']['options'][$name] = $value; return true; }
function add_option($n, $v) { return update_option($n, $v); }
function _l($k, $x = '') { return $k; }
function log_activity($m) { $GLOBALS['se_test']['activity'][] = $m; }
function is_ajax_request() { return false; }
function slug_it($s) { return strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s)); }
function to_sql_date($d, $t = false) { return $d; }
function _dt($d) { return $d; }
function html_escape($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
class SeAccessDenied extends RuntimeException {}
function access_denied($w = '') { throw new SeAccessDenied($w ?: 'denied'); }
function ajax_access_denied($w = '') { throw new SeAccessDenied($w ?: 'denied'); }
function se_core_deny() { access_denied('se_brands'); }
class SeRealHooks
{
    public function add_action($t, $f, $p = 10, $a = 1) {}
    public function add_filter($t, $f, $p = 10, $a = 1) {}
    public function do_action($t, $a = null) {}
    public function apply_filters($t, $v) { return $v; }
}
function hooks() { static $h = null; if ($h === null) { $h = new SeRealHooks(); } return $h; }
function register_staff_capabilities($f, $c, $n = null) {}
function register_language_files($m, $f) {}
function register_activation_hook($m, $f) {}
function admin_url($p = '') { return '/admin/' . $p; }
function site_url($p = '') { return '/' . $p; }
class App_Model { public $db; public function __construct() { $this->db = $GLOBALS['se_real_db']; } }

$SE_HTTP_MOD = $SE_HTTP_ROOT . 'modules/';
require_once $SE_HTTP_MOD . 'se_core/helpers/se_core_helper.php';
require_once $SE_HTTP_MOD . 'se_core/se_authz.php';
require_once $SE_HTTP_MOD . 'se_core/pipeline.php';
require_once $SE_HTTP_MOD . 'se_core/se_consent.php';
require_once $SE_HTTP_MOD . 'se_core/libraries/Se_hash.php';
require_once $SE_HTTP_MOD . 'se_core/se_outbox_snapshot.php';
require_once $SE_HTTP_MOD . 'se_core/se_outbox.php';
require_once $SE_HTTP_MOD . 'se_core/se_capi.php';
require_once $SE_HTTP_MOD . 'se_core/se_google_dm.php';
require_once $SE_HTTP_MOD . 'se_core/se_secret_provider.php';
require_once $SE_HTTP_MOD . 'se_core/se_meta_leadgen.php';
require_once $SE_HTTP_MOD . 'se_whatsapp/helpers.php';
require_once $SE_HTTP_MOD . 'se_whatsapp/outbound.php';

se_net_install_fixtures();   // any outbound-transport seam that fires is COUNTED

/* ---------------------------------------------------------------------------
 * Assertions + per-case result matrix.
 * ------------------------------------------------------------------------- */

$GLOBALS['se_assert'] = ['pass' => 0, 'fail' => 0, 'failures' => [], 'group' => ''];
$GLOBALS['se_matrix'] = [];   // suite => [ [case, status, marker, reason, note] ]

function se_group($n) { $GLOBALS['se_assert']['group'] = $n; echo "\n-- {$n}\n"; }

function se_ok($cond, $label)
{
    if ($cond) { $GLOBALS['se_assert']['pass']++; echo "   PASS  {$label}\n"; }
    else {
        $GLOBALS['se_assert']['fail']++;
        $GLOBALS['se_assert']['failures'][] = $GLOBALS['se_assert']['group'] . ' :: ' . $label;
        echo "   FAIL  {$label}\n";
    }
}

function se_eq($e, $a, $label)
{
    $ok = $e === $a;
    if (!$ok) { $label .= ' (expected ' . var_export($e, true) . ', got ' . var_export($a, true) . ')'; }
    se_ok($ok, $label);
}

function se_matrix_add($suite, $case, $r, $note = '')
{
    $GLOBALS['se_matrix'][$suite][] = [
        'case'   => $case,
        'status' => is_array($r) ? $r['code'] : $r,
        'marker' => is_array($r) ? ($r['marker'] ?? '-') : '-',
        'reason' => is_array($r) ? ($r['reason'] ?? '-') : '-',
        'note'   => $note,
    ];
}

function se_matrix_print()
{
    foreach ($GLOBALS['se_matrix'] as $suite => $rows) {
        echo "\n== RESULT MATRIX: {$suite} ==\n";
        printf("   %-44s %-6s %-10s %-18s %s\n", 'case', 'HTTP', 'marker', 'reason', 'row evidence');
        foreach ($rows as $r) {
            printf("   %-44s %-6s %-10s %-18s %s\n", $r['case'], $r['status'],
                $r['marker'] === null ? '(none)' : $r['marker'],
                $r['reason'] === null ? '(none)' : $r['reason'], $r['note']);
        }
    }
}

/* ---------------------------------------------------------------------------
 * Transport. Origin only, host-guarded, one cookie jar for the whole run.
 * ------------------------------------------------------------------------- */

define('SE_HTTP_JAR', '/home/hyundaic/_w3/A/http_cookies_' . getmypid() . '.txt');

function se_http($path, array $opt = [])
{
    $url  = SE_HTTP_BASE . $path;
    $host = parse_url($url, PHP_URL_HOST);

    if ($host !== SE_HTTP_HOST) {
        throw new RuntimeException('refusing to contact ' . $host);
    }

    @mkdir(dirname(SE_HTTP_JAR), 0700, true);

    $respHeaders = [];

    $ch = curl_init($url);
    $curl = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RESOLVE        => [SE_HTTP_HOST . ':443:' . SE_HTTP_ORIGIN],
        CURLOPT_HTTPHEADER     => $opt['headers'] ?? [],
        CURLOPT_USERAGENT      => 'se-http-tier',
        CURLOPT_COOKIEJAR      => SE_HTTP_JAR,
        CURLOPT_COOKIEFILE     => SE_HTTP_JAR,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            if (strpos($line, ':') !== false) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
            return strlen($line);
        },
    ];

    if (isset($opt['method'])) { $curl[CURLOPT_CUSTOMREQUEST] = $opt['method']; }
    if (isset($opt['body']))   { $curl[CURLOPT_POSTFIELDS] = $opt['body']; }

    curl_setopt_array($ch, $curl);

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err !== '') { throw new RuntimeException('transport: ' . $err); }

    $json = json_decode((string) $body, true);

    return [
        'code'     => $code,
        'body'     => (string) $body,
        'len'      => strlen((string) $body),
        'headers'  => $respHeaders,
        'marker'   => $respHeaders['x-se-webhook'] ?? null,
        'allow'    => $respHeaders['allow'] ?? null,
        'location' => $respHeaders['location'] ?? null,
        'reason'   => is_array($json) ? ($json['reason'] ?? null) : null,
        'ok'       => is_array($json) ? ($json['ok'] ?? null) : null,
    ];
}

function se_sign_meta($raw) { return 'sha256=' . hash_hmac('sha256', $raw, $GLOBALS['SE_HTTP_SECRET_META']); }
function se_sign_wa($raw)   { return 'sha256=' . hash_hmac('sha256', $raw, $GLOBALS['SE_HTTP_SECRET_WA']); }

/* ---------------------------------------------------------------------------
 * Synthetic secret files (random per run, never logged, always removed).
 * ------------------------------------------------------------------------- */

$GLOBALS['SE_HTTP_SECRET_DIR_CREATED'] = false;
$GLOBALS['SE_HTTP_SECRET_META']   = 'ZZTEST-' . bin2hex(random_bytes(24));
$GLOBALS['SE_HTTP_SECRET_WA']     = 'ZZTEST-' . bin2hex(random_bytes(24));
$GLOBALS['SE_HTTP_VERIFY_META']   = 'ZZTEST-VER-' . bin2hex(random_bytes(12));
$GLOBALS['SE_HTTP_VERIFY_WA']     = 'ZZTEST-VER-' . bin2hex(random_bytes(12));

function se_http_install_secrets()
{
    $dir = se_secret_dir();

    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0700, true)) {
            throw new RuntimeException('cannot create secret dir');
        }
        @chmod($dir, 0700);
        $GLOBALS['SE_HTTP_SECRET_DIR_CREATED'] = true;
    }

    $pairs = [
        'meta_app'    => $GLOBALS['SE_HTTP_SECRET_META'],
        'meta_verify' => $GLOBALS['SE_HTTP_VERIFY_META'],
        'wa_app'      => $GLOBALS['SE_HTTP_SECRET_WA'],
        'wa_verify'   => $GLOBALS['SE_HTTP_VERIFY_WA'],
    ];

    foreach ($pairs as $provider => $value) {
        $path = se_secret_path($provider);
        if ($path === null) { throw new RuntimeException('unknown provider ' . $provider); }
        if (file_put_contents($path, $value) === false) {
            throw new RuntimeException('cannot write secret file for ' . $provider);
        }
        @chmod($path, 0600);
    }

    // Sanity: the provider must read back exactly what enforcement will use.
    foreach ($pairs as $provider => $value) {
        if (se_secret_read($provider) !== $value) {
            throw new RuntimeException('provider read-back mismatch for ' . $provider);
        }
    }
}

function se_http_remove_secrets()
{
    foreach (['meta_app', 'meta_verify', 'wa_app', 'wa_verify'] as $provider) {
        $path = se_secret_path($provider);
        if ($path !== null && is_file($path)) { @unlink($path); }
    }

    $dir = se_secret_dir();

    if ($GLOBALS['SE_HTTP_SECRET_DIR_CREATED'] && is_dir($dir)) {
        @rmdir($dir);   // fails harmlessly unless empty — exactly the contract
    }
}

/** True when NO provider file (any brand suffix) remains in the secret dir. */
function se_http_secret_residue()
{
    $dir = se_secret_dir();
    $residue = [];

    if (is_dir($dir)) {
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') { continue; }
            foreach (array_keys(se_secret_providers()) as $provider) {
                if ($f === $provider || strpos($f, $provider . '_') === 0) {
                    $residue[] = $f;
                }
            }
        }
    }

    return $residue;
}

/* ---------------------------------------------------------------------------
 * Synthetic DB fixtures.
 * ------------------------------------------------------------------------- */

$GLOBALS['SE_HTTP_RUN'] = strtoupper(bin2hex(random_bytes(3)));   // per-run tag

function se_run_tag() { return $GLOBALS['SE_HTTP_RUN']; }

function se_http_install_fixtures()
{
    $p   = db_prefix();
    $tag = se_run_tag();
    $now = date('Y-m-d H:i:s');

    // Two brands, so cross-brand cases are real.
    se_sql("INSERT INTO `{$p}se_brands` (`id`,`name`,`slug`,`active`,`date_created`) VALUES ("
        . SE_HTTP_BRAND_A . ", 'ZZTEST-HTTP-A', 'zztest-http-a-{$tag}', 1, '{$now}'), ("
        . SE_HTTP_BRAND_B . ", 'ZZTEST-HTTP-B', 'zztest-http-b-{$tag}', 1, '{$now}')");

    // Meta page+form routing for brand A.
    se_sql("INSERT INTO `{$p}se_meta_forms` (`id`,`brand_id`,`page_id`,`form_id`,`form_name`,`field_map_json`,`active`,`date_created`) VALUES ("
        . (SE_HTTP_ID_BASE + 201) . ", " . SE_HTTP_BRAND_A . ", 'ZZTEST-PG-{$tag}', 'ZZTEST-FM-{$tag}', 'ZZTEST http form', NULL, 1, '{$now}')");

    // WhatsApp numbers: brand A and brand B.
    se_sql("INSERT INTO `{$p}se_wa_numbers` (`id`,`brand_id`,`waba_id`,`phone_number_id`,`display_number`,`state`,`date_created`) VALUES ("
        . (SE_HTTP_ID_BASE + 301) . ", " . SE_HTTP_BRAND_A . ", 'ZZWABA{$tag}', 'ZZPNA{$tag}', 'ZZTEST-A', 'test', '{$now}'), ("
        . (SE_HTTP_ID_BASE + 302) . ", " . SE_HTTP_BRAND_B . ", 'ZZWABA{$tag}', 'ZZPNB{$tag}', 'ZZTEST-B', 'test', '{$now}')");

    // A brand-A conversation with one SENT outbound message (mirrored thread
    // row + outbound queue row carrying the synthetic provider message id).
    se_sql("INSERT INTO `{$p}se_wa_conversations` (`id`,`brand_id`,`phone_number_id`,`wa_user_id`,`state`,`date_created`) VALUES ("
        . (SE_HTTP_ID_BASE + 401) . ", " . SE_HTTP_BRAND_A . ", 'ZZPNA{$tag}', 'ZZUSER{$tag}', 'open', '{$now}')");

    se_sql("INSERT INTO `{$p}se_wa_messages` (`id`,`conversation_id`,`brand_id`,`wamid`,`direction`,`type`,`body`,`delivery_state`,`sent_at`,`date_created`) VALUES ("
        . (SE_HTTP_ID_BASE + 501) . ", " . (SE_HTTP_ID_BASE + 401) . ", " . SE_HTTP_BRAND_A
        . ", 'wamid.ZZTEST{$tag}', 'out', 'text', 'zz synthetic', 'sent', '{$now}', '{$now}')");

    se_sql("INSERT INTO `{$p}se_wa_outbound` (`id`,`conversation_id`,`brand_id`,`kind`,`body`,`idempotency_key`,`status`,`attempts`,`fence`,`wamid`,`sent_at`,`date_created`) VALUES ("
        . (SE_HTTP_ID_BASE + 601) . ", " . (SE_HTTP_ID_BASE + 401) . ", " . SE_HTTP_BRAND_A
        . ", 'text', 'zz synthetic', 'zz-http-{$tag}', 'sent', 1, 1, 'wamid.ZZTEST{$tag}', '{$now}', '{$now}')");

    // A brand-B lead already claiming a meta_lead_id, for the cross-brand
    // routing-conflict case (the webhook event routes to brand A).
    se_sql("INSERT INTO `{$p}leads` (`id`,`brand_id`,`name`,`meta_lead_id`,`status`,`source`,`addedfrom`,`dateadded`) VALUES ("
        . (SE_HTTP_ID_BASE + 701) . ", " . SE_HTTP_BRAND_B . ", 'ZZTEST http lead', 'ZZTEST-LG-{$tag}-V', 0, 0, 0, '{$now}')");
}

/**
 * Delete ONLY reserved-range / ZZTEST fixture rows, across every table the
 * fixtures (or the webhooks they exercised) touch. Idempotent: also usable as
 * `--cleanup` after a crashed run (patterns, not remembered ids).
 */
function se_http_delete_fixture_rows()
{
    $p = db_prefix();
    $B = SE_HTTP_ID_BASE;

    $purge = [
        'se_meta_leadgen_events' => "leadgen_id LIKE 'ZZTEST%' OR page_id LIKE 'ZZTEST%' OR form_id LIKE 'ZZTEST%'",
        'se_wa_webhook_events'   => "phone_number_id LIKE 'ZZPN%' OR waba_id LIKE 'ZZWABA%' OR payload LIKE '%ZZTEST%'",
        'se_wa_messages'         => "id >= {$B} OR brand_id >= {$B} OR wamid LIKE 'wamid.ZZTEST%'",
        'se_wa_conversations'    => "id >= {$B} OR brand_id >= {$B} OR phone_number_id LIKE 'ZZPN%'",
        'se_wa_outbound'         => "id >= {$B} OR brand_id >= {$B} OR idempotency_key LIKE 'zz-http%'",
        'se_wa_numbers'          => "id >= {$B} OR brand_id >= {$B} OR phone_number_id LIKE 'ZZPN%'",
        'se_wa_metering'         => "brand_id >= {$B} OR dedup_ref LIKE '%ZZTEST%'",
        'se_meta_forms'          => "id >= {$B} OR brand_id >= {$B} OR page_id LIKE 'ZZTEST%'",
        'leads'                  => "id >= {$B} OR brand_id >= {$B} OR meta_lead_id LIKE 'ZZTEST%'",
        'se_consent_ledger'      => "brand_id >= {$B} OR rel_id >= {$B}",
        'se_conversion_outbox'   => "id >= {$B} OR brand_id >= {$B}",
        'se_brands'              => "id >= {$B} OR name LIKE 'ZZTEST%'",
    ];

    $deleted = [];

    foreach ($purge as $table => $where) {
        try {
            se_sql('DELETE FROM `' . $p . $table . '` WHERE ' . $where);
            if (se_conn()->affected_rows > 0) {
                $deleted[$table] = se_conn()->affected_rows;
            }
        } catch (Throwable $e) { /* a table may not exist in an older schema */ }
    }

    return $deleted;
}

/** Delete ONLY the session rows this run created (ids from its cookie jar). */
function se_http_delete_own_sessions()
{
    $ids = [];

    if (is_file(SE_HTTP_JAR)) {
        foreach (file(SE_HTTP_JAR, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            // curl writes HttpOnly cookies as "#HttpOnly_<domain>..." lines.
            if (strpos($line, '#HttpOnly_') === 0) { $line = substr($line, 10); }
            if ($line === '' || $line[0] === '#') { continue; }
            $parts = preg_split('/\t/', $line);
            $value = end($parts);
            if (is_string($value) && preg_match('/^[A-Za-z0-9\-,]{16,128}$/', $value)) {
                $ids[] = $value;
            }
        }
        @unlink(SE_HTTP_JAR);
    }

    if (!$ids) { return 0; }

    $in = implode(',', array_map('se_esc', array_unique($ids)));
    $n  = 0;

    foreach (se_session_tables() as $table) {
        try {
            se_sql('DELETE FROM `' . $table . '` WHERE `id` IN (' . $in . ')');
            $n += se_conn()->affected_rows;
        } catch (Throwable $e) { /* keep going */ }
    }

    return $n;
}

/* ---------------------------------------------------------------------------
 * tbloptions restore: accepted webhook POSTs stamp se_meta_last_webhook_at
 * inside the live app; the run must leave the option row exactly as found.
 * ------------------------------------------------------------------------- */

function se_http_snapshot_option($name)
{
    $row = se_fetch_row('SELECT `value` FROM `' . db_prefix() . "options` WHERE `name` = " . se_esc($name));
    return $row === null ? ['exists' => false, 'value' => null] : ['exists' => true, 'value' => $row['value']];
}

function se_http_restore_option($name, array $snap)
{
    $p = db_prefix();

    if (!$snap['exists']) {
        se_sql("DELETE FROM `{$p}options` WHERE `name` = " . se_esc($name));
        return;
    }

    se_sql("UPDATE `{$p}options` SET `value` = " . se_esc($snap['value'])
        . ' WHERE `name` = ' . se_esc($name));
}

/* ---------------------------------------------------------------------------
 * Reversible storage-failure window (RENAME TABLE, one request, restored in
 * a finally AND a shutdown handler; this file is CLI, never a transaction).
 * ------------------------------------------------------------------------- */

$GLOBALS['SE_HTTP_RENAMED'] = [];   // table => backup name, while renamed

function se_http_rename_away($table)
{
    $p = db_prefix();
    se_http_avoid_cron_window();
    se_sql("RENAME TABLE `{$p}{$table}` TO `{$p}{$table}_zzbak`");
    $GLOBALS['SE_HTTP_RENAMED'][$table] = $table . '_zzbak';
}

function se_http_rename_back($table)
{
    $p = db_prefix();

    if (!isset($GLOBALS['SE_HTTP_RENAMED'][$table])) { return; }

    se_sql("RENAME TABLE `{$p}{$table}_zzbak` TO `{$p}{$table}`");
    unset($GLOBALS['SE_HTTP_RENAMED'][$table]);
}

/** Restore ANY leftover _zzbak rename (crash recovery; also used by --cleanup). */
function se_http_restore_renames()
{
    $p = db_prefix();

    foreach (['se_meta_leadgen_events', 'se_wa_webhook_events'] as $table) {
        $bak = se_sql("SHOW TABLES LIKE '{$p}{$table}_zzbak'")->num_rows;
        if ($bak > 0) {
            $orig = se_sql("SHOW TABLES LIKE '{$p}{$table}'")->num_rows;
            if ($orig === 0) {
                se_sql("RENAME TABLE `{$p}{$table}_zzbak` TO `{$p}{$table}`");
                echo "   restored leftover rename: {$table}\n";
            } else {
                // Both exist: the bak is an orphan from a crashed run AFTER a
                // fresh table was created; leave it for a human, loudly.
                echo "   WARNING: both {$table} and {$table}_zzbak exist — manual review needed\n";
            }
        }
    }

    $GLOBALS['SE_HTTP_RENAMED'] = [];
}

/**
 * The live cron fires at minutes 3-59/15 (:03 :18 :33 :48). Never open the
 * rename window inside its first seconds, so a real cron request cannot land
 * on a renamed table.
 */
function se_http_avoid_cron_window()
{
    $deadline = time() + 90;

    while (time() < $deadline) {
        $m = (int) date('i'); $s = (int) date('s');
        $near = ($m % 15 === 3) || ($m % 15 === 2 && $s >= 55) || ($m % 15 === 4 && $s <= 10);
        if (!$near) { return; }
        sleep(3);
    }
}

/* ---------------------------------------------------------------------------
 * Master cleanup. try/finally + shutdown handler + --cleanup all end here.
 * ------------------------------------------------------------------------- */

$GLOBALS['SE_HTTP_CLEANED'] = false;
$GLOBALS['SE_HTTP_OPTION_SNAPS'] = [];

function se_http_cleanup()
{
    if ($GLOBALS['SE_HTTP_CLEANED']) { return; }
    $GLOBALS['SE_HTTP_CLEANED'] = true;

    echo "\n-- guaranteed cleanup\n";

    try { se_http_restore_renames(); } catch (Throwable $e) { echo '   rename restore: ' . $e->getMessage() . "\n"; }

    $deleted = se_http_delete_fixture_rows();
    foreach ($deleted as $t => $n) { echo "   deleted {$n} fixture row(s) from {$t}\n"; }

    $sess = se_http_delete_own_sessions();
    if ($sess > 0) { echo "   deleted {$sess} own session row(s)\n"; }

    foreach ($GLOBALS['SE_HTTP_OPTION_SNAPS'] as $name => $snap) {
        try { se_http_restore_option($name, $snap); echo "   restored option {$name}\n"; }
        catch (Throwable $e) { echo "   option restore failed: {$name}\n"; }
    }

    se_http_remove_secrets();
    echo "   synthetic secret files removed\n";
}

register_shutdown_function('se_http_cleanup');

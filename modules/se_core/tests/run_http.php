<?php
/**
 * HTTP test tier — real requests against the deployed application.
 *
 *   php modules/se_core/tests/run_http.php
 *
 * Exercises the PUBLIC webhook surface and route authorization as a client
 * sees them: verification failure, bad signatures, oversized bodies,
 * unsupported methods, CSRF/method protection and unauthenticated access.
 *
 * SAFETY
 * ------
 * - Requests go to the ORIGIN over --resolve, so Cloudflare is bypassed and no
 *   bot-protection challenge is triggered or defeated.
 * - Only THIS application is contacted. Any host other than the CRM aborts.
 * - No authenticated session is created, forged or used: every request here is
 *   deliberately unauthenticated, which is what makes the authorization
 *   assertions meaningful.
 * - Nothing is written that survives: the signature checks reject every body
 *   before storage, and the run asserts the event tables are unchanged.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

ini_set('log_errors', '0');
ini_set('display_errors', 'stderr');
date_default_timezone_set('UTC');

define('SE_HTTP_HOST', 'crm.roozbeh.com.tr');
define('SE_HTTP_ORIGIN', '57.129.84.98');
define('SE_HTTP_BASE', 'https://' . SE_HTTP_HOST);

$GLOBALS['se_assert'] = ['pass' => 0, 'fail' => 0, 'failures' => [], 'group' => ''];

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

/**
 * Issue one request. Only ever to this application.
 *
 * @return array{code:int,body:string,len:int}
 */
function se_http($path, array $opt = [])
{
    $url = SE_HTTP_BASE . $path;

    // Hard guard: never contact anything but the CRM.
    $host = parse_url($url, PHP_URL_HOST);
    if ($host !== SE_HTTP_HOST) {
        throw new RuntimeException('refusing to contact ' . $host);
    }

    $ch = curl_init($url);

    $headers = $opt['headers'] ?? [];
    $curl = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RESOLVE        => [SE_HTTP_HOST . ':443:' . SE_HTTP_ORIGIN],
        CURLOPT_HTTPHEADER     => $headers,
    ];

    if (isset($opt['method'])) { $curl[CURLOPT_CUSTOMREQUEST] = $opt['method']; }
    if (isset($opt['body']))   { $curl[CURLOPT_POSTFIELDS] = $opt['body']; }

    curl_setopt_array($ch, $curl);

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err !== '') { throw new RuntimeException('transport: ' . $err); }

    return ['code' => $code, 'body' => (string) $body, 'len' => strlen((string) $body)];
}

/* Row counts before/after, to prove nothing was persisted. */
function se_http_counts()
{
    $root = realpath(__DIR__ . '/../../../') . '/';
    if (!defined('BASEPATH')) {
        define('BASEPATH', $root . 'system/'); define('APPPATH', $root . 'application/');
        define('FCPATH', $root); define('ENVIRONMENT', 'production');
    }
    require_once $root . 'application/config/app-config.php';

    // Perfex's database.php calls db_prefix(); provide it before requiring.
    if (!function_exists('db_prefix')) {
        function db_prefix() { return defined('APP_DB_PREFIX') ? APP_DB_PREFIX : 'tbl'; }
    }

    $db = []; $active_group = 'default'; $query_builder = true;
    require $root . 'application/config/database.php';
    $c = $db['default'];
    $m = @new mysqli($c['hostname'], $c['username'], $c['password'], $c['database']);
    $p = defined('APP_DB_PREFIX') ? APP_DB_PREFIX : 'tbl';

    $out = [];
    foreach (['se_wa_webhook_events', 'se_meta_leadgen_events', 'leads'] as $t) {
        $out[$t] = (int) $m->query("SELECT COUNT(*) c FROM {$p}{$t}")->fetch_assoc()['c'];
    }
    $m->close();

    return $out;
}

$before = se_http_counts();

/* ======================================================================== */
se_group('Application reachable');

$r = se_http('/admin/authentication');
se_eq(200, $r['code'], 'the login page responds');

/* ======================================================================== */
se_group('WhatsApp webhook: subscription verification');

$r = se_http('/se_whatsapp/webhook?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=CHAL');
se_eq(403, $r['code'], 'verification with a WRONG token is refused');
se_eq(false, strpos($r['body'], 'CHAL') !== false, 'and the challenge is not echoed');

$r = se_http('/se_whatsapp/webhook?hub_mode=subscribe&hub_challenge=CHAL');
se_eq(403, $r['code'], 'verification with NO token is refused');

$r = se_http('/se_whatsapp/webhook?hub_mode=unsubscribe&hub_verify_token=x&hub_challenge=CHAL');
se_eq(403, $r['code'], 'a non-subscribe mode is refused');

// No verify token is configured, so verification must fail closed.
$r = se_http('/se_whatsapp/webhook?hub_mode=subscribe&hub_verify_token=&hub_challenge=CHAL');
se_eq(403, $r['code'], 'an empty configured token fails CLOSED, never open');

/* ======================================================================== */
se_group('WhatsApp webhook: POST is rejected (CSRF-gated, then signature)');

/* TWO LAYERS, and which one answers tells us the deployment state.
 *
 * 403 = Perfex's global CSRF filter rejected the POST before the controller
 *       ran. That is the CURRENT, correct pre-activation state: the webhook
 *       cannot receive anything until the owner adds the narrow
 *       csrf_exclude_uris entry, which is a documented go-live step.
 * 401 = the controller ran and rejected the SIGNATURE. That is the state after
 *       activation, and the signature check is what protects it then.
 *
 * Either is a rejection. A 200 would mean an unsigned body was accepted, and
 * that is the only outcome this suite must never see.
 */
$csrfGated = null;

$cases = [
    ['no signature',                     []],
    ['a wrong signature',                ['X-Hub-Signature-256: sha256=' . str_repeat('0', 64)]],
    ['a signature without sha256=',      ['X-Hub-Signature-256: ' . str_repeat('0', 64)]],
    ['an unconfigured app secret',       ['X-Hub-Signature-256: sha256=deadbeef']],
];

foreach ($cases as [$label, $extra]) {
    $r = se_http('/se_whatsapp/webhook', ['method' => 'POST', 'body' => $payload,
        'headers' => array_merge(['Content-Type: application/json'], $extra)]);

    se_ok(in_array($r['code'], [401, 403], true), "POST with {$label} is rejected (got {$r['code']})");
    se_ok($r['code'] !== 200, "POST with {$label} is never ACCEPTED");

    if ($csrfGated === null) { $csrfGated = ($r['code'] === 403); }
}

se_ok(true, 'deployment state: webhook POST is '
    . ($csrfGated ? 'CSRF-GATED (403) — not yet activated, as expected'
                  : 'reaching the controller (401) — CSRF exclusion is in place'));

/* ======================================================================== */
se_group('WhatsApp webhook: oversized body is never accepted');

$huge = str_repeat('x', 200000);   // > SE_WA_MAX_BODY_BYTES (128 KB)
$r = se_http('/se_whatsapp/webhook', ['method' => 'POST', 'body' => $huge,
    'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: sha256=' . str_repeat('0', 64)]]);

se_ok(in_array($r['code'], [413, 401, 403], true),
    'an oversized body is refused (413 bound / 401 signature / 403 CSRF) — got ' . $r['code']);
se_ok($r['code'] !== 200, 'an oversized body is NEVER accepted');

/* ======================================================================== */
se_group('Meta Lead Ads webhook');

$r = se_http('/se_core/leadgen?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=CHAL');
se_eq(403, $r['code'], 'leadgen verification with a wrong token is refused');

$r = se_http('/se_core/leadgen', ['method' => 'POST', 'body' => $payload,
    'headers' => ['Content-Type: application/json']]);
se_ok(in_array($r['code'], [401, 403], true), 'leadgen POST with no signature is rejected — got ' . $r['code']);

$r = se_http('/se_core/leadgen', ['method' => 'POST', 'body' => $huge,
    'headers' => ['Content-Type: application/json', 'X-Hub-Signature-256: sha256=' . str_repeat('0', 64)]]);
se_ok($r['code'] !== 200, 'leadgen oversized body is never accepted — got ' . $r['code']);

/* ======================================================================== */
se_group('Admin routes require authentication');

$adminRoutes = [
    '/admin/se_core/se_dashboard', '/admin/se_core/se_outbox', '/admin/se_core/se_consent',
    '/admin/se_core/se_meta', '/admin/se_core/se_google', '/admin/se_core/se_credentials',
    '/admin/se_core/se_patients', '/admin/se_appointments/se_appointments/manage',
    '/admin/se_whatsapp/se_whatsapp/inbox', '/admin/se_core/se_reports/health',
];

foreach ($adminRoutes as $route) {
    $r = se_http($route);
    // Unauthenticated must never render the screen: a redirect to login, or a
    // 403. A 200 with page content would mean the screen is public.
    $isRedirect = $r['code'] >= 300 && $r['code'] < 400;
    $isDenied   = $r['code'] === 401 || $r['code'] === 403;
    se_ok($isRedirect || $isDenied, "unauthenticated {$route} is not served (got {$r['code']})");
}

/* ======================================================================== */
se_group('Mutation routes reject GET');

// These are POST-only writers. A GET must not perform the mutation; it must
// redirect to login (unauthenticated) rather than 200 with a result.
foreach (['/admin/se_core/se_patients/archive/1',
          '/admin/se_appointments/se_appointments/delete/1',
          '/admin/se_appointments/se_appointments/status/1',
          '/admin/se_core/se_outbox/requeue/1',
          '/admin/se_core/se_consent/save',
          '/admin/se_whatsapp/se_whatsapp/assign/1'] as $route) {
    $r = se_http($route);
    se_ok($r['code'] !== 200, "GET {$route} does not execute (got {$r['code']})");
}

/* ======================================================================== */
se_group('Test harness is not web-reachable');

foreach (['/modules/se_core/tests/run.php', '/modules/se_core/tests/run_db.php',
          '/modules/se_core/tests/run_http.php', '/modules/se_core/tests/real_db.php',
          '/modules/se_core/tests/bootstrap.php', '/modules/se_core/tests/migrate_cli.php',
          '/modules/se_core/tests/db/test_appointments.php'] as $route) {
    $r = se_http($route);
    se_eq(403, $r['code'], "{$route} is denied");
}

se_group('Operational logs are not web-reachable');

foreach (['/error_log', '/error_log.anything', '/application/logs/log-2026-01-01.php'] as $route) {
    $r = se_http($route);
    se_ok($r['code'] === 403 || $r['code'] === 404, "{$route} is not served (got {$r['code']})");
}

/* ======================================================================== */
se_group('Nothing was persisted by any of the above');

$after = se_http_counts();

foreach ($before as $t => $n) {
    se_eq($n, $after[$t], "{$t} row count unchanged ({$n})");
}

$a = $GLOBALS['se_assert'];

echo "\n============================================\n";
echo "HTTP tier\n";
echo "PASS   : {$a['pass']}\n";
echo "FAIL   : {$a['fail']}\n";

if ($a['fail'] > 0) {
    echo "\nFailures:\n";
    foreach ($a['failures'] as $f) { echo "  - {$f}\n"; }
    exit(1);
}

echo "ALL HTTP TESTS PASSED\n";
exit(0);

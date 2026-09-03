<?php
/**
 * Renders the se_journey admin and patient views to static HTML for visual
 * verification (390 px / 768 px screenshots). Uses the REAL views, helpers
 * and language files over the same fake-DB fixtures as the test suite —
 * synthetic patient, GD-generated photos, no network.
 *
 *   php modules/se_core/tests/render_journey.php <out-dir> [<bootstrap.css> <style.css> <fontawesome.css>]
 *
 * Perfex's admin chrome (init_head/init_tail, form_open) is stubbed to a
 * minimal Bootstrap 3 shell so the module's markup is what gets judged.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$_SERVER['argv'][1] = $_SERVER['argv'][1] ?? '/tmp/se_journey_render';
$OUT = rtrim($argv[1], '/');
@mkdir($OUT, 0755, true);
$CSS = [];
foreach (array_slice($argv, 2) as $f) { if (is_file($f)) { $CSS[] = file_get_contents($f); } }

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/journey_fixtures.php';
if (!function_exists('to_sql_date')) { function to_sql_date($d, $t = false) { return date($t ? 'Y-m-d H:i:s' : 'Y-m-d', strtotime((string) $d)); } }
require_once dirname(dirname(__DIR__)) . '/se_appointments/models/Se_appointments_model.php';
require_once dirname(dirname(__DIR__)) . '/se_core/se_reporting.php';

/* ---- Perfex view-layer stubs ------------------------------------------ */
$GLOBALS['SE_RENDER_CSS'] = implode("\n", $CSS);
function init_head() {
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>render</title><style>'
       . $GLOBALS['SE_RENDER_CSS']
       . "\nbody{background:#f5f6f8;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;font-size:13px;color:#333}"
       . ".panel_s{background:#fff;border:1px solid #e4e7ea;border-radius:4px;margin-bottom:15px}.panel-body{padding:15px}.content{padding:12px}"
       . "#wrapper{margin-left:0!important;padding:0!important}#wrapper .content{padding:15px!important}"
       . ".mtop5{margin-top:5px}.mtop10{margin-top:10px}.mtop15{margin-top:15px}.mtop20{margin-top:20px}.mbot10{margin-bottom:10px}.mbot15{margin-bottom:15px}.mleft5{margin-left:5px}.mleft10{margin-left:10px}.no-margin{margin:0}"
       . '</style></head><body>';
}
function init_tail() { echo ''; }
function form_open($action, $attrs = []) {
    $a = '';
    foreach ((array) $attrs as $k => $v) { $a .= ' ' . $k . '="' . htmlspecialchars((string) $v, ENT_QUOTES) . '"'; }
    return '<form method="post" action="' . htmlspecialchars((string) $action, ENT_QUOTES) . '"' . $a . '><input type="hidden" name="csrf_token_name" value="render" />';
}
function form_close() { return '</form>'; }
function set_alert($t, $m) {}
function redirect($u) { throw new RuntimeException('redirect ' . $u); }
function set_status_header($c) {}

/* Language: real files (English by default; SE_RENDER_LANG=turkish for the Turkish copy gate) so every key used by the views resolves. */
$lang = [];
$SE_RENDER_LANG = preg_replace('/[^a-z]/', '', (string) getenv('SE_RENDER_LANG')) ?: 'english';
foreach (['se_core', 'se_whatsapp', 'se_appointments', 'se_journey', 'se_instagram'] as $m) {
    $f = dirname(dirname(__DIR__)) . '/' . $m . '/language/' . $SE_RENDER_LANG . '/' . $m . '_lang.php';
    if (is_file($f)) { include $f; }
}
$GLOBALS['SE_LANG'] = $lang;
// Replace the bootstrap's identity _l() with a lookup (same signature).
runkit_or_shim();
function runkit_or_shim() { /* PHP cannot redefine functions; the views call _l() which returns the key — acceptable, but we prefer labels: */ }
function se_l($k) { return $GLOBALS['SE_LANG'][$k] ?? $k; }

/* ---- Minimal CI stand-ins the views/controller touch ------------------ */
class SeRenderSecurity { public function get_csrf_token_name() { return 'csrf_token_name'; } public function get_csrf_hash() { return 'render'; } }
class SeRenderDb {
    private $db; public function __construct($db) { $this->db = $db; }
    public function __call($m, $a) { return call_user_func_array([$this->db, $m], $a); }
}

/** Load a module view like CI would ($this = a tiny object exposing input/security/db). */
class SeRenderView {
    public $input; public $security; public $db;
    public function __construct() { $this->input = $GLOBALS['se_test_ci']->input; $this->security = new SeRenderSecurity(); $this->db = $GLOBALS['se_test_ci']->db; }
    public function render($view, array $data) {
        extract($data);
        ob_start();
        include dirname(dirname(__DIR__)) . '/se_journey/views/' . $view . '.php';
        $html = ob_get_clean();
        // The bootstrap's _l() returns keys; swap them for English labels here.
        return preg_replace_callback('/\bse_(?:journey|wa|appt|core|whatsapp|back|patients|appointments|instagram|reports)[a-z0-9_]*\b/', function ($m) {
            return $GLOBALS['SE_LANG'][$m[0]] ?? $m[0];
        }, $html);
    }
}

/* ---- Fixture: a journey that has gone through review with photos ------ */
$j = se_test_journey_reviewed();
$db = se_test_db();
se_journey_media_classify((int) $db->rows('tblse_journey_media')[0]['id'], 'frontal', 10);
se_journey_media_classify((int) $db->rows('tblse_journey_media')[1]['id'], 'left', 10);
se_journey_media_classify((int) $db->rows('tblse_journey_media')[2]['id'], 'right', 10);
se_journey_review_open(se_test_journey_row(), 10);
se_journey_review_save(se_test_journey_row(), ['internal_notes' => 'Aspirin reported — clinician to confirm at consultation.', 'assigned_staff' => 10], 10);
se_journey_quote_draft(se_test_journey_row(), ['currency' => 'EUR', 'amount_min' => '1500', 'amount_max' => '2200', 'show_amount' => 1, 'valid_until' => '+30 days',
    'included' => "Ön görüşme\nİşlem\nİlk kontrol", 'excluded' => "Konaklama\nUlaşım", 'deposit_terms' => '%20 depozito, kalan işlem günü', 'recommendation' => 'procedure_after_consultation', 'internal_margin' => '35%'], 10);
se_journey_quote_request_approval((int) $db->rows('tblse_journey_quotes')[0]['id'], 10);
se_journey_seed_templates(1);
$db->tables['tblse_journey_templates'][0]['approval_status'] = 'approved';
$db->tables['tblse_journey_templates'][1]['approval_status'] = 'pending';
$db->tables['tblse_journey_templates'][2]['approval_status'] = 'rejected';
$db->tables['tblse_journey_templates'][2]['rejection_reason'] = 'INVALID_FORMAT: sample values required';
$db->seed('tblse_wa_templates', [['id' => 1, 'brand_id' => 1, 'name' => 'eyebrow_intake_resume_tr', 'language' => 'tr', 'category' => 'UTILITY', 'approval_state' => 'approved']]);

/* A second journey in aftercare with events, for the care tab. */
se_test_wa_deliver(se_test_wa_body('905000000003', SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid(), ['name' => 'Zeynep']));
$j2 = se_test_journey_row();
se_journey_transition($j2, 'procedure_completed', 'render', 'staff', 10, null, null, true);
$db->tables['tblse_journeys'][1]['procedure_at'] = date('Y-m-d H:i:s', time() - 4 * 86400);
se_journey_aftercare_start(se_journey_get_raw((int) $j2->id), 'standard', 10, date('Y-m-d H:i:s', time() - 4 * 86400));
$GLOBALS['se_test']['options']['se_journey_quiet_hours'] = '00:00-00:00';
se_journey_run_aftercare(time());
$ev = $db->rows('tblse_journey_aftercare_events');
foreach ($ev as $i => $e) { if ($e['step_key'] === 'day1') { $db->tables['tblse_journey_aftercare_events'][$i]['state'] = 'answered'; $db->tables['tblse_journey_aftercare_events'][$i]['reply_enc'] = se_journey_encrypt('Hafif şişlik var, ağrı yok.'); } }
se_journey_task($j2, 'followup_unanswered', 'No reply to "3. gün" — call or message the patient', 'normal', null, 'day3');

/* ---- Render admin screens through the controller's data assembly ------ */
$r = new SeRenderView();
se_test_act_as(10, [], true);

function journey_view_data($j, $tab, $r) {
    $data = ['title' => 'x', 'j' => $j, 'tab' => $tab];
    $data['tasks'] = se_journey_open_tasks(20, (int) $j->id);
    $data['staff'] = se_appt_selectable_staff((int) $j->brand_id);
    $data['consent'] = se_journey_consent_state($j);
    $data['can'] = []; foreach (array_keys(se_journey_capabilities()) as $cap) { $data['can'][$cap] = true; }
    $data['checklist'] = se_journey_media_checklist($j);
    $data['intake'] = se_journey_intake_get($j);
    $data['answers'] = []; $data['fields'] = []; $data['sections'] = [];
    if ($tab === 'intake' && $data['intake']) { $data['answers'] = se_journey_intake_answers($data['intake']); $data['fields'] = se_journey_fields(1); $data['sections'] = se_journey_questionnaire()['sections']; }
    $data['media'] = [];
    if ($tab === 'photos') { foreach (se_journey_media_list($j) as $m) { $m['view_url'] = 'data:image/jpeg;base64,' . base64_encode(se_journey_media_read((object) $m)); $data['media'][] = $m; } $data['retake_reasons'] = se_journey_retake_reasons(); }
    if ($tab === 'review') { $data['review'] = se_journey_review_get($j); $data['quote'] = se_journey_quote_latest($j); $data['quotes'] = []; $data['decisions'] = se_journey_review_decisions(); $data['flags'] = $data['intake'] ? (json_decode((string) $data['intake']->flags_json, true) ?: []) : []; $data['amount_policy'] = 'range'; }
    if ($tab === 'care') {
        $CI = &get_instance(); $CI->db->where('rel_id', (int) $j->lead_id)->where('brand_id', 1); $data['appointments'] = $CI->db->get('tblse_appointments')->result_array();
        $data['aftercare'] = se_journey_aftercare_events($j); $data['protocols'] = se_journey_aftercare_protocols(1); $data['preop'] = se_journey_preop_checklist(1);
    }
    // Same shape as Se_journey::view() after Wave 4: human timeline, header facts, next action.
    $CI = &get_instance();
    $CI->db->where('id', (int) $j->lead_id);
    $data['lead'] = $CI->db->get('tblleads')->row_array() ?: null;
    $data['name'] = $data['lead'] && trim((string) $data['lead']['name']) !== '' ? (string) $data['lead']['name'] : (string) ($j->display_name ?? '');
    $data['phone'] = se_ui_phone($data['lead']['phonenumber'] ?? (string) $j->wa_user_id, false, false);
    $batch = se_journey_batch_context([(array) $j], null, ['next_appointment' => true]);
    $item = $batch['items'][0] ?? null;
    $data['na'] = $item ? $item['na'] : se_journey_next_action_for($j);
    $data['next_appointment'] = $item ? $item['next_appointment'] : null;
    $data['wa_failed'] = false; $data['unread'] = 0;
    $data['quote_latest'] = se_journey_quote_latest($j);
    $data['timeline'] = se_journey_timeline_human($j, 150);
    return $data;
}

$j = se_journey_get_raw(1);
foreach (['timeline' => 'journey-timeline', 'intake' => 'intake-review', 'photos' => 'photo-review', 'review' => 'quote-approval'] as $tab => $name) {
    file_put_contents($OUT . '/' . $name . '.html', $r->render('view', journey_view_data($j, $tab, $r)));
}
file_put_contents($OUT . '/aftercare-followup.html', $r->render('view', journey_view_data(se_journey_get_raw((int) $j2->id), 'care', $r)));

$CI = &get_instance();
$CI->db->where('brand_id', 1)->order_by('logical_name', 'ASC');
file_put_contents($OUT . '/template-status.html', $r->render('templates', ['title' => 'x', 'brand' => 1, 'rows' => $CI->db->get('tblse_journey_templates')->result_array(),
    'mirror' => ['eyebrow_intake_resume_tr'], 'can_submit' => true, 'test_recipients' => ['905000000002']]));

file_put_contents($OUT . '/dashboard.html', $r->render('index', ['title' => 'x', 'counters' => se_journey_dashboard_counters(), 'tasks' => se_journey_open_tasks(30),
    'filter' => ['state' => '', 'urgent' => 0], 'journeys' => se_journey_list(['limit' => 50]), 'brand' => 1, 'readiness' => se_journey_readiness(1)]));

/* Public patient pages. */
$tok = se_journey_issue_token($j, 'intake', 0);
$intake = se_journey_intake_get($j);
$GLOBALS['se_test']['get'] = [];
file_put_contents($OUT . '/patient-intake-form.html', $r->render('public/intake', [
    'token' => $tok['token'], 'j' => $j, 'allowed' => true, 'consent' => ['health_data' => true, 'marketing' => false, 'photo_publication' => false, 'whatsapp' => true, 'version' => 'kvkk-test-v1'],
    'texts' => se_journey_consent_texts(1), 'version' => 'kvkk-test-v1', 'draft' => false, 'questionnaire' => se_journey_questionnaire(), 'fields' => se_journey_fields(1),
    'answers' => [], 'submitted' => false, 'masked_phone' => '+9050 ••• •• 01', 'csrf_name' => 'csrf_token_name', 'csrf_hash' => 'render', 'base' => '/se_journey/intake/x', 'photos_url' => '/se_journey/intake/x/photos',
]));
file_put_contents($OUT . '/patient-consent.html', $r->render('public/intake', [
    'token' => $tok['token'], 'j' => $j, 'allowed' => true, 'consent' => ['health_data' => false, 'marketing' => false, 'photo_publication' => false, 'whatsapp' => true, 'version' => 'kvkk-test-v1'],
    'texts' => se_journey_consent_texts(1), 'version' => 'kvkk-test-v1', 'draft' => false, 'questionnaire' => se_journey_questionnaire(), 'fields' => se_journey_fields(1),
    'answers' => [], 'submitted' => false, 'masked_phone' => '+9050 ••• •• 01', 'csrf_name' => 'csrf_token_name', 'csrf_hash' => 'render', 'base' => '/se_journey/intake/x', 'photos_url' => '/se_journey/intake/x/photos',
]));
$q = se_journey_quote_latest($j);
file_put_contents($OUT . '/patient-quote.html', $r->render('public/quote', ['snapshot' => se_journey_quote_snapshot($q, $j)]));
file_put_contents($OUT . '/patient-photos.html', $r->render('public/photos', ['token' => 'x', 'j' => $j, 'consent_ok' => true, 'checklist' => se_journey_media_checklist($j), 'count' => 3,
    'csrf_name' => 'csrf_token_name', 'csrf_hash' => 'render', 'action' => '/x', 'followup' => false, 'results' => []]));

echo "rendered to {$OUT}\n";
foreach (glob($OUT . '/*.html') as $f) { echo '  ' . basename($f) . ' ' . filesize($f) . " bytes\n"; }

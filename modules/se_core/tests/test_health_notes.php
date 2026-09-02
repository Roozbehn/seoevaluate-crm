<?php
/**
 * Integration Health: an integration that WORKS at standard access is never
 * listed under "Blockers". The Health page showed "Meta Lead Ads operational
 * at standard access; advanced access (App Review) pending" as a blocker even
 * though the same snapshot recorded a successful live fetch. That item is now
 * an optional NOTE, in its own list, and the blocker list stays honest.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$db = se_test_db();
$db->tables = []; $db->autoinc = [];
$db->seed('tblse_brands', [['id' => 1, 'name' => 'Brand A', 'active' => 1]]);
$db->seed('tblse_wa_numbers', []);
$db->seed('tblse_ig_accounts', []);
$db->seed('tblse_meta_forms', []);
$db->seed('tblse_outbox', []);
$GLOBALS['se_test']['options'] = [];

se_test_act_as(10, [], true);

/* Review-gated WITHOUT live evidence: a real blocker, named precisely. */
update_option('se_meta_leadgen_review_gated', 1);
update_option('se_meta_leadgen_review_item', 'leads_retrieval');
se_test_install_secret('meta_page_1', 'fixture-not-a-real-token');

$h = se_integration_health(1);
$keys = array_map(function ($b) { return $b['key']; }, $h['blockers']);
se_ok(in_array('meta_leadgen_review', $keys, true),
    'without a successful live fetch, an App-Review gate on a present token IS a blocker');
se_ok(!in_array('meta_leadgen_advanced_access', $keys, true), 'and the advanced-access note is not a blocker');
se_eq([], array_map(function ($n) { return $n['key']; }, $h['notes']), 'no note while it is genuinely blocked');

/* Standard access proven operational by a live fetch: NOT a blocker. */
update_option('se_meta_leadgen_access_level', 'standard_operational');

$h = se_integration_health(1);
$keys = array_map(function ($b) { return $b['key']; }, $h['blockers']);
se_ok(!in_array('meta_leadgen_review', $keys, true), 'operational standard access is not reported as an App Review blocker');
se_ok(!in_array('meta_leadgen_advanced_access', $keys, true), 'and is not smuggled into the blocker list under another key');
se_ok(is_array($h['notes']), 'the snapshot carries a separate notes list');
$noteKeys = array_map(function ($n) { return $n['key']; }, $h['notes']);
se_eq(['meta_leadgen_advanced_access'], $noteKeys, 'the optional advanced-access follow-up lives under notes');
se_ok(stripos($h['notes'][0]['impact'], 'Nothing is blocked') === 0, 'the note says plainly that nothing is blocked');
se_ok(stripos($h['notes'][0]['action'], 'Optional') !== 0, 'the action carries no "Optional:" prefix (the view adds it once)');

/* The Meta screen's reconcile gate agrees: standard-operational is not gated. */
$st = se_meta_ui_status(1);
se_eq(false, $st['reconcile_gated'], 'reconciliation is not reported as gated at operational standard access');

se_test_remove_secret('meta_page_1');
$GLOBALS['se_test']['options'] = [];

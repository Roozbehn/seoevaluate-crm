<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Hastalar — the one patient list (CRM-M024 / UX-L01 / AZCRM-UX-002 / T10).
 *
 * One row per journey, brand-scoped and fail-closed, with the lead's name and
 * phone, the Turkish state, the next action (same engine as Bugün), unread
 * count and the next appointment. Search by name or by phone digits in any
 * formatting. Bounded: the SQL side never returns more than SE_HASTALAR_SCAN
 * rows (newest first) and the page is SE_HASTALAR_PAGE rows.
 */
const SE_HASTALAR_PAGE = 25;
const SE_HASTALAR_SCAN = 500;

/** Chip keys in display order: active (default) · attention · one per stage · closed · all. */
function se_hastalar_chips()
{
    $chips = ['active', 'attention', 'mine'];
    foreach (se_ui_stages_list() as $s) { $chips[] = $s; }
    $chips[] = 'closed';
    $chips[] = 'all';

    return $chips;
}

function se_hastalar_chip_label($chip)
{
    if (in_array($chip, se_ui_stages_list(), true)) {
        return se_ui_stage_label($chip);
    }
    $k = 'se_hastalar_chip_' . $chip;
    $t = _l($k);

    return $t === $k ? $chip : $t;
}

/** Normalise request input into a filter array. */
function se_hastalar_filters(array $in)
{
    $chip = (string) ($in['f'] ?? 'active');
    if (!in_array($chip, se_hastalar_chips(), true)) { $chip = 'active'; }
    // Bugün links with ?stage=<stage> and ?sort=attention
    if (!empty($in['stage']) && in_array((string) $in['stage'], se_ui_stages_list(), true)) { $chip = (string) $in['stage']; }
    $sort = (string) ($in['sort'] ?? '');
    if (!in_array($sort, ['attention', 'recent', 'name'], true)) { $sort = $chip === 'attention' ? 'attention' : 'recent'; }

    return [
        'q'    => trim(mb_substr((string) ($in['q'] ?? ''), 0, 80)),
        'f'    => $chip,
        'sort' => $sort,
        'page' => max(1, (int) ($in['page'] ?? 1)),
    ];
}

/** Digits of a search string, or '' when the query is a name. */
function se_hastalar_digits($q)
{
    $d = preg_replace('/\D+/', '', (string) $q);

    return strlen($d) >= 3 && strlen($d) >= mb_strlen(trim((string) $q)) / 2 ? $d : '';
}

/**
 * @return array{rows: array, total: int, page: int, pages: int, scanned: int, capped: bool}
 */
function se_hastalar_query(array $f, $now = null)
{
    $now = $now ?? time();
    $CI  = &get_instance();
    $p   = db_prefix();
    $empty = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'scanned' => 0, 'capped' => false];

    if (!$CI->db->table_exists($p . 'se_journeys')) {
        return $empty;
    }
    $terminal = se_journey_terminal_states();

    // --- search → lead ids (name or digits) and wa_user_id digits ---
    $leadIds = null;
    $digits  = se_hastalar_digits($f['q']);
    if ($f['q'] !== '') {
        if ($digits !== '') {
            $CI->db->select('id')->like("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phonenumber,' ',''),'-',''),'+',''),'(',''),')',''),'.','')", $digits, 'both');
        } else {
            $CI->db->select('id')->like('name', $f['q'], 'both');
        }
        $CI->db->limit(200);
        $leadIds = array_map(function ($r) { return (int) $r['id']; }, $CI->db->get($p . 'leads')->result_array());
    }

    // --- journeys (scoped, state chip, search) ---
    if (function_exists('se_apply_scope_in') && !se_apply_scope_in('brand_id')) {
        $CI->db->reset_query();
        return $empty;
    }
    if ($f['f'] === 'closed') {
        $CI->db->where_in('state', $terminal);
    } elseif ($f['f'] !== 'all') {
        $CI->db->where_not_in('state', $terminal);
        if (in_array($f['f'], se_ui_stages_list(), true)) {
            $states = [];
            foreach (se_ui_state_map() as $st => $m) { if ($m[0] === $f['f']) { $states[] = $st; } }
            $CI->db->where_in('state', $states ?: ['__none__']);
        }
    }
    if ($f['q'] !== '') {
        $CI->db->group_start();
        if ($digits !== '') { $CI->db->like('wa_user_id', $digits, 'both'); } else { $CI->db->like('display_name', $f['q'], 'both'); }
        if ($leadIds) { $CI->db->or_where_in('lead_id', $leadIds); }
        $CI->db->group_end();
    }
    if ($f['f'] === 'mine') {
        $CI->db->where('assigned_staff', (int) get_staff_user_id());
    }
    $CI->db->order_by('last_updated', 'DESC')->limit(SE_HASTALAR_SCAN + 1);
    $journeys = $CI->db->get($p . 'se_journeys')->result_array();
    $capped = count($journeys) > SE_HASTALAR_SCAN;
    if ($capped) { array_pop($journeys); }
    if (!$journeys) {
        return $empty;
    }

    $batch = se_journey_batch_context($journeys, $now, ['next_appointment' => true]);
    $staffIds = array_values(array_unique(array_filter(array_map(function ($j) { return (int) ($j['assigned_staff'] ?? 0); }, $journeys))));
    $staffNames = [];
    if ($staffIds) {
        $CI->db->select('staffid, firstname, lastname')->where_in('staffid', $staffIds);
        foreach ($CI->db->get($p . 'staff')->result_array() as $st) { $staffNames[(int) $st['staffid']] = trim($st['firstname'] . ' ' . $st['lastname']); }
    }
    $mask  = !se_journey_can('view_health');
    $rows  = [];
    foreach ($batch['items'] as $it) {
        $j = $it['j']; $na = $it['na'];
        $attn = se_journey_attention_row_from($it, $now);
        if ($f['f'] === 'attention' && !$attn) { continue; }
        $phoneRaw = $it['lead'] && trim((string) $it['lead']['phonenumber']) !== '' ? $it['lead']['phonenumber'] : (string) $j->wa_user_id;
        $rows[] = [
            'journey_id' => (int) $j->id, 'lead_id' => (int) $j->lead_id, 'conversation_id' => (int) $j->wa_conversation_id,
            'name' => $it['lead'] && trim((string) $it['lead']['name']) !== '' ? (string) $it['lead']['name'] : (trim((string) ($j->display_name ?? '')) !== '' ? (string) $j->display_name : ''),
            'who' => $it['name'], 'phone' => se_ui_phone($phoneRaw, $mask, false),
            'state' => (string) $j->state, 'stage' => se_ui_stage_of($j->state), 'state_label' => se_ui_state_label($j->state), 'tone' => se_ui_state_tone($j->state),
            'owner' => $na['owner'], 'priority' => $attn ? (int) $attn['priority'] : 9, 'age' => (int) $na['age'],
            'next' => $attn && $attn['key'] === 'unread' ? _l('se_na_unread') : (string) $na['sentence'], 'next_meta' => (string) ($attn ? $attn['reason'] : $na['reason']),
            'action_label' => $attn ? $attn['action_label'] : '', 'action_url' => $attn ? $attn['url'] : '',
            'unread' => $it['unread'] ? (int) $it['unread']['unread_count'] : 0,
            'next_appointment' => $it['next_appointment'] ? ['start_at' => $it['next_appointment']['start_at'], 'type' => function_exists('se_appt_type_key') ? se_appt_type_key($it['next_appointment']['appointment_type'] ?? '') : 'consultation'] : null,
            'last_updated' => (string) $j->last_updated, 'automation_state' => (string) $j->automation_state, 'urgent' => (int) $j->urgent,
            'assigned' => $staffNames[(int) ($j->assigned_staff ?? 0)] ?? '', 'source' => (string) ($j->source ?? ''),
            'url' => admin_url('se_journey/se_journey/view/' . (int) $j->id),
        ];
    }

    if ($f['sort'] === 'attention') {
        usort($rows, function ($a, $b) {
            if ($a['priority'] !== $b['priority']) { return $a['priority'] <=> $b['priority']; }
            if ($a['priority'] === 9) { return strcmp($b['last_updated'], $a['last_updated']); }
            return $b['age'] <=> $a['age'];
        });
    } elseif ($f['sort'] === 'name') {
        usort($rows, function ($a, $b) { return strcoll(mb_strtolower($a['who']), mb_strtolower($b['who'])); });
    } else {
        usort($rows, function ($a, $b) { return strcmp($b['last_updated'], $a['last_updated']); });
    }

    $total = count($rows);
    $pages = max(1, (int) ceil($total / SE_HASTALAR_PAGE));
    $page  = min($f['page'], $pages);

    return ['rows' => array_slice($rows, ($page - 1) * SE_HASTALAR_PAGE, SE_HASTALAR_PAGE), 'total' => $total, 'page' => $page, 'pages' => $pages, 'scanned' => count($journeys), 'capped' => $capped];
}

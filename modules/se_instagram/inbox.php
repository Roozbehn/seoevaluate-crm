<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Mesajlar · Instagram — inbox rows and thread paging, the same shape as the
 * WhatsApp inbox (se_whatsapp/inbox.php) so the two channels share one view
 * model: list | thread | context, bounded queries, batch-loaded names,
 * previews and journey states. An Instagram thread is tied to a journey
 * through its lead (there is no Instagram conversation id on the journey).
 */
const SE_IG_INBOX_PAGE  = 50;
const SE_IG_THREAD_PAGE = 100;

function se_ig_inbox_chips()
{
    return ['all', 'unread', 'me', 'unassigned'];
}

function se_ig_inbox_filters(array $in)
{
    $f = (string) ($in['f'] ?? 'all');
    // legacy ?assigned=me|none links
    if (($in['assigned'] ?? '') === 'me') { $f = 'me'; } elseif (($in['assigned'] ?? '') === 'none') { $f = 'unassigned'; }
    if (!in_array($f, se_ig_inbox_chips(), true)) { $f = 'all'; }

    return [
        'q'      => trim(mb_substr((string) ($in['q'] ?? ''), 0, 80)),
        'f'      => $f,
        'before' => (string) ($in['before'] ?? ''),   // last_inbound_at cursor
    ];
}

/** Journey for an Instagram conversation: through its lead, same brand. */
function se_ig_journey_for($conv)
{
    if (!function_exists('se_journey_find_by_lead') || (int) ($conv->lead_id ?? 0) <= 0) {
        return null;
    }

    return se_journey_find_by_lead((int) $conv->brand_id, (int) $conv->lead_id);
}

/**
 * @return array{rows: array, has_more: bool, next_before: string, counts: array}
 */
function se_ig_inbox_rows(array $f, $now = null)
{
    $now = $now ?? time();
    $CI  = &get_instance();
    $p   = db_prefix();
    $out = ['rows' => [], 'has_more' => false, 'next_before' => '', 'counts' => ['unread' => 0]];
    if (!$CI->db->table_exists($p . 'se_ig_conversations')) {
        return $out;
    }

    // search → lead ids (name or phone digits); an igsid tail also matches
    $leadIds = null;
    $digits  = function_exists('se_hastalar_digits') ? se_hastalar_digits($f['q']) : preg_replace('/\D+/', '', $f['q']);
    if ($f['q'] !== '') {
        if ($digits !== '') {
            $CI->db->select('id')->like("REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phonenumber,' ',''),'-',''),'+',''),'(',''),')',''),'.','')", $digits, 'both');
        } else {
            $CI->db->select('id')->like('name', $f['q'], 'both');
        }
        $CI->db->limit(200);
        $leadIds = array_map(function ($r) { return (int) $r['id']; }, $CI->db->get($p . 'leads')->result_array());
    }

    if (function_exists('se_apply_scope_in') && !se_apply_scope_in('brand_id')) {
        $CI->db->reset_query();
        return $out;
    }
    if ($f['f'] === 'unread') { $CI->db->where('unread_count >', 0); }
    elseif ($f['f'] === 'me') { $CI->db->where('assigned_staff', (int) get_staff_user_id()); }
    elseif ($f['f'] === 'unassigned') { $CI->db->where('assigned_staff', 0); }
    if ($f['q'] !== '') {
        if ($digits === '' && !$leadIds) {
            $CI->db->reset_query();
            return $out;
        }
        $CI->db->group_start();
        if ($digits !== '') { $CI->db->like('igsid', $digits, 'both'); }
        if ($leadIds) { $digits !== '' ? $CI->db->or_where_in('lead_id', $leadIds) : $CI->db->where_in('lead_id', $leadIds); }
        $CI->db->group_end();
    }
    if ($f['before'] !== '' && strtotime($f['before'])) {
        $CI->db->where('last_inbound_at <', date('Y-m-d H:i:s', strtotime($f['before'])));
    }
    $CI->db->order_by('last_inbound_at', 'DESC')->limit(SE_IG_INBOX_PAGE + 1);
    $convs = $CI->db->get($p . 'se_ig_conversations')->result_array();
    $out['has_more'] = count($convs) > SE_IG_INBOX_PAGE;
    if ($out['has_more']) { array_pop($convs); }
    if (!$convs) {
        return $out;
    }
    $out['next_before'] = (string) ($convs[count($convs) - 1]['last_inbound_at'] ?? '');

    $ids     = array_map(function ($c) { return (int) $c['id']; }, $convs);
    $leadIds = array_values(array_unique(array_filter(array_map(function ($c) { return (int) $c['lead_id']; }, $convs))));

    // lead names (one query)
    $leads = [];
    if ($leadIds) {
        $CI->db->select('id, name')->where_in('id', $leadIds);
        foreach ($CI->db->get($p . 'leads')->result_array() as $l) { $leads[(int) $l['id']] = (string) $l['name']; }
    }
    // last message per conversation (two bounded queries, exact)
    $last = [];
    if ($CI->db->table_exists($p . 'se_ig_messages')) {
        $CI->db->select('conversation_id, MAX(id) AS mid')->where_in('conversation_id', $ids)->group_by('conversation_id');
        $mids = array_map(function ($r) { return (int) $r['mid']; }, $CI->db->get($p . 'se_ig_messages')->result_array());
        if ($mids) {
            $CI->db->select('id, conversation_id, direction, type, body, received_at, sent_at, date_created, source')->where_in('id', $mids);
            foreach ($CI->db->get($p . 'se_ig_messages')->result_array() as $m) { $last[(int) $m['conversation_id']] = $m; }
        }
    }
    // journey per lead (one query) + next action
    $journeys = [];
    if ($leadIds && function_exists('se_journey_batch_context') && $CI->db->table_exists($p . 'se_journeys')) {
        $CI->db->where_in('lead_id', $leadIds);
        $jrows = $CI->db->get($p . 'se_journeys')->result_array();
        if ($jrows) {
            $batch = se_journey_batch_context($jrows, $now);
            foreach ($batch['items'] as $it) { $journeys[(int) $it['j']->lead_id] = $it; }
        }
    }

    foreach ($convs as $c) {
        $cid  = (int) $c['id'];
        $it   = $journeys[(int) $c['lead_id']] ?? null;
        $name = isset($leads[(int) $c['lead_id']]) && trim($leads[(int) $c['lead_id']]) !== '' ? se_ui_short_name($leads[(int) $c['lead_id']])
              : ($it && trim((string) ($it['j']->display_name ?? '')) !== '' ? se_ui_short_name($it['j']->display_name) : se_ig_redacted_contact((string) $c['igsid']));
        $m = $last[$cid] ?? null;
        $preview = '';
        if ($m) {
            $body = trim((string) ($m['body'] ?? ''));
            $t = (string) ($m['type'] ?? 'text');
            $preview = $t === 'image' ? '📷 ' . _l('se_ig_preview_photo') : ($t === 'audio' ? '🎤 ' . _l('se_ig_preview_audio') : ($t === 'video' ? '🎬 ' . _l('se_ig_preview_video') : (in_array($t, ['text', 'story_reply', 'story_mention', 'share'], true) ? mb_substr($body, 0, 90) : '📎 ' . _l('se_ig_media_placeholder'))));
            if (($m['direction'] ?? 'in') === 'out') { $preview = _l('se_ig_preview_you') . ': ' . $preview; }
        }
        $attn = $it && function_exists('se_journey_attention_row_from') ? se_journey_attention_row_from($it, $now) : null;
        $row = [
            'id' => $cid, 'brand_id' => (int) $c['brand_id'], 'lead_id' => (int) $c['lead_id'], 'igsid' => (string) $c['igsid'],
            'name' => $name, 'initials' => se_ui_initials($name), 'preview' => $preview, 'unread' => (int) $c['unread_count'],
            'last_at' => (string) ($c['last_inbound_at'] ?: ($m['date_created'] ?? '')), 'assigned_staff' => (int) ($c['assigned_staff'] ?? 0),
            'window_open' => !empty($c['window_expires_at']) && strtotime($c['window_expires_at']) > $now,
            'ad' => (string) ($c['referral_ad_id'] ?? ''),
            'journey_id' => $it ? (int) $it['j']->id : 0, 'state' => $it ? (string) $it['j']->state : '',
            'state_label' => $it ? se_ui_state_label($it['j']->state) : _l('se_na_new_thread'), 'tone' => $it ? se_ui_state_tone($it['j']->state) : 'info',
            'urgent' => $it ? (int) $it['j']->urgent : 0, 'attention' => $attn ? (int) $attn['priority'] : 0,
            'url' => admin_url('se_instagram/se_instagram/inbox?c=' . $cid),
        ];
        if ($row['unread'] > 0) { $out['counts']['unread']++; }
        $out['rows'][] = $row;
    }

    return $out;
}

/**
 * Thread page: the newest $limit messages (ascending for display) and the id
 * to pass back as `before` for the previous page.
 *
 * @return array{messages: array, older_before: int}
 */
function se_ig_thread_page($conversation_id, $before_id = 0, $limit = SE_IG_THREAD_PAGE)
{
    $CI = &get_instance();
    $p  = db_prefix();
    if (function_exists('se_apply_scope_in')) { se_apply_scope_in('brand_id'); }
    $CI->db->where('conversation_id', (int) $conversation_id);
    if ((int) $before_id > 0) { $CI->db->where('id <', (int) $before_id); }
    $CI->db->order_by('id', 'DESC')->limit(max(1, (int) $limit) + 1);
    $rows = $CI->db->get($p . 'se_ig_messages')->result_array();
    $more = count($rows) > $limit;
    if ($more) { array_pop($rows); }
    $rows = array_reverse($rows);

    return ['messages' => $rows, 'older_before' => $more && $rows ? (int) $rows[0]['id'] : 0];
}

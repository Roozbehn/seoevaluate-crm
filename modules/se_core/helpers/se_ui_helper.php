<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Shared UI primitives for every SE screen.
 *
 * DESIGN RULES (the compact spec these functions encode)
 * ------------------------------------------------------
 * - Reuse native Perfex/Bootstrap 3 classes only: panel_s, table, btn,
 *   label, form-group, row/col-md-*. No extra frontend framework, so the
 *   screens inherit Perfex's responsive grid and the Dark Theme for free.
 * - Never hard-code a colour. The Dark Theme restyles Perfex's own classes;
 *   a literal #fff or inline background is exactly what breaks under it.
 * - Every list has an explicit empty state. A bare empty table looks broken.
 * - Every status is a labelled badge with a stable colour mapping, so the same
 *   state reads the same on every screen.
 * - Every value that came from the database is escaped with html_escape().
 * - Brand is shown as a badge wherever a record belongs to one, because with
 *   several brands in one list the row is otherwise ambiguous.
 */

/** Standard page header with optional right-hand actions. */
function se_ui_header($title, array $actions = [], $subtitle = '')
{
    echo '<div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">';
    echo '<div class="clearfix">';
    echo '<h4 class="no-margin pull-left">' . html_escape($title);

    if ($subtitle !== '') {
        echo ' <small class="text-muted">' . html_escape($subtitle) . '</small>';
    }

    echo '</h4>';

    if ($actions) {
        echo '<div class="pull-right">';
        foreach ($actions as $a) {
            $cls = $a['class'] ?? 'btn-default';
            echo '<a href="' . html_escape($a['href']) . '" class="btn ' . $cls . ' mleft5">'
               . (isset($a['icon']) ? '<i class="fa ' . html_escape($a['icon']) . '"></i> ' : '')
               . html_escape($a['label']) . '</a>';
        }
        echo '</div>';
    }

    echo '</div></div></div></div></div>';
}

/** Stable status → Bootstrap label class mapping, shared by every screen. */
function se_ui_badge_class($status)
{
    $map = [
        // outbox / delivery
        'pending' => 'label-default', 'processing' => 'label-info',
        'submitted' => 'label-info', 'confirmed' => 'label-success',
        'sent' => 'label-success', 'failed' => 'label-danger',
        'skipped' => 'label-warning', 'gated' => 'label-warning',
        'retryable' => 'label-warning', 'permanent' => 'label-danger',
        // appointments
        'scheduled' => 'label-info', 'held' => 'label-success',
        'completed' => 'label-success', 'no_show' => 'label-danger',
        'cancelled' => 'label-default', 'confirmed_appt' => 'label-info',
        // consent
        'granted' => 'label-success', 'withdrawn' => 'label-danger',
        'unknown' => 'label-default',
        // generic
        'active' => 'label-success', 'archived' => 'label-default',
        'enabled' => 'label-success', 'disabled' => 'label-default',
        'ok' => 'label-success', 'warning' => 'label-warning', 'error' => 'label-danger',
        'open' => 'label-success', 'closed' => 'label-default',
    ];

    return $map[(string) $status] ?? 'label-default';
}

/** Status badge. $label defaults to a translated key, then to the raw status. */
function se_ui_badge($status, $label = null)
{
    if ($status === null || $status === '') {
        return '<span class="text-muted">&mdash;</span>';
    }

    $text = $label !== null ? $label : $status;

    return '<span class="label ' . se_ui_badge_class($status) . '">' . html_escape($text) . '</span>';
}

/** Brand badge. Renders the brand NAME, never a bare numeric id. */
function se_ui_brand_badge($brand_id)
{
    $brand_id = (int) $brand_id;

    if ($brand_id === 0) {
        return '<span class="label label-warning">' . html_escape(_l('se_brand_unassigned')) . '</span>';
    }

    return '<span class="label label-primary">' . html_escape(se_brand_name($brand_id)) . '</span>';
}

/** Empty state: an explanation and, where useful, the action that fixes it. */
function se_ui_empty($message, $action = null)
{
    echo '<div class="text-center" style="padding:36px 12px">';
    echo '<p class="text-muted no-margin">' . html_escape($message) . '</p>';

    if ($action) {
        echo '<p class="mtop15"><a href="' . html_escape($action['href']) . '" class="btn btn-primary">'
           . html_escape($action['label']) . '</a></p>';
    }

    echo '</div>';
}

/**
 * The screen an ordinary staff member sees when they are mapped to no brand.
 *
 * Without this they got either a SQL error or an empty table with no
 * explanation, and no way to tell a misconfiguration from "no data yet".
 */
function se_ui_no_brand_screen()
{
    echo '<div class="row"><div class="col-md-8 col-md-offset-2"><div class="panel_s"><div class="panel-body text-center" style="padding:48px 24px">';
    echo '<i class="fa fa-lock fa-3x text-muted"></i>';
    echo '<h4 class="mtop20">' . html_escape(_l('se_no_brand_title')) . '</h4>';
    echo '<p class="text-muted">' . html_escape(_l('se_no_brand_body')) . '</p>';
    echo '</div></div></div></div>';
}

/** Dashboard stat card. $href makes the whole card a link to the real screen. */
function se_ui_stat_card($label, $value, $href = null, $icon = 'fa-circle', $tone = '')
{
    $inner = '<div class="panel_s ' . html_escape($tone) . '"><div class="panel-body">'
        . '<div class="clearfix">'
        . '<span class="text-muted"><i class="fa ' . html_escape($icon) . '"></i> ' . html_escape($label) . '</span>'
        . '<h3 class="no-margin pull-right">' . html_escape((string) $value) . '</h3>'
        . '</div></div></div>';

    if ($href) {
        return '<a href="' . html_escape($href) . '" class="se-stat-link" style="text-decoration:none;display:block">' . $inner . '</a>';
    }

    return $inner;
}

/**
 * Filter bar. Renders a GET form of selects/inputs, preserving current values
 * so a filter survives pagination.
 */
function se_ui_filters($action, array $fields, array $current)
{
    echo '<form method="get" action="' . html_escape($action) . '" class="_filter_form">';
    echo '<div class="row">';

    foreach ($fields as $name => $field) {
        $value = $current[$name] ?? '';
        echo '<div class="col-md-2 col-sm-4 col-xs-6"><div class="form-group">';
        echo '<label for="f_' . html_escape($name) . '" class="control-label">' . html_escape($field['label']) . '</label>';

        if (($field['type'] ?? 'select') === 'select') {
            echo '<select class="form-control" id="f_' . html_escape($name) . '" name="' . html_escape($name) . '">';
            foreach ($field['options'] as $ov => $ol) {
                echo '<option value="' . html_escape((string) $ov) . '"'
                   . ((string) $ov === (string) $value ? ' selected' : '') . '>'
                   . html_escape($ol) . '</option>';
            }
            echo '</select>';
        } else {
            echo '<input type="' . html_escape($field['type']) . '" class="form-control"'
               . ' id="f_' . html_escape($name) . '" name="' . html_escape($name) . '"'
               . ' value="' . html_escape((string) $value) . '"'
               . (isset($field['placeholder']) ? ' placeholder="' . html_escape($field['placeholder']) . '"' : '')
               . ' />';
        }

        echo '</div></div>';
    }

    echo '<div class="col-md-2 col-sm-4 col-xs-6"><div class="form-group">'
       . '<label class="control-label">&nbsp;</label><br />'
       . '<button type="submit" class="btn btn-default"><i class="fa fa-filter"></i> '
       . html_escape(_l('filter_by')) . '</button>'
       . '</div></div>';

    echo '</div></form>';
}

/** Counter strip: [label => count] rendered as small clickable pills. */
function se_ui_counters(array $counters, $base_href = null, $param = 'status')
{
    echo '<div class="mbot15">';

    foreach ($counters as $key => $count) {
        $body = '<span class="label ' . se_ui_badge_class($key) . '" style="display:inline-block;padding:6px 10px;margin:0 6px 6px 0">'
              . html_escape($key) . ' <strong>' . (int) $count . '</strong></span>';

        if ($base_href) {
            echo '<a href="' . html_escape($base_href . (strpos($base_href, '?') === false ? '?' : '&') . $param . '=' . rawurlencode($key)) . '" style="text-decoration:none">' . $body . '</a>';
        } else {
            echo $body;
        }
    }

    echo '</div>';
}

/**
 * Key/value definition rows for a detail panel.
 * Values are pre-escaped HTML by the caller only when $raw is true.
 */
function se_ui_kv(array $rows, $raw = false)
{
    // Wrapped for overflow: a key/value table with long values (a webhook URL,
    // a request id) pushes past a phone viewport and takes the whole page with
    // it. .table-responsive scrolls the table instead of the document.
    echo '<div class="table-responsive"><table class="table table-striped"><tbody>';

    foreach ($rows as $k => $v) {
        echo '<tr><td style="width:34%;min-width:140px"><strong>' . html_escape($k) . '</strong></td><td>'
           . ($raw ? $v : html_escape((string) $v)) . '</td></tr>';
    }

    echo '</tbody></table></div>';
}

/** An "external setup required" checklist. Never contains a secret field. */
function se_ui_gate_checklist($title, array $items)
{
    echo '<div class="panel_s"><div class="panel-body">';
    echo '<h5>' . html_escape($title) . '</h5>';
    echo '<ul class="list-unstyled">';

    foreach ($items as $item) {
        $done = !empty($item['done']);
        echo '<li class="mbot10"><i class="fa ' . ($done ? 'fa-check-square text-success' : 'fa-square text-muted')
           . '"></i> ' . html_escape($item['label']);

        if (!empty($item['hint'])) {
            echo '<br /><small class="text-muted" style="margin-left:20px">' . html_escape($item['hint']) . '</small>';
        }

        echo '</li>';
    }

    echo '</ul></div></div>';
}

/* ==========================================================================
 * Azin CRM design-system helpers (DS v1 §2, CRM-M016).
 *
 * Views call these; they own the markup and the classes in se-ds.css. Every
 * value from the database is escaped here. Nothing below writes a colour or
 * a size — those are tokens in se-ds.css.
 * ========================================================================== */

/** The 7 macro-stages (UX-COPY §3.1). Order matters: it is the stage bar. */
function se_ui_stages_list()
{
    return ['enquiry', 'evaluation', 'review', 'quote', 'consultation', 'procedure', 'aftercare'];
}

/**
 * Journey state → [stage key, semantic colour] (UX-COPY §3.2).
 * Semantic: positive | warning | danger | info | action | inactive.
 * `action` is reserved for "a staff member must do something now".
 */
function se_ui_state_map()
{
    return [
        'new_whatsapp_enquiry'         => ['enquiry',      'info'],
        'welcome_sent'                 => ['enquiry',      'info'],
        'privacy_notice_sent'          => ['evaluation',   'info'],
        'consent_pending'              => ['evaluation',   'info'],
        'intake_link_sent'             => ['evaluation',   'info'],
        'consent_declined'             => ['evaluation',   'inactive'],
        'intake_started'               => ['evaluation',   'info'],
        'intake_submitted'             => ['evaluation',   'positive'],
        'intake_incomplete'            => ['evaluation',   'warning'],
        'photos_requested'             => ['evaluation',   'info'],
        'photos_incomplete'            => ['evaluation',   'warning'],
        'photo_retake_requested'       => ['evaluation',   'info'],
        'ready_for_review'             => ['review',       'action'],
        'under_review'                 => ['review',       'action'],
        'more_information_required'    => ['review',       'warning'],
        'not_suitable'                 => ['terminal',     'inactive'],
        'consultation_recommended'     => ['consultation', 'action'],
        'quote_pending_staff_approval' => ['quote',        'action'],
        'quote_sent'                   => ['quote',        'info'],
        'quote_accepted'               => ['quote',        'positive'],
        'quote_revision_requested'     => ['quote',        'action'],
        'quote_expired'                => ['quote',        'warning'],
        'consultation_booked'          => ['consultation', 'info'],
        'consultation_completed'       => ['consultation', 'positive'],
        'procedure_booked'             => ['procedure',    'info'],
        'preop_pending'                => ['procedure',    'info'],
        'procedure_completed'          => ['procedure',    'positive'],
        'aftercare_active'             => ['aftercare',    'positive'],
        'followup_due'                 => ['aftercare',    'warning'],
        'completed'                    => ['terminal',     'positive'],
        'opted_out'                    => ['terminal',     'inactive'],
        'closed_lost'                  => ['terminal',     'inactive'],
    ];
}

/** Macro-stage of a journey state ('terminal' for the end states). */
function se_ui_stage_of($state)
{
    $m = se_ui_state_map();

    return $m[(string) $state][0] ?? 'enquiry';
}

/** Semantic colour of a journey state. */
function se_ui_state_tone($state)
{
    $m = se_ui_state_map();

    return $m[(string) $state][1] ?? 'info';
}

/** Turkish/localised label of a journey state (lang key se_journey_state_<state>). */
function se_ui_state_label($state)
{
    $key = 'se_journey_state_' . (string) $state;
    $txt = _l($key);

    return $txt === $key ? (string) $state : $txt;
}

/** Stage label (lang key se_ui_stage_<stage>). */
function se_ui_stage_label($stage)
{
    $key = 'se_ui_stage_' . (string) $stage;
    $txt = _l($key);

    return $txt === $key ? (string) $stage : $txt;
}

/** DS badge. $tone: positive|warning|danger|info|action|inactive; $plain = no status dot. */
function se_ui_ds_badge($tone, $text, $plain = false, $attrs = '')
{
    $tone = in_array($tone, ['positive', 'warning', 'danger', 'info', 'action', 'inactive'], true) ? $tone : 'info';

    return '<span class="se-badge se-badge-' . $tone . ($plain ? ' se-badge-plain' : '') . '"' . ($attrs !== '' ? ' ' . $attrs : '') . '>'
         . html_escape($text) . '</span>';
}

/** Journey-state badge: label + tone from the map, in one call. */
function se_ui_state_badge($state, $suffix = '')
{
    $label = se_ui_state_label($state) . ($suffix !== '' ? ' · ' . $suffix : '');

    return se_ui_ds_badge(se_ui_state_tone($state), $label);
}

/** Automation badge (Açık / Duraklatıldı …). */
function se_ui_automation_badge($automation_state)
{
    $map = ['active' => 'positive', 'paused_staff' => 'inactive', 'paused_urgent' => 'danger', 'disabled' => 'inactive', 'error' => 'danger'];
    $key = 'se_ui_automation_' . (string) $automation_state;
    $txt = _l($key);

    return se_ui_ds_badge($map[(string) $automation_state] ?? 'info', $txt === $key ? (string) $automation_state : $txt, true);
}

/**
 * DS button. $variant: primary|secondary|ghost|danger. $opts: 'sm' => true,
 * 'attrs' => raw attribute string, 'icon' => leading glyph, 'aria' => label.
 */
function se_ui_btn($label, $href, $variant = 'secondary', array $opts = [])
{
    $variant = in_array($variant, ['primary', 'secondary', 'ghost', 'danger'], true) ? $variant : 'secondary';
    $cls = 'se-btn se-btn-' . $variant . (!empty($opts['sm']) ? ' se-btn-sm' : '') . (!empty($opts['class']) ? ' ' . $opts['class'] : '');
    $aria = !empty($opts['aria']) ? ' aria-label="' . html_escape($opts['aria']) . '"' : '';
    $icon = !empty($opts['icon']) ? '<span aria-hidden="true">' . $opts['icon'] . '</span> ' : '';
    $attrs = !empty($opts['attrs']) ? ' ' . $opts['attrs'] : '';

    return '<a href="' . html_escape($href) . '" class="' . $cls . '"' . $aria . $attrs . '>' . $icon . html_escape($label) . '</a>';
}

/** DS submit button that posts to $action with the CSRF token (Perfex form_open). */
function se_ui_post_btn($label, $action, $variant = 'secondary', array $fields = [], array $opts = [])
{
    $variant = in_array($variant, ['primary', 'secondary', 'ghost', 'danger'], true) ? $variant : 'secondary';
    $h = form_open($action, ['class' => 'se-inline-form' . (!empty($opts['class']) ? ' ' . $opts['class'] : '')]);
    foreach ($fields as $k => $v) {
        $h .= '<input type="hidden" name="' . html_escape($k) . '" value="' . html_escape($v) . '">';
    }
    $confirm = !empty($opts['confirm']) ? ' onclick="return confirm(' . json_encode((string) $opts['confirm']) . ')"' : '';
    $h .= '<button type="submit" class="se-btn se-btn-' . $variant . (!empty($opts['sm']) ? ' se-btn-sm' : '') . '"' . $confirm . '>' . html_escape($label) . '</button>';

    return $h . form_close();
}

/** DS alert row. $tone: info|warning|danger. $action: ['label'=>..,'href'=>..] */
function se_ui_alert($tone, $text, $action = null)
{
    $tone = in_array($tone, ['info', 'warning', 'danger'], true) ? $tone : 'info';
    $icon = ['info' => 'ℹ️', 'warning' => '⚠️', 'danger' => '⛔'][$tone];
    $h = '<div class="se-alert se-alert-' . $tone . '" role="' . ($tone === 'danger' ? 'alert' : 'status') . '">'
       . '<span aria-hidden="true">' . $icon . '</span> <span>' . html_escape($text) . '</span>';
    if ($action) {
        $h .= se_ui_btn($action['label'], $action['href'], 'secondary', ['sm' => true]);
    }

    return $h . '</div>';
}

/** DS empty state (what · why · how) */
function se_ui_empty_state($title, $text = '', $action = null)
{
    $h = '<div class="se-card se-empty"><h2>' . html_escape($title) . '</h2>';
    if ($text !== '') {
        $h .= '<p class="se-help">' . html_escape($text) . '</p>';
    }
    if ($action) {
        $h .= se_ui_btn($action['label'], $action['href'], 'primary');
    }

    return $h . '</div>';
}

/** Relative age in Turkish clinic shorthand: 18 dk · 3 sa · 2 g · 3 hf. */
function se_ui_age($ts, $now = null)
{
    $t = is_numeric($ts) ? (int) $ts : (int) strtotime((string) $ts);
    if ($t <= 0) {
        return '—';
    }
    $d = max(0, ($now ?? time()) - $t);
    if ($d < 60) { return _l('se_ui_age_now'); }
    if ($d < 3600) { return (int) floor($d / 60) . ' ' . _l('se_ui_age_min'); }
    if ($d < 86400) { return (int) floor($d / 3600) . ' ' . _l('se_ui_age_hour'); }
    if ($d < 7 * 86400) { return (int) floor($d / 86400) . ' ' . _l('se_ui_age_day'); }

    return (int) floor($d / (7 * 86400)) . ' ' . _l('se_ui_age_week');
}

/** Absolute short date: "4 Eyl 14:00" (year only when not the current one). */
function se_ui_when($ts)
{
    $t = is_numeric($ts) ? (int) $ts : (int) strtotime((string) $ts);
    if ($t <= 0) {
        return '—';
    }
    $months = ['Oca', 'Şub', 'Mar', 'Nis', 'May', 'Haz', 'Tem', 'Ağu', 'Eyl', 'Eki', 'Kas', 'Ara'];
    $s = (int) date('j', $t) . ' ' . $months[(int) date('n', $t) - 1] . ' ' . date('H:i', $t);
    if (date('Y', $t) !== date('Y')) {
        $s = (int) date('j', $t) . ' ' . $months[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
    }

    return $s;
}

/**
 * Phone formatting (UX-COPY §9): E.164 in, "+90 5xx xxx xx xx" out; masked
 * "+90 5•• ••• 27 41" for lists and the Sales role. Wrapped in <bdi dir=ltr>
 * so a number never reorders inside RTL text.
 */
function se_ui_phone($raw, $mask = false, $html = true)
{
    $d = preg_replace('/\D+/', '', (string) $raw);
    if ($d === '') {
        return $html ? '<span class="se-help">—</span>' : '';
    }
    if (strlen($d) === 10 && $d[0] === '5') { $d = '90' . $d; }
    if (strlen($d) === 11 && $d[0] === '0') { $d = '9' . $d; }
    if (substr($d, 0, 2) === '90' && strlen($d) === 12) {
        $n = substr($d, 2);   // 5xx xxx xx xx
        $out = $mask
            ? '+90 ' . $n[0] . '•• ••• ' . substr($n, 6, 2) . ' ' . substr($n, 8, 2)
            : '+90 ' . substr($n, 0, 3) . ' ' . substr($n, 3, 3) . ' ' . substr($n, 6, 2) . ' ' . substr($n, 8, 2);
    } else {
        $out = $mask ? '+' . substr($d, 0, 2) . ' •••• ' . substr($d, -4) : '+' . $d;
    }

    return $html ? '<bdi dir="ltr" class="se-phone">' . html_escape($out) . '</bdi>' : $out;
}

/** Initials for the avatar disc. */
function se_ui_initials($name)
{
    $parts = preg_split('/\s+/', trim((string) $name));
    $parts = array_values(array_filter($parts));
    if (!$parts) {
        return '?';
    }
    $ini = mb_strtoupper(mb_substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $ini .= mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1));
    }

    return $ini;
}

/** "Ayşe Y." — first name + initial, for lists and the queue. */
function se_ui_short_name($name)
{
    $parts = array_values(array_filter(preg_split('/\s+/', trim((string) $name))));
    if (!$parts) {
        return '';
    }
    if (count($parts) === 1) {
        return $parts[0];
    }

    return $parts[0] . ' ' . mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)) . '.';
}

/** 7-segment stage bar (DS §2.5). */
function se_ui_stages($state)
{
    $stage = se_ui_stage_of($state);
    $list = se_ui_stages_list();
    $idx = array_search($stage, $list, true);
    $h = '<div class="se-stages" role="list" aria-label="' . html_escape(_l('se_ui_stages_aria')) . '">';
    foreach ($list as $i => $k) {
        $cls = $idx === false ? ($stage === 'terminal' ? 'done' : '') : ($i < $idx ? 'done' : ($i === $idx ? 'now' : ''));
        $h .= '<span role="listitem" class="' . $cls . '" data-n="' . ($i + 1) . '"' . ($cls === 'now' ? ' aria-current="step"' : '') . '>' . html_escape(se_ui_stage_label($k)) . '</span>';
    }

    return $h . '</div>';
}

/**
 * Next-action panel (DS §2.6). $na is the array from se_journey_next_action().
 */
function se_ui_next_action(array $na, $compact = false)
{
    if (empty($na['sentence'])) {
        return '';
    }
    $h = '<div class="se-next' . ($compact ? ' se-next-compact' : '') . '">'
       . '<div><div class="k">' . html_escape(_l('se_ui_next_action')) . '</div>'
       . '<div class="v">' . html_escape($na['sentence']) . '</div>';
    if (!empty($na['reason'])) {
        $h .= '<div class="m">' . html_escape($na['reason']) . '</div>';
    }
    $h .= '</div>';
    if (!empty($na['action_label']) && !empty($na['url'])) {
        $h .= se_ui_btn($na['action_label'], $na['url'], !empty($na['ghost']) ? 'ghost' : 'primary');
    }

    return $h . '</div>';
}

/**
 * Attention row (DS §2.3). $item: who, why (badge text), tone, reason, age
 * (seconds), hot (bool), priority (1 danger / 2 action / 3 info), action_label,
 * url, aria (button label incl. the patient), method ('get'|'post'), fields.
 */
function se_ui_attention_row(array $item)
{
    $p = (int) ($item['priority'] ?? 3);
    $h = '<li class="has-prio"><span class="se-prio p' . $p . '" aria-hidden="true"></span><div>'
       . '<span class="se-who">' . html_escape($item['who'] ?? '') . '</span>'
       . '<div class="se-why">' . se_ui_ds_badge($item['tone'] ?? 'info', $item['why'] ?? '');
    if (!empty($item['reason'])) {
        $h .= ' <span>' . html_escape($item['reason']) . '</span>';
    }
    if (isset($item['age'])) {
        $h .= ' <span class="se-age' . (!empty($item['hot']) ? ' hot' : '') . '">' . html_escape(is_string($item['age']) ? $item['age'] : se_ui_age(time() - (int) $item['age'])) . '</span>';
    }
    $h .= '</div></div>';
    if (!empty($item['action_label']) && !empty($item['url'])) {
        $variant = $p <= 2 ? 'primary' : 'secondary';
        if (($item['method'] ?? 'get') === 'post') {
            $h .= se_ui_post_btn($item['action_label'], $item['url'], $variant, (array) ($item['fields'] ?? []));
        } else {
            $h .= se_ui_btn($item['action_label'], $item['url'], $variant, ['aria' => $item['aria'] ?? '']);
        }
    }

    return $h . '</li>';
}

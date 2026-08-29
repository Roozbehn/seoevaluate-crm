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
function se_ui_stat_card($label, $value, $href = null, $icon = 'fa-circle-o', $tone = '')
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
    echo '<table class="table table-striped"><tbody>';

    foreach ($rows as $k => $v) {
        echo '<tr><td style="width:34%"><strong>' . html_escape($k) . '</strong></td><td>'
           . ($raw ? $v : html_escape((string) $v)) . '</td></tr>';
    }

    echo '</tbody></table>';
}

/** An "external setup required" checklist. Never contains a secret field. */
function se_ui_gate_checklist($title, array $items)
{
    echo '<div class="panel_s"><div class="panel-body">';
    echo '<h5>' . html_escape($title) . '</h5>';
    echo '<ul class="list-unstyled">';

    foreach ($items as $item) {
        $done = !empty($item['done']);
        echo '<li class="mbot10"><i class="fa ' . ($done ? 'fa-check-square-o text-success' : 'fa-square-o text-muted')
           . '"></i> ' . html_escape($item['label']);

        if (!empty($item['hint'])) {
            echo '<br /><small class="text-muted" style="margin-left:20px">' . html_escape($item['hint']) . '</small>';
        }

        echo '</li>';
    }

    echo '</ul></div></div>';
}

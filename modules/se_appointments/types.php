<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Appointment types — the clinic vocabulary (UX-COPY §3.4).
 *
 * Storage values are stable identifiers (`consultation`, `procedure`, `check`,
 * `followup`); labels come from the language files; colours are the
 * design-system event classes (DS §2.15), never status colours.
 */
function se_appt_types()
{
    return [
        'consultation' => ['label' => _l('se_appt_type_consultation'), 'class' => 'ev-consult', 'minutes' => 30,  'color' => '#93c5fd'],
        'procedure'    => ['label' => _l('se_appt_type_procedure'),    'class' => 'ev-proc',    'minutes' => 240, 'color' => '#fdba74'],
        'check'        => ['label' => _l('se_appt_type_check'),        'class' => 'ev-check',   'minutes' => 20,  'color' => '#6ee7b7'],
        'followup'     => ['label' => _l('se_appt_type_followup'),     'class' => 'ev-follow',  'minutes' => 15,  'color' => '#c4b5fd'],
    ];
}

/** Normalise a stored/posted type to a known key (default consultation). */
function se_appt_type_key($type)
{
    $type = (string) $type;

    return isset(se_appt_types()[$type]) ? $type : 'consultation';
}

function se_appt_type_label($type)
{
    $t = se_appt_types();

    return $t[se_appt_type_key($type)]['label'];
}

function se_appt_type_class($type)
{
    $t = se_appt_types();

    return $t[se_appt_type_key($type)]['class'];
}

/** Default duration in minutes for a type. */
function se_appt_type_minutes($type)
{
    $t = se_appt_types();

    return (int) $t[se_appt_type_key($type)]['minutes'];
}

/**
 * Human conflict message (UX-COPY §5): who, when, what, and the next free slot.
 *
 * @param array  $clash   ['title'=>..,'start_at'=>..,'end_at'=>..,'appointment_type'=>..,'patient'=>..]
 * @param string $staff   staff display name
 * @param string $nextFree 'H:i' or ''
 */
function se_appt_conflict_message($clash, $staff, $nextFree = '')
{
    $from = date('H:i', strtotime((string) $clash['start_at']));
    $to   = date('H:i', strtotime((string) $clash['end_at']));
    $what = se_appt_type_label($clash['appointment_type'] ?? '');
    $who  = trim((string) ($clash['patient'] ?? ''));
    $msg  = se_tr('se_appt_conflict_human', '%s already has an appointment at this time (%s–%s %s).', $staff, $from, $to, $what . ($who !== '' ? ' · ' . $who : ''));
    if ($nextFree !== '') {
        $msg .= ' ' . se_tr('se_appt_conflict_next_free', 'First free slot: %s.', $nextFree);
    }

    return $msg;
}

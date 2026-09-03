<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Staff timers (CRM-M045 / AZCRM-WF-002 / UX-COPY §4) + quote expiry
 * (CRM-M048 / AZCRM-WF-005) + aftercare auto-start behind the approved
 * flag (CRM-M046 / AZCRM-WF-003).
 *
 * Runs from the journey cron. Uses the SAME thresholds as the next-action
 * engine (SE_NA_*), so what Bugün shows and what the timer nudges never
 * disagree. Every nudge is a dedup'd staff task (one per journey + state
 * period) plus at most one push; nothing here messages a patient, decides
 * suitability, or marks an appointment held. Option se_journey_timers=0
 * turns it off.
 */

/** Next-action keys that earn a staff nudge, with task kind and priority. */
function se_journey_timer_rules()
{
    return [
        'review'          => ['kind' => 'timer_review',       'p1_only' => true],    // escalate after SE_NA_REVIEW_ESCALATE
        'quote_followup'  => ['kind' => 'timer_quote_followup', 'p1_only' => false],
        'quote_expired'   => ['kind' => 'timer_quote_expired', 'p1_only' => false],
        'paused'          => ['kind' => 'timer_paused',       'p1_only' => false],
        'held_unrecorded' => ['kind' => 'timer_held',         'p1_only' => false],
        'start_aftercare' => ['kind' => 'timer_aftercare',    'p1_only' => false],
        'welcome_stale'   => ['kind' => 'timer_welcome',      'p1_only' => false],
    ];
}

function se_journey_timers_enabled()
{
    return (string) get_option('se_journey_timers') !== '0';
}

/**
 * @return array{scanned:int, tasks:int, pushes:int, expired:int, aftercare:int, skipped:string}
 */
function se_journey_run_timers($now = null, $limit = 300)
{
    $out = ['scanned' => 0, 'tasks' => 0, 'pushes' => 0, 'expired' => 0, 'aftercare' => 0, 'skipped' => ''];
    if (!se_journey_timers_enabled()) {
        $out['skipped'] = 'disabled';
        return $out;
    }
    $now = $now ?? time();
    $CI  = &get_instance();
    $p   = db_prefix();
    if (!$CI->db->table_exists($p . 'se_journeys')) {
        $out['skipped'] = 'no_table';
        return $out;
    }
    // Cron has no staff session: no brand scope here, every brand's journeys.
    $CI->db->where_not_in('state', se_journey_terminal_states())->order_by('last_updated', 'ASC')->limit(max(1, (int) $limit));
    $journeys = $CI->db->get($p . 'se_journeys')->result_array();
    if (!$journeys) {
        return $out;
    }
    $batch = se_journey_batch_context($journeys, $now);
    $rules = se_journey_timer_rules();

    foreach ($batch['items'] as $it) {
        $j  = $it['j'];
        $na = $it['na'];
        $out['scanned']++;

        // 1) Quote expiry: past valid_until, still waiting → quote_expired (+ nudge below on the next pass or now).
        if ((string) $j->state === 'quote_sent' && !empty($it['ctx']['quote']) && !empty($it['ctx']['quote']->valid_until)
            && (string) ($it['ctx']['quote']->patient_response ?? '') === ''
            && strtotime((string) $it['ctx']['quote']->valid_until . ' 23:59:59') < $now) {
            $r = se_journey_transition($j, 'quote_expired', 'quote_expired', 'system', null, null, 'valid_until ' . $it['ctx']['quote']->valid_until);
            if (!empty($r['ok'])) {
                $out['expired']++;
                $j = se_journey_get_raw((int) $j->id) ?: $j;
                $na = se_journey_next_action($j, $it['ctx'], $now);
            }
        }

        // 2) Aftercare auto-start: only with an APPROVED protocol (owner flag); otherwise the nudge below.
        if ((string) $j->state === 'procedure_completed' && function_exists('se_journey_aftercare_protocols') && function_exists('se_journey_aftercare_start')) {
            $approved = null;
            foreach (se_journey_aftercare_protocols((int) $j->brand_id) as $key => $proto) {
                if (!empty($proto['approved'])) { $approved = (string) $key; break; }
            }
            if ($approved !== null) {
                $CI->db->where('journey_id', (int) $j->id)->where('state', 'active');
                if ($CI->db->count_all_results($p . 'se_journey_aftercare_plans') === 0) {
                    $r = se_journey_aftercare_start($j, $approved, 0, $j->procedure_at ?? null);
                    if (!empty($r['ok'])) {
                        $out['aftercare']++;
                        se_journey_event($j, 'auto_started', 'aftercare ' . $approved, [], 'system', null, 'plan', (string) $r['plan_id']);
                        continue;   // state moved on; nudge next pass if still needed
                    }
                }
            }
        }

        // 3) Staff nudges at the documented thresholds.
        $key = (string) ($na['key'] ?? '');
        if (($na['owner'] ?? '') !== 'staff' || !isset($rules[$key])) {
            continue;
        }
        $rule = $rules[$key];
        if ($rule['p1_only'] && (int) $na['priority'] !== 1) {
            continue;
        }
        // One task per journey + state period: the suffix is the state change time.
        $period = (string) (strtotime((string) ($j->state_changed_at ?: $j->last_updated)) ?: 0);
        $title  = trim((string) $na['sentence'] . ($na['reason'] !== '' ? ' — ' . $na['reason'] : ''));
        $taskId = se_journey_task($j, $rule['kind'], $title, (int) $na['priority'] === 1 ? 'urgent' : 'normal', null, $period);
        if ($taskId > 0) {
            $out['tasks']++;
            se_journey_event($j, 'timer', $key, ['task' => $taskId], 'system', null, 'task', (string) $taskId);
            if (function_exists('se_push_safe_notify') && function_exists('se_push_conversation_recipients')) {
                $n = se_push_safe_notify(se_push_conversation_recipients((int) $j->brand_id, (int) $j->assigned_staff), [
                    't' => 'journey', 'title' => (string) $na['sentence'], 'body' => (string) ($na['reason'] ?: $it['name']),
                    'tag' => 'journey-' . (int) $j->id, 'url' => (string) ($na['url'] ?: admin_url('se_journey/se_journey/view/' . (int) $j->id)),
                ]);
                $out['pushes'] += (int) $n;
            }
        }
    }

    return $out;
}

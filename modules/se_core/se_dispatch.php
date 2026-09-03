<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Messaging dispatcher core — see controllers/Dispatch.php for the route.
 *
 * Runs the four messaging legs (WA events, WA queue, IG events, IG queue) under
 * one DB lock and records when it last ran, so the conversation tracker can
 * say "next run in under a minute" instead of pointing at the 15-minute cron.
 */

define('SE_DISPATCH_INTERVAL_SECONDS', 60);
define('SE_DISPATCH_LOCK', 'se_dispatch');

/** The steps, in order. Each is bounded and idempotent; a missing module is skipped. */
function se_dispatch_steps()
{
    return [
        'wa_events' => 'se_wa_process_pending',
        'wa_queue'  => 'se_wa_out_drain',
        'ig_events' => 'se_ig_process_pending',
        'ig_queue'  => 'se_ig_out_drain',
        'leadgen'   => 'se_leadgen_process_pending',   // Meta Lead Ads notifications (leased, idempotent) — within a minute, not the cron cadence (PJ-004)
        'media'     => 'se_media_fetch_pending',   // attachments referenced by the events above
        'journey_media' => 'se_journey_retry_parked_media',   // seal fetched patient photos (se_journey)
    ];
}

/**
 * @return array{ok:bool,locked:bool,ran:array<string,mixed>,errors:array<string,string>,at:string}
 */
function se_dispatch_run()
{
    $CI = &get_instance();

    // Non-blocking lock: a run that finds another in progress simply reports
    // it — the next minute's run picks the work up.
    $lock = $CI->db->query("SELECT GET_LOCK('" . SE_DISPATCH_LOCK . "', 0) AS l")->row();
    if (!$lock || (int) $lock->l !== 1) {
        return ['ok' => true, 'locked' => true, 'ran' => [], 'errors' => [], 'at' => date('Y-m-d H:i:s')];
    }

    $ran = []; $errors = [];
    foreach (se_dispatch_steps() as $name => $fn) {
        if (!function_exists($fn)) { $ran[$name] = 'skipped'; continue; }
        try {
            $r = call_user_func($fn);
            $ran[$name] = is_scalar($r) ? $r : 'ok';
        } catch (Throwable $e) {
            // Never a secret, never a stack trace: the class name is enough to triage.
            $errors[$name] = get_class($e);
        }
    }

    update_option('se_dispatch_last_run', time());
    update_option('se_dispatch_last_summary', json_encode(['ran' => $ran, 'errors' => $errors]));

    $CI->db->query("SELECT RELEASE_LOCK('" . SE_DISPATCH_LOCK . "') AS l");

    return ['ok' => empty($errors), 'locked' => false, 'ran' => $ran, 'errors' => $errors, 'at' => date('Y-m-d H:i:s')];
}

/** Seconds since the dispatcher last ran; null if never. */
function se_dispatch_age()
{
    $last = (int) get_option('se_dispatch_last_run');
    return $last ? (time() - $last) : null;
}

/** Is the fast dispatcher alive (ran within three intervals)? */
function se_dispatch_active($now = null)
{
    $last = (int) get_option('se_dispatch_last_run');
    $now  = $now ?? time();
    return $last > 0 && ($now - $last) <= 3 * SE_DISPATCH_INTERVAL_SECONDS;
}

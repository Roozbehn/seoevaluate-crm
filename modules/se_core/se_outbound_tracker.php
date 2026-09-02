<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Outbound message tracker — shared by the WhatsApp and Instagram threads.
 *
 * A reply queued from the panel used to vanish into a counter strip
 * ("pending 1") with no way to see WHICH message, WHY it is still pending, or
 * WHEN it will go. This renders one row per queued message for the open
 * conversation, explains its state in plain words, and states the dispatcher
 * cadence so "pending" reads as "waiting for the 16:33 run", not "stuck".
 *
 * Nothing here sends or mutates; it reads the queue tables and the cron clock.
 *
 * CLOCKS. Queue rows are written on the DATABASE clock (date_created,
 * next_attempt_at via se_db_now()) while sent_at is written with PHP date();
 * on this host they differ by a real offset. Every timestamp shown here is
 * converted to the PHP (site) clock so a row never appears to have been sent
 * before it was queued.
 */

/** Seconds to ADD to a DB-clock timestamp to express it on the PHP clock. */
function se_db_clock_offset()
{
    static $offset = null;

    if ($offset === null) {
        $offset = time() - strtotime(se_db_now());
        // Sub-minute jitter is request latency, not a timezone difference.
        if (abs($offset) < 60) { $offset = 0; }
    }

    return $offset;
}

/** DB-clock datetime → PHP-clock datetime string ('' passthrough for empty). */
function se_db_to_local($db_datetime)
{
    if ($db_datetime === null || $db_datetime === '') {
        return '';
    }
    $ts = strtotime((string) $db_datetime);

    return $ts === false ? (string) $db_datetime : date('Y-m-d H:i:s', $ts + se_db_clock_offset());
}

/**
 * When will the dispatcher (Perfex cron → after_cron_run drain) next run?
 *
 * @return array{last_run_at:?string,next_run_at:?string,seconds:?int,interval:int,overdue:bool}
 */
function se_outbound_dispatch_eta($now = null)
{
    $now      = $now ?? time();
    $interval = defined('SE_CRON_EXPECTED_INTERVAL_SECONDS') ? SE_CRON_EXPECTED_INTERVAL_SECONDS : 900;
    $last     = (int) get_option('last_cron_run');
    $source   = 'cron';

    // The dedicated per-minute dispatcher (se_core/dispatch) takes precedence
    // while it is alive; if it stops, the ETA falls back to the 15-minute cron
    // rather than promising a minute that will not come.
    if (function_exists('se_dispatch_active') && se_dispatch_active($now)) {
        $interval = SE_DISPATCH_INTERVAL_SECONDS;
        $last     = (int) get_option('se_dispatch_last_run');
        $source   = 'dispatcher';
    }

    if ($last <= 0) {
        return ['last_run_at' => null, 'next_run_at' => null, 'seconds' => null,
                'interval' => $interval, 'overdue' => false, 'source' => $source];
    }

    $next = $last + $interval;
    // If the expected run has already been missed, the next one is still "due
    // now" — never report a time in the past as the ETA.
    $overdue = $next < $now;
    $seconds = max(0, $next - $now);

    return ['last_run_at' => date('Y-m-d H:i:s', $last), 'next_run_at' => date('Y-m-d H:i:s', $next),
            'seconds' => $seconds, 'interval' => $interval, 'overdue' => $overdue, 'source' => $source];
}

/** "every minute" / "every 15 minutes" for an ETA's interval. */
function se_outbound_cadence_text(array $eta)
{
    $s = (int) ($eta['interval'] ?? 900);
    if ($s < 120) { return 'every minute'; }
    return 'every ' . (int) round($s / 60) . ' minutes';
}

/**
 * Queue rows for one conversation, newest first, normalised across channels.
 *
 * @param string $table  'se_wa_outbound' | 'se_ig_outbound'
 * @param string $id_col provider id column: 'wamid' | 'mid'
 */
function se_outbound_rows($table, $conversation_id, $id_col = 'wamid', $limit = 10)
{
    $CI = &get_instance();
    $CI->db->where('conversation_id', (int) $conversation_id)->order_by('id', 'DESC')->limit((int) $limit);
    $rows = $CI->db->get(db_prefix() . $table)->result_array();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'             => (int) $r['id'],
            'kind'           => (string) ($r['kind'] ?? 'text'),
            'template_name'  => (string) ($r['template_name'] ?? ''),
            'body'           => (string) ($r['body'] ?? ''),
            'status'         => (string) ($r['status'] ?? 'pending'),
            'attempts'       => (int) ($r['attempts'] ?? 0),
            'failure_class'  => (string) ($r['failure_class'] ?? ''),
            'last_error'     => (string) ($r['last_error'] ?? ''),
            'queued_at'      => se_db_to_local($r['date_created'] ?? ''),
            'next_attempt_at' => se_db_to_local($r['next_attempt_at'] ?? ''),
            'sent_at'        => (string) ($r['sent_at'] ?? ''),          // already PHP clock
            'provider_id'    => (string) ($r[$id_col] ?? ''),
        ];
    }

    return $out;
}

/**
 * One plain-language line per queue row. Pure: takes the row + ETA + "now".
 *
 * @return array{state:string,text:string}  state drives the badge colour.
 */
function se_outbound_explain(array $row, array $eta, $now = null)
{
    $now = $now ?? time();
    $mins = function ($s) { return $s < 60 ? 'under a minute' : ('≈' . (int) ceil($s / 60) . ' min'); };

    switch ($row['status']) {
        case 'sent':
            return ['state' => 'sent', 'text' => 'Sent ' . $row['sent_at']
                . ($row['provider_id'] !== '' ? ' · provider id ' . mb_substr($row['provider_id'], 0, 18) . '…' : '')];

        case 'processing':
            return ['state' => 'processing', 'text' => 'Sending now (claimed by the dispatcher)'];

        case 'failed':
            return ['state' => 'failed', 'text' => 'Failed after ' . $row['attempts'] . ' attempt'
                . ($row['attempts'] === 1 ? '' : 's') . ($row['last_error'] !== '' ? ': ' . $row['last_error'] : '')];

        case 'skipped':
            return ['state' => 'skipped', 'text' => 'Skipped' . ($row['last_error'] !== '' ? ': ' . $row['last_error'] : '')
                . ' — not sent and will not be retried'];
    }

    // pending
    if ($row['failure_class'] === 'gated') {
        return ['state' => 'gated', 'text' => 'Held — ' . ($row['last_error'] ?: 'sending gated')
            . '. Retried automatically once the gate clears (next check ' . $row['next_attempt_at'] . ')'];
    }

    $due = $row['next_attempt_at'] === '' || strtotime($row['next_attempt_at']) <= $now;

    if (!$due) {
        $wait = strtotime($row['next_attempt_at']) - $now;
        return ['state' => 'retryable', 'text' => 'Retry scheduled ' . $row['next_attempt_at'] . ' (' . $mins($wait) . ')'
            . ($row['last_error'] !== '' ? ' — last error: ' . $row['last_error'] : '')
            . ' · attempt ' . ($row['attempts'] + 1)];
    }

    if ($eta['next_run_at'] === null) {
        return ['state' => 'pending', 'text' => 'Queued — waiting for the dispatcher (cron has not run yet)'];
    }
    if ($eta['overdue']) {
        return ['state' => 'warning', 'text' => 'Queued — dispatcher run overdue (last run ' . $eta['last_run_at']
            . '; expected ' . se_outbound_cadence_text($eta) . '). Check System / Cron on Integration Health'];
    }

    if ($eta['interval'] < 120) {
        return ['state' => 'pending', 'text' => 'Queued — goes out within the next minute (dispatcher runs every minute)'];
    }

    return ['state' => 'pending', 'text' => 'Queued — goes out on the next dispatcher run at '
        . substr($eta['next_run_at'], 11, 5) . ' (' . $mins($eta['seconds']) . ')'];
}

/** Render the tracker panel body. $rows from se_outbound_rows(). */
function se_ui_outbound_tracker(array $rows, array $eta, $now = null)
{
    $now = $now ?? time();

    echo '<p class="text-muted" style="font-size:12px">'
       . 'Replies are queued here and sent by the dispatcher, which runs ' . html_escape(se_outbound_cadence_text($eta))
       . ($eta['next_run_at'] !== null && !$eta['overdue']
            ? ' — next run ' . html_escape(substr($eta['next_run_at'], 11, ($eta['interval'] < 120 ? 8 : 5)))
            : '')
       . '. Delivery receipts update the thread on the run after that.</p>';

    if (empty($rows)) {
        echo '<p class="text-muted no-margin">No messages queued from this thread yet.</p>';
        return;
    }

    echo '<div class="table-responsive"><table class="table table-condensed" style="font-size:12px"><thead><tr>'
       . '<th>Message</th><th>Status</th><th>Queued</th></tr></thead><tbody>';

    foreach ($rows as $r) {
        $ex    = se_outbound_explain($r, $eta, $now);
        $label = $r['kind'] === 'template'
            ? 'Template ' . $r['template_name']
            : mb_substr($r['body'], 0, 60) . (mb_strlen($r['body']) > 60 ? '…' : '');

        echo '<tr>'
           . '<td>' . html_escape($label) . '<br /><small class="text-muted">#' . (int) $r['id'] . '</small></td>'
           . '<td>' . se_ui_badge($ex['state'], $r['status']) . '<br /><small>' . html_escape($ex['text']) . '</small></td>'
           . '<td><small>' . html_escape($r['queued_at']) . '</small></td>'
           . '</tr>';
    }

    echo '</tbody></table></div>';
}

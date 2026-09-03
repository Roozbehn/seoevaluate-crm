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
    if ($s < 120) { return se_tr('se_tr_every_minute', 'every minute'); }
    return se_tr('se_tr_every_n_minutes', 'every %d minutes', (int) round($s / 60));
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
    $mins = function ($s) { return $s < 60 ? se_tr('se_tr_under_minute', 'under a minute') : se_tr('se_tr_approx_min', '≈%d min', (int) ceil($s / 60)); };

    switch ($row['status']) {
        case 'sent':
            return ['state' => 'sent', 'text' => se_tr('se_tr_sent', 'Sent %s', $row['sent_at'])
                . ($row['provider_id'] !== '' ? se_tr('se_tr_provider_id', ' · provider id %s…', mb_substr($row['provider_id'], 0, 18)) : '')];

        case 'processing':
            return ['state' => 'processing', 'text' => se_tr('se_tr_processing', 'Sending now (claimed by the dispatcher)')];

        case 'failed':
            return ['state' => 'failed', 'text' => se_tr('se_tr_failed', 'Failed after %d attempt%s', $row['attempts'], $row['attempts'] === 1 ? '' : 's')
                . ($row['last_error'] !== '' ? ': ' . $row['last_error'] : '')];

        case 'skipped':
            return ['state' => 'skipped', 'text' => se_tr('se_tr_skipped', 'Skipped') . ($row['last_error'] !== '' ? ': ' . $row['last_error'] : '')
                . se_tr('se_tr_skipped_final', ' — not sent and will not be retried')];
    }

    // pending
    if ($row['failure_class'] === 'gated') {
        return ['state' => 'gated', 'text' => se_tr('se_tr_held', 'Held — %s. Retried automatically once the gate clears (next check %s)',
            $row['last_error'] ?: se_tr('se_tr_sending_gated', 'sending gated'), $row['next_attempt_at'])];
    }

    $due = $row['next_attempt_at'] === '' || strtotime($row['next_attempt_at']) <= $now;

    if (!$due) {
        $wait = strtotime($row['next_attempt_at']) - $now;
        return ['state' => 'retryable', 'text' => se_tr('se_tr_retry', 'Retry scheduled %s (%s)', $row['next_attempt_at'], $mins($wait))
            . ($row['last_error'] !== '' ? se_tr('se_tr_last_error', ' — last error: %s', $row['last_error']) : '')
            . se_tr('se_tr_attempt', ' · attempt %d', $row['attempts'] + 1)];
    }

    if ($eta['next_run_at'] === null) {
        return ['state' => 'pending', 'text' => se_tr('se_tr_queued_no_cron', 'Queued — waiting for the dispatcher (cron has not run yet)')];
    }
    if ($eta['overdue']) {
        return ['state' => 'warning', 'text' => se_tr('se_tr_queued_overdue', 'Queued — dispatcher run overdue (last run %s; expected %s). Check System / Cron on Integration Health',
            $eta['last_run_at'], se_outbound_cadence_text($eta))];
    }

    if ($eta['interval'] < 120) {
        return ['state' => 'pending', 'text' => se_tr('se_tr_queued_minute', 'Queued — goes out within the next minute (dispatcher runs every minute)')];
    }

    return ['state' => 'pending', 'text' => se_tr('se_tr_queued_next', 'Queued — goes out on the next dispatcher run at %s (%s)',
        substr($eta['next_run_at'], 11, 5), $mins($eta['seconds']))];
}

/** Render the tracker panel body. $rows from se_outbound_rows(). */
function se_ui_outbound_tracker(array $rows, array $eta, $now = null)
{
    $now = $now ?? time();
    $pendingCount = count(array_filter($rows, function ($r) { return in_array($r['status'], ['pending', 'processing'], true); }));

    // One line by default; the table only when something is queued or failed (UX-W08).
    echo '<p class="se-help">'
       . html_escape(se_tr('se_tr_intro', 'Replies are queued here and sent by the dispatcher, which runs %s', se_outbound_cadence_text($eta)))
       . ($eta['next_run_at'] !== null && !$eta['overdue']
            ? html_escape(se_tr('se_tr_next_run', ' — next run %s', substr($eta['next_run_at'], 11, ($eta['interval'] < 120 ? 8 : 5))))
            : '')
       . '. ' . html_escape(se_tr('se_tr_receipts', 'Delivery receipts update the thread on the run after that.')) . '</p>';

    if (empty($rows)) {
        echo '<p class="se-help no-margin">' . html_escape(se_tr('se_tr_empty', 'No messages queued from this thread yet.')) . '</p>';
        return;
    }
    $failed = count(array_filter($rows, function ($r) { return $r['status'] === 'failed'; }));
    $open = $pendingCount > 0 || $failed > 0;
    echo '<details class="se-tracker"' . ($open ? ' open' : '') . '><summary class="se-help">'
       . html_escape(se_tr('se_tr_summary', '%d message(s) · %d queued · %d failed', count($rows), $pendingCount, $failed)) . '</summary>';

    echo '<div class="se-tablewrap"><table class="se-table"><thead><tr>'
       . '<th>' . html_escape(se_tr('se_tr_h_message', 'Message')) . '</th><th>' . html_escape(se_tr('se_tr_h_status', 'Status')) . '</th><th>' . html_escape(se_tr('se_tr_h_queued', 'Queued')) . '</th></tr></thead><tbody>';

    foreach ($rows as $r) {
        $ex    = se_outbound_explain($r, $eta, $now);
        $label = $r['kind'] === 'template'
            ? se_tr('se_tr_template', 'Template %s', $r['template_name'])
            : mb_substr($r['body'], 0, 60) . (mb_strlen($r['body']) > 60 ? '…' : '');

        echo '<tr>'
           . '<td>' . html_escape($label) . '<br /><small class="se-help">#' . (int) $r['id'] . '</small></td>'
           . '<td>' . se_ui_badge($ex['state'], se_tr('se_tr_status_' . $r['status'], $r['status'])) . '<br /><small>' . html_escape($ex['text']) . '</small></td>'
           . '<td><small>' . html_escape($r['queued_at']) . '</small></td>'
           . '</tr>';
    }

    echo '</tbody></table></div></details>';
}

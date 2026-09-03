<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Per-request memo for the capability lookups below.
 *
 * A generation counter rather than function statics, so a test (or a job that
 * legitimately switches acting staff) can invalidate every cached answer at
 * once with se_authz_reset_cache(). Static locals cannot be cleared, which
 * would make the tenancy rules untestable.
 */
function &se_authz_cache()
{
    static $cache = [];

    return $cache;
}

/** Drop every memoized capability answer. */
function se_authz_reset_cache()
{
    $cache = &se_authz_cache();
    $cache = [];

    if (function_exists('se_clinic_reset_cache')) {
        se_clinic_reset_cache();
    }
}

function se_authz_memo($key, callable $resolver)
{
    $cache = &se_authz_cache();

    $staff = (int) get_staff_user_id();
    $slot  = $staff . ':' . $key;

    if (!array_key_exists($slot, $cache)) {
        $cache[$slot] = $resolver();
    }

    return $cache[$slot];
}

/**
 * Does the current staff member see EVERY brand?
 *
 * Only two things grant that: being a Perfex admin, or holding the explicit
 * `se_tenancy.all_brands` capability. It is deliberately NOT implied by
 * `se_brands.view` (brand configuration) or `se_reports.view` (reporting) —
 * that conflation is exactly the defect this replaces, because gating the
 * reports controller on `se_brands.view` promoted every reporting user to a
 * global tenant user.
 */
function se_staff_sees_all_brands()
{
    return se_authz_memo('all_brands', function () {
        // No staff session (public token page, dispatcher, cron): nobody sees
        // anything, and — the part that matters — no query. Perfex's is_admin()
        // without $GLOBALS['current_user'] runs a SELECT on the SHARED query
        // builder; called from inside a model's half-built get() that polluted
        // the statement and threw (a 500 after the appointment row was written).
        if (!se_staff_session_id()) {
            return false;
        }

        return is_admin() || staff_can(SE_CAP_ALL_BRANDS, SE_FEATURE_TENANCY);
    });
}

/** The logged-in staff id, or 0 when there is no staff session. Never queries. */
function se_staff_session_id()
{
    $id = function_exists('get_staff_user_id') ? get_staff_user_id() : 0;

    return is_numeric($id) ? (int) $id : 0;
}

/**
 * May the current staff member work the unassigned (brand 0) triage queue?
 *
 * Brand 0 is where a lead lands before its brand is known. It used to be
 * appended to EVERY staff member's brand set, which quietly made all
 * unassigned records — leads, patients, appointments, WhatsApp threads —
 * globally visible. It is now its own capability.
 */
function se_staff_can_triage()
{
    return se_authz_memo('triage', function () {
        if (!se_staff_session_id()) {
            return false;   // same reason as se_staff_sees_all_brands()
        }

        return is_admin() || staff_can(SE_CAP_TRIAGE, SE_FEATURE_TENANCY);
    });
}

/** May the current staff member open the reporting screens? */
function se_staff_can_report()
{
    return is_admin()
        || staff_can('view', SE_FEATURE_REPORTS)
        || staff_can(SE_CAP_ALL_BRANDS, SE_FEATURE_TENANCY);
}

/** May the current staff member read/write brand CONFIGURATION? */
function se_staff_can_configure_brands()
{
    return is_admin() || staff_can('view', SE_FEATURE_BRANDS);
}

/**
 * The REAL brands mapped to the current staff member — never including the
 * brand-0 triage bucket.
 *
 * Kept separate from se_staff_brand_ids() because "which brands do you work
 * on" and "which rows may you see" are different questions. New-lead brand
 * stamping needs the former: with brand 0 folded in, a staff member mapped to
 * exactly one real brand looked like a two-brand user and their leads were
 * never stamped.
 */
function se_staff_real_brand_ids()
{
    return se_authz_memo('real_brand_ids', function () {
    $CI = &get_instance();

    // Standalone query: a caller may invoke this mid-build (e.g. a brand-scoped
    // model that has already set select()/join() on the shared query builder).
    // Using the query builder here would inherit that partial state and corrupt
    // both queries, so run raw SQL that leaves the shared builder untouched.
    $rows = $CI->db->query('SELECT brand_id FROM ' . db_prefix() . 'se_staff_brands WHERE staff_id = ' . (int) get_staff_user_id())->result_array();

    $ids = [];

    foreach ($rows as $row) {
        $id = (int) $row['brand_id'];
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
    });
}

/**
 * Brand ids whose ROWS the current staff member may see.
 *
 * The mapped real brands, plus the brand-0 triage bucket only when the
 * triage capability is held.
 */
function se_staff_brand_ids()
{
    return se_authz_memo('brand_ids', function () {
        $ids = se_staff_real_brand_ids();

        if (se_staff_can_triage()) {
            $ids[] = 0;
        }

        return array_values(array_unique($ids));
    });
}

function se_can_access_brand($brand_id)
{
    if (se_staff_sees_all_brands()) {
        return true;
    }

    return in_array((int) $brand_id, se_staff_brand_ids(), true);
}

/**
 * The DATABASE's clock, as a SQL datetime string.
 *
 * WHY THIS EXISTS
 * ---------------
 * Queue rows are written with SQL `NOW()` (locked_at) but were compared
 * against PHP's `date()` (next_attempt_at, lease cutoffs). On this host PHP
 * runs in UTC and MariaDB runs on system time — a REAL two-hour offset,
 * measured. Every such comparison was therefore wrong by that offset:
 *
 *   - a row the drainer rescheduled one hour ahead only became claimable
 *     three hours later;
 *   - a dead worker's 15-minute lease took 2h15m to recover.
 *
 * Both failures are silent and only appear under real timing, which is why no
 * fake-database test could have caught them. Everything that compares against
 * a stored timestamp now uses the same clock that wrote it.
 *
 * Memoised per request: one round trip, and a consistent instant for the whole
 * request rather than a clock that drifts between statements.
 */
function se_db_now($offset_seconds = 0)
{
    static $base = null;
    static $at   = null;

    if ($base === null) {
        $CI  = &get_instance();
        $row = $CI->db->query('SELECT NOW() AS n')->row();

        // Fall back to PHP time only if the server cannot answer.
        $base = $row ? strtotime($row->n) : time();
        $at   = time();
    }

    // Advance the memoised instant by however long this request has been running.
    return date('Y-m-d H:i:s', $base + (time() - $at) + (int) $offset_seconds);
}

/**
 * Canonical brand-scope SQL for a column. THE fail-closed primitive.
 *
 * Returns '' for a caller who legitimately sees everything, and `1=0` for a
 * caller with no reachable brands.
 *
 * Why this exists: five separate call sites each built their own
 * `IN (implode(...))`. For a staff member mapped to no brand at all,
 * se_staff_brand_ids() returns an EMPTY array, and every one of them then
 * produced `IN ()` — a MariaDB syntax error, i.e. a 500 on the leads list, the
 * kanban, appointments and the WhatsApp inbox. One of them was worse: the
 * patient scope used `$ids ?: [0]`, which silently substituted the brand-0
 * triage bucket and WIDENED access for exactly the user who should see nothing.
 *
 * An empty scope must deny, never error and never widen.
 */
function se_scope_in_sql($column)
{
    if (se_staff_sees_all_brands()) {
        return '';
    }

    $ids = array_map('intval', se_staff_brand_ids());

    if (!$ids) {
        return '1=0';   // fail closed
    }

    return $column . ' IN (' . implode(',', $ids) . ')';
}

/**
 * Apply se_scope_in_sql() to the shared query builder.
 * Returns false when the caller may see nothing at all.
 */
function se_apply_scope_in($column)
{
    $sql = se_scope_in_sql($column);

    if ($sql === '') {
        return true;
    }

    $CI = &get_instance();
    $CI->db->where($sql, null, false);

    return $sql !== '1=0';
}

/**
 * INNER JOIN that restricts a table to the staff member's brands.
 *
 * Returns an empty string for staff who see everything, so no join is added.
 * For a staff member with NO reachable brands it returns a join that cannot
 * match, rather than the `INNER JOIN ()` syntax error the old version emitted.
 */
function se_scope_join_sql($table)
{
    if (se_staff_sees_all_brands()) {
        return '';
    }

    $ids   = array_map('intval', se_staff_brand_ids());
    $alias = 'se_scope_' . substr(md5($table), 0, 8);

    if (!$ids) {
        // Deliberately unsatisfiable: a derived table with no rows.
        return 'INNER JOIN (SELECT NULL AS brand_id FROM DUAL WHERE 1=0) ' . $alias
            . ' ON ' . $alias . '.brand_id = ' . $table . '.brand_id';
    }

    $values = [];

    foreach ($ids as $id) {
        $values[] = 'SELECT ' . (int) $id . ' AS brand_id';
    }

    return 'INNER JOIN (' . implode(' UNION ', $values) . ') ' . $alias
        . ' ON ' . $alias . '.brand_id = ' . $table . '.brand_id';
}

/** Has the current staff member any reachable brand at all? */
function se_staff_has_any_brand()
{
    return se_staff_sees_all_brands() || count(se_staff_brand_ids()) > 0;
}

/**
 * Brands for pickers and settings screens.
 *
 * $accessible_only defaults to TRUE: an ordinary picker must never offer a
 * brand the staff member cannot reach, because offering it invites a POST that
 * the mutation guard then has to reject. Pass false only from a genuine
 * configuration screen that the caller has already authorized.
 */
function se_all_brands($active_only = true, $accessible_only = true)
{
    /* Resolve the accessible-id set BEFORE touching the query builder. The
     * old order added where('active',1) first and then bailed out for a
     * staff member with no reachable brand — leaving that predicate DANGLING
     * on the shared builder, where it silently contaminated whatever query
     * ran next in the same request. */
    $ids = null;

    if ($accessible_only && !se_staff_sees_all_brands()) {
        $ids = array_values(array_filter(se_staff_brand_ids(), function ($id) {
            return (int) $id > 0;
        }));

        if (!$ids) {
            return [];
        }
    }

    $CI = &get_instance();

    if ($active_only) {
        $CI->db->where('active', 1);
    }

    if ($ids !== null) {
        $CI->db->where_in('id', $ids);
    }

    $CI->db->order_by('name', 'ASC');

    return $CI->db->get(db_prefix() . 'se_brands')->result_array();
}

/**
 * Sentinel "no brand at all". Matches no row anywhere (brand ids are >= 0),
 * so every brand-filtered aggregate for it is honestly EMPTY.
 */
define('SE_BRAND_NONE', -1);

/**
 * The brand a screen should default to: the first brand this staff member can
 * actually reach, never the first globally-existing brand.
 */
function se_default_brand_id()
{
    $brands = se_all_brands(true, true);

    if ($brands) {
        return (int) $brands[0]['id'];
    }

    /* No reachable real brand. Brand 0 is the TRIAGE bucket and is only a
     * legitimate default for staff who may actually work it. It used to be
     * returned unconditionally, which handed an UNMAPPED ordinary staff
     * member (no tblse_staff_brands rows, no se_tenancy capability) the
     * brand-0 triage aggregates on the reports screens. Such a staff member
     * now gets SE_BRAND_NONE: a sentinel no query matches, so every
     * downstream panel is empty rather than the triage bucket's data. */
    if (se_staff_can_triage() || se_staff_sees_all_brands()) {
        return 0;
    }

    return SE_BRAND_NONE;
}

/**
 * Resolve the brand an integration screen should show from the raw ?brand
 * query value.
 *
 *   - param ABSENT (null)  => default to this staff member's brand, so a
 *     single-clinic deployment lands on its real brand instead of an empty
 *     "All" aggregate. This is the 2F fix: the selector defaults to the brand,
 *     not to "All".
 *   - param PRESENT ('0')  => the user explicitly chose "All"; honour it.
 *   - param PRESENT ('22') => that brand.
 */
function se_requested_brand_or_default($raw)
{
    if ($raw === null || $raw === '') {
        return (int) se_default_brand_id();
    }
    return (int) $raw;
}

function se_brand_name($brand_id)
{
    if ((int) $brand_id === 0) {
        return _l('se_brand_unassigned');
    }

    $CI = &get_instance();
    $CI->db->select('name')->where('id', (int) $brand_id);
    $row = $CI->db->get(db_prefix() . 'se_brands')->row();

    return $row ? $row->name : _l('se_brand_unknown');
}

/**
 * Queues a conversion signal for a destination, WITH an immutable snapshot of
 * the attribution and consent state that applied at event time.
 *
 * Nothing is sent inline with a web request - cron drains the outbox. The dedup
 * key keeps a repeated stage change on the same day from producing duplicates.
 *
 * @param string $destination meta_capi|google_dm
 * @param string $event_name  pipeline stage name, treatment-agnostic
 */
function se_outbox_queue($brand_id, $lead_id, $destination, $event_name, array $payload = [], $event_time = null, $dedup_extra = '')
{
    $CI = &get_instance();

    $event_time = $event_time ?: se_db_now();

    /* $dedup_extra discriminates events that are genuinely different but land
     * on the same (brand, lead, destination, name, day).
     *
     * Found by a test: one patient who answers a WhatsApp ad AND an Instagram
     * ad on the same day produces two messaging conversions with identical
     * keys, so the second was silently dropped — the channel is what makes
     * them different, and it was nowhere in the key. Empty for every existing
     * caller, so their keys are byte-identical to before. */
    $parts = [
        (int) $brand_id,
        (int) $lead_id,
        $destination,
        $event_name,
        date('Y-m-d', strtotime($event_time)),
    ];
    if ((string) $dedup_extra !== '') {
        $parts[] = (string) $dedup_extra;
    }
    $dedup = implode(':', $parts);

    $CI->db->where('dedup_key', $dedup);

    if ($CI->db->count_all_results(db_prefix() . 'se_conversion_outbox') > 0) {
        return false;
    }

    // Snapshot FIRST: it reads the lead row and the consent ledger, and doing
    // that mid-INSERT would pollute the shared query builder.
    $snapshot = function_exists('se_outbox_build_snapshot')
        ? se_outbox_build_snapshot($brand_id, $lead_id, $event_name, $event_time)
        : null;

    /* No snapshot, no row.
     *
     * build_snapshot returns null when the lead is missing or belongs to a
     * different brand. Queueing anyway would create a conversion that can
     * never be verified and — because the senders fail closed on a
     * snapshot-less row — could never be delivered either. Refuse at the
     * producer, where the caller can still see why. */
    if ($snapshot === null) {
        log_activity('SE outbox refused: no snapshot for brand ' . (int) $brand_id
            . ' lead ' . (int) $lead_id . ' (missing or cross-brand)');

        return false;
    }

    $row = [
        'brand_id'    => (int) $brand_id,
        'lead_id'     => (int) $lead_id,
        'destination' => $destination,
        'event_name'  => $event_name,
        'event_time'  => $event_time,
        'payload'     => json_encode($payload),
        'status'      => 'pending',
        'attempts'    => 0,
        'dedup_key'   => $dedup,
        'date_created' => se_db_now(),
        'next_attempt_at' => $event_time,
    ];

    $row['attribution_snapshot'] = json_encode($snapshot['attribution']);
    $row['consent_snapshot']     = json_encode($snapshot['consent']);
    $row['payload_version']      = SE_OUTBOX_PAYLOAD_VERSION;

    $CI->db->insert(db_prefix() . 'se_conversion_outbox', $row);

    return $CI->db->insert_id();
}

/* ---------------------------------------------------------------------------
 * Cron listener isolation (CRM-M014 / AZCRM-OBS-004).
 * ------------------------------------------------------------------------- */

/**
 * Register an after_cron_run listener that cannot take the others down.
 *
 * Perfex runs hook listeners in sequence with no isolation: one fatal in the
 * Google poll skipped the WhatsApp drain and the journey cron for that tick.
 * Each SE listener now runs inside its own try/catch; a failure is recorded
 * (class + message, redacted) under the option se_cron_last_errors and the
 * next listener still runs.
 *
 * Idempotent per callable; safe to call at module load.
 */
function se_cron_listener($callable, $hook = 'after_cron_run', $priority = 10)
{
    hooks()->add_action($hook, function ($arg = null) use ($callable) {
        return se_cron_run_isolated($callable, $arg);
    }, $priority);
}

/** Run one cron step under isolation; returns its result or null on failure. */
function se_cron_run_isolated($callable, $arg = null)
{
    $name = is_string($callable) ? $callable : 'closure';
    try {
        return call_user_func($callable, $arg);
    } catch (\Throwable $e) {
        $msg = get_class($e) . ': ' . mb_substr((string) $e->getMessage(), 0, 200);
        $msg = preg_replace('/[A-Za-z0-9_\-]{24,}/', '[redacted]', $msg);   // token-shaped strings never reach the options table
        $errors = [];
        if (function_exists('get_option')) {
            $errors = json_decode((string) get_option('se_cron_last_errors'), true) ?: [];
        }
        $errors[$name] = ['at' => date('Y-m-d H:i:s'), 'error' => $msg];
        if (function_exists('update_option')) {
            update_option('se_cron_last_errors', json_encode(array_slice($errors, -20, null, true)));
        }
        if (function_exists('log_message')) {
            log_message('error', 'se_cron step ' . $name . ' failed: ' . $msg);
        }

        return null;
    }
}

/** Does a staff member belong to a brand (admins belong to all)? Shared by journey and inbox assignment. */
function se_staff_in_brand($staff_id, $brand_id)
{
    $staff_id = (int) $staff_id;
    if ($staff_id <= 0) {
        return false;
    }
    if (function_exists('is_admin') && is_admin($staff_id)) {
        return true;
    }
    $CI = &get_instance();
    $CI->db->where('staff_id', $staff_id)->where('brand_id', (int) $brand_id);

    return $CI->db->count_all_results(db_prefix() . 'se_staff_brands') > 0;
}

/**
 * A public base URL for patient links must be HTTPS on THIS installation's
 * host (or a subdomain of it); a brand-config user could otherwise point
 * every patient link at another site.
 */
function se_journey_public_base_url_allowed($url, $own_host = null)
{
    $url = trim((string) $url);
    if ($url === '') {
        return true;   // empty = use site_url
    }
    if (!preg_match('#^https://([a-z0-9.-]+)(?::\d+)?(/.*)?$#i', $url, $m)) {
        return false;
    }
    $host = strtolower($m[1]);
    $own  = $own_host !== null ? strtolower((string) $own_host)
        : strtolower((string) parse_url(function_exists('site_url') ? site_url() : '', PHP_URL_HOST));
    if ($own === '') {
        return true;
    }

    return $host === $own || substr($host, -strlen('.' . $own)) === '.' . $own;
}

/* ---------------------------------------------------------------------------
 * Outbound host allowlists (CRM-M012 / audit DiD-1, DiD-2).
 * ------------------------------------------------------------------------- */

/** Is a URL's host one of the allowed suffixes? Exact host or subdomain match. */
function se_host_allowed($url, array $suffixes)
{
    $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
    if ($host === '') {
        return false;
    }
    foreach ($suffixes as $sfx) {
        $sfx = strtolower(ltrim((string) $sfx, '.'));
        if ($sfx === '' ) { continue; }
        if ($host === $sfx || substr($host, -strlen('.' . $sfx)) === '.' . $sfx) {
            return true;
        }
    }

    return false;
}

/** Hosts the media fetcher may download from (Meta CDNs + Graph). */
function se_media_fetch_hosts()
{
    $extra = array_filter(array_map('trim', explode(',', (string) (function_exists('get_option') ? get_option('se_media_fetch_hosts_extra') : ''))));

    return array_merge(['lookaside.fbsbx.com', 'cdninstagram.com', 'fbcdn.net', 'graph.facebook.com', 'whatsapp.net', 'facebook.com'], $extra);
}

/** Hosts a browser push subscription endpoint may point at. */
function se_push_endpoint_hosts()
{
    return ['fcm.googleapis.com', 'android.googleapis.com', 'push.apple.com', 'notify.windows.com', 'push.services.mozilla.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'];
}


/**
 * Translate with an English fallback: _l() returns its key when no language
 * file defines it (and in the test harness). Used by pure explainers whose
 * English wording is part of their tests while the staff UI reads Turkish.
 */
function se_tr($key, $default, ...$args)
{
    $t = _l($key);
    $t = ($t === $key || $t === '') ? $default : $t;

    return $args ? vsprintf($t, $args) : $t;
}

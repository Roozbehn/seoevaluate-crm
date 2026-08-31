<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Read/act layer for the Meta Lead Ads and Google Data Manager screens.
 *
 * Everything returned here is safe to render: booleans, counts, timestamps,
 * sanitized codes. No token, no raw payload, no personal data.
 */

/** True when the screen is showing the aggregate "All brands" view (brand 0). */
function se_is_all_brands($brand)
{
    return (int) $brand === 0;
}

/**
 * Read-only banner for the aggregate "All brands" view. In this view no single
 * brand's configuration may be edited or shown as if it were global: every
 * credential/mapping/integration action is disabled and data is shown per brand.
 */
function se_all_brands_readonly_notice()
{
    return '<div class="alert alert-warning"><i class="fa fa-lock"></i> '
        . html_escape(function_exists('_l') ? _l('se_all_brands_readonly') : 'All brands (read-only)')
        . '</div>';
}

/**
 * Per-provider credential progress for the Integration Credentials screen.
 *
 * Replaces the old global "owner actions" checklist, which went green as soon
 * as the server-generated tokens existed even while required provider secrets
 * (meta_app, meta_page, meta_capi, google_sa) were missing. Each row reports a
 * provider's real state — 'complete' | 'partial' | 'missing' — with an exact,
 * credential-naming detail string, plus whether the integration is enabled.
 *
 * @return array<int, array{key:string,label:string,state:string,detail:string,enabled:?bool,enabled_label:?string}>
 */
function se_integration_provider_progress($brand_id, $store)
{
    $brand_id = (int) $brand_id;

    $capiTok   = function_exists('se_capi_token_available') ? se_capi_token_available($brand_id)
                 : (se_secret_configured('meta_capi', $brand_id) || se_secret_configured('meta_capi', 0));
    $capiInh   = function_exists('se_capi_token_inherited') && se_capi_token_inherited($brand_id);
    $metaVer   = se_secret_configured('meta_verify', 0);
    $metaApp   = se_secret_configured('meta_app', 0);
    $metaPage  = se_secret_configured('meta_page', $brand_id) || se_secret_configured('meta_page', 0);
    $waVer     = se_secret_configured('wa_verify', 0);
    $waAppInh  = function_exists('se_wa_app_secret') ? se_wa_app_secret() !== '' : $metaApp;
    $googleSa  = se_secret_configured('google_sa', $brand_id) || se_secret_configured('google_sa', 0);

    $storeOk = !empty($store['exists']) && !empty($store['mode_ok']) && !empty($store['outside_docroot']);

    // Compose an exact "installed X; missing Y" detail line from named parts.
    $compose = function ($installed, $missing) {
        $parts = [];
        if ($installed) { $parts[] = $installed . ' installed'; }
        if ($missing)   { $parts[] = $missing . ' missing'; }
        return implode('; ', $parts);
    };

    $rows = [];

    $rows[] = [
        'key' => 'store', 'label' => 'Secret store',
        'state' => $storeOk ? 'complete' : 'missing',
        'detail' => $storeOk ? 'directory present, mode 700, outside document root'
                             : 'store directory missing or wrong mode',
        'enabled' => null, 'enabled_label' => null,
    ];

    $rows[] = [
        'key' => 'meta_capi', 'label' => 'Meta CAPI',
        'state' => $capiTok ? 'complete' : 'missing',
        'detail' => $capiTok
            ? ($capiInh ? 'Conversions API token inherited from the Page/system-user token' : 'Conversions API token installed')
            : 'Conversions API token missing',
        'enabled' => $brand_id > 0 ? se_capi_enabled($brand_id) : null,
        'enabled_label' => 'CAPI transmission',
    ];

    // Meta Lead Ads: verify token + App Secret (signatures) + Page token (fetch).
    $laMissing = [];
    if (!$metaApp)  { $laMissing[] = 'App Secret'; }
    if (!$metaPage) { $laMissing[] = 'Page token'; }
    $laInstalled = $metaVer ? 'verify token' : '';
    $laState = (!$metaVer) ? 'missing' : ($laMissing ? 'partial' : 'complete');
    $rows[] = [
        'key' => 'meta_leadgen', 'label' => 'Meta Lead Ads',
        'state' => $laState,
        'detail' => $metaVer
            ? $compose($laInstalled, $laMissing ? implode(' and ', $laMissing) : '')
            : 'verify token missing',
        'enabled' => null, 'enabled_label' => null,
    ];

    // WhatsApp: verify token + shared (inherited) App Secret + Cloud API token
    // for outbound. Never shown as an independent wa_app requirement.
    $waTok = se_secret_configured('wa_token', 0);
    $waState = (!$waVer) ? 'missing' : (($waAppInh && $waTok) ? 'complete' : 'partial');
    $waDetail = $waVer
        ? ($waAppInh
            ? ('verify token installed; App Secret inherited from Meta App Secret; Cloud API token '
               . ($waTok ? 'installed' : 'missing'))
            : 'verify token installed; shared App Secret (Meta App Secret) missing')
        : 'verify token missing';
    $rows[] = [
        'key' => 'whatsapp', 'label' => 'WhatsApp',
        'state' => $waState, 'detail' => $waDetail,
        'enabled' => null, 'enabled_label' => null,
    ];

    $rows[] = [
        'key' => 'google', 'label' => 'Google',
        'state' => $googleSa ? 'complete' : 'missing',
        'detail' => $googleSa ? 'service-account credential installed'
                              : 'service-account credential missing',
        'enabled' => $brand_id > 0 && function_exists('se_google_dm_enabled') ? se_google_dm_enabled($brand_id) : null,
        'enabled_label' => 'Google upload',
    ];

    return $rows;
}

/**
 * Safe, provider-specific diagnostic actions for the Meta screen. Each returns
 * ['ok'=>bool, 'message'=>string]. None reveals a secret; each names its own
 * prerequisite when it cannot run.
 *
 *   recheck          : re-evaluate health (no side effects).
 *   credential       : re-test credential readability (booleans only).
 *   verify_readiness : probe the public webhook (wrong token) to confirm the
 *                      route is reachable and record route_ok. No secret sent.
 *   reconcile        : run reconciliation now — it records Skipped + reason if
 *                      the Page token/mapping are missing (an honest skip).
 */
function se_meta_ui_diag($action, $brand_id)
{
    $brand_id = (int) $brand_id;

    switch ($action) {
        case 'recheck':
            return ['ok' => true, 'message' => 'Health rechecked.'];

        case 'credential':
            $parts = [];
            foreach (['meta_app' => 0, 'meta_verify' => 0, 'meta_page' => $brand_id, 'meta_capi' => $brand_id] as $prov => $bid) {
                $parts[] = $prov . ': ' . (se_secret_configured($prov, $bid) ? 'readable' : 'missing');
            }
            return ['ok' => true, 'message' => 'Credential readability — ' . implode('; ', $parts)];

        case 'verify_readiness':
            $res = se_webhook_probe_route(site_url('se_core/leadgen'), 'leadgen');
            if ($res['reached'] && function_exists('se_webhook_record')) {
                se_webhook_record('meta', 'route_ok');
            }
            return $res['reached']
                ? ['ok' => true, 'message' => 'Verification readiness: route reachable (HTTP ' . $res['status'] . ', controller reached). Verify token ' . (se_secret_configured('meta_verify', 0) ? 'installed' : 'MISSING') . '. challenge_verified still requires Meta\'s real callback.']
                : ['ok' => false, 'message' => 'Verification readiness: the public route did not reach the controller (no X-SE-Webhook marker). Check Cloudflare/routing.'];

        case 'reconcile':
            if (function_exists('se_leadgen_reconcile')) {
                $n = (int) se_leadgen_reconcile();
                $result = get_option('se_meta_last_reconcile_result') ?: 'unknown';
                $reason = get_option('se_meta_last_reconcile_reason') ?: '';
                return ['ok' => $result === 'Reconciled',
                        'message' => 'Reconciliation ' . $result . ($reason ? ' — ' . $reason : '') . ' (upserted ' . $n . ').'];
            }
            return ['ok' => false, 'message' => 'Reconciliation is unavailable.'];
    }

    return ['ok' => false, 'message' => 'Unknown diagnostic action.'];
}

/**
 * Probe a public webhook route with a deliberately WRONG verify token. A 403
 * carrying the X-SE-Webhook marker proves the route is reachable and our
 * controller handled it — without ever sending a real secret.
 */
function se_webhook_probe_route($url, $marker)
{
    $ch = curl_init($url . '?hub_mode=subscribe&hub_verify_token=diagnostic-wrong-token&hub_challenge=SE_DIAG');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_NOBODY         => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $resp   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $reached = $resp !== false
        && stripos((string) $resp, 'X-SE-Webhook: ' . $marker) !== false
        && $status === 403;

    return ['reached' => $reached, 'status' => $status];
}

/* ============================ META LEAD ADS ============================= */

function se_meta_ui_status($brand_id = 0)
{
    // The CANONICAL public webhook URI — the one the CSRF exclusion covers and
    // the one to register with Meta. The /admin/... router alias stays
    // CSRF-protected and must never be handed out.
    $leadgenUrl = site_url('se_core/leadgen');

    $tokenConfigured  = se_secret_configured('meta_page', $brand_id);
    $appSecretPresent = se_secret_configured('meta_app', 0);
    // The META verify token (provider meta_verify) — the same source
    // Leadgen::verify() enforces, not the WhatsApp one.
    $verifyPresent    = se_secret_configured('meta_verify', 0);

    $activeForms = 0;
    if ($brand_id > 0) {
        $CI = &get_instance();
        $CI->db->where('brand_id', (int) $brand_id)->where('active', 1);
        $activeForms = $CI->db->count_all_results(db_prefix() . 'se_meta_forms');
    }

    return [
        'enabled'            => $brand_id > 0 ? se_capi_enabled($brand_id) : false,
        'page_token'         => $tokenConfigured,
        'app_secret'         => $appSecretPresent,
        'verify_token'       => $verifyPresent,
        'webhook_url'        => $leadgenUrl,
        'webhook_ready'      => $appSecretPresent && $verifyPresent,
        'page_form_mapped'   => $activeForms > 0,
        'app_owner'          => get_option('se_meta_app_owner_label') ?: null,
        // "Last successful fetch" now means an AUTHENTICATED Graph fetch really
        // succeeded — not the reconcile heartbeat, which used to be written on
        // every cron run and read as a false success even with no token.
        'last_fetch_ok_at'   => get_option('se_meta_last_fetch_ok_at') ?: null,
        'last_webhook_at'    => get_option('se_meta_last_webhook_at') ?: null,
        'last_reconcile_at'  => get_option('se_meta_last_reconcile_at') ?: null,
        'last_error'         => $brand_id > 0 ? (get_option('se_meta_token_last_error_' . $brand_id) ?: null) : null,
        // Reconciliation is implemented: it re-fetches recent leads per mapped
        // form and upserts them idempotently; live fetching is gated on a token.
        'reconcile_implemented' => true,
        'reconcile_gated'    => !$tokenConfigured || (int) get_option('se_meta_leadgen_review_gated') === 1,
        // Honest, blocker-naming status line (never a bare green "Yes").
        'reconcile_status_text' => se_meta_reconcile_status_text(
            $tokenConfigured, $activeForms > 0,
            (int) get_option('se_meta_leadgen_review_gated') === 1,
            get_option('se_meta_leadgen_review_item') ?: 'leads_retrieval'),
        // Last cron attempt outcome: 'Skipped' + exact reason means a blocked
        // attempt, which is NOT a successful reconciliation.
        'last_reconcile_result' => get_option('se_meta_last_reconcile_result') ?: null,
        'last_reconcile_reason' => get_option('se_meta_last_reconcile_reason') ?: null,
    ];
}

/**
 * Honest reconciliation status string. When any prerequisite is missing it
 * names the exact blockers instead of showing a misleading "Yes".
 */
function se_meta_reconcile_status_text($token_configured, $mapped, $review_gated = false, $review_item = 'leads_retrieval')
{
    if ($token_configured && $mapped && !$review_gated) {
        return 'Implemented, not live-tested — awaiting a live authenticated fetch';
    }

    $missing = [];
    if (!$token_configured) { $missing[] = 'Page token'; }
    if ($review_gated)      { $missing[] = $review_item . ' (App Review)'; }
    if (!$mapped)           { $missing[] = 'mapping'; }

    return 'Implemented, not live-tested — blocked by ' . implode(' and ', $missing);
}

/**
 * Visibility predicate for leadgen-event queries.
 *
 * Events carry NO brand column before routing, so brand membership is derived
 * from the (page_id, form_id) mapping in tblse_meta_forms. The old code only
 * filtered when brand > 0, so the default brand=0 screen listed EVERY brand's
 * event metadata to any se_brands.view holder.
 *
 * Rules:
 *   - explicit brand  -> that brand's mapped pairs (after se_can_access_brand);
 *   - brand 0, staff with cross-brand reach (admin / se_tenancy.all_brands)
 *     -> everything, INCLUDING still-unrouted events;
 *   - brand 0, limited staff -> only events whose pair maps to one of THEIR
 *     real brands; unrouted events are excluded (no brand can be asserted).
 *
 * @return string '' = unrestricted, '1=0' = nothing, else a pair predicate.
 */
function se_meta_event_scope_predicate($brand_id = 0)
{
    $brand_id = (int) $brand_id;

    if ($brand_id > 0) {
        if (!se_can_access_brand($brand_id)) {
            return '1=0';
        }

        return se_meta_form_pair_predicate([$brand_id]);
    }

    if (se_staff_sees_all_brands()) {
        return '';
    }

    $ids = se_staff_real_brand_ids();

    if (!$ids) {
        return '1=0';   // fail closed: no brands, no events
    }

    return se_meta_form_pair_predicate($ids);
}

/**
 * OR-of-(page_id AND form_id) predicate for these brands' form mappings.
 * Resolved as a STANDALONE query first, so callers can then build their own
 * query without builder pollution. '1=0' when the brands map no forms.
 */
function se_meta_form_pair_predicate(array $brand_ids)
{
    $brand_ids = array_values(array_filter(array_map('intval', $brand_ids), function ($id) {
        return $id > 0;
    }));

    if (!$brand_ids) {
        return '1=0';
    }

    $CI = &get_instance();

    $CI->db->select('page_id, form_id');
    $CI->db->where_in('brand_id', $brand_ids);
    $rows = $CI->db->get(db_prefix() . 'se_meta_forms')->result_array();

    if (!$rows) {
        return '1=0';
    }

    $parts = [];

    foreach ($rows as $r) {
        $parts[] = '(page_id = ' . $CI->db->escape((string) $r['page_id'])
                 . ' AND form_id = ' . $CI->db->escape((string) $r['form_id']) . ')';
    }

    return '(' . implode(' OR ', array_unique($parts)) . ')';
}

function se_meta_ui_counters($brand_id = 0)
{
    $CI = &get_instance();

    // Resolve the visibility predicate BEFORE building the aggregate query —
    // the resolver runs its own query and would pollute a half-built one.
    $scope = se_meta_event_scope_predicate($brand_id);

    $CI->db->select('state, COUNT(*) AS c')->group_by('state');

    if ($scope !== '') {
        $CI->db->where($scope, null, false);
    }

    $rows = $CI->db->get(db_prefix() . 'se_meta_leadgen_events')->result_array();

    $out = ['pending' => 0, 'processing' => 0, 'processed' => 0, 'held' => 0, 'failed' => 0];

    foreach ($rows as $r) {
        $out[$r['state']] = (int) $r['c'];
    }

    return $out;
}

function se_meta_ui_forms($brand_id = 0)
{
    $CI = &get_instance();

    se_apply_scope_in('brand_id');

    if ($brand_id > 0 && se_can_access_brand($brand_id)) {
        $CI->db->where('brand_id', (int) $brand_id);
    }

    $CI->db->order_by('id', 'ASC');

    return $CI->db->get(db_prefix() . 'se_meta_forms')->result_array();
}

function se_meta_ui_events($brand_id = 0, $state = '', $limit = 50)
{
    $CI = &get_instance();

    // Resolve first — see se_meta_event_scope_predicate().
    $scope = se_meta_event_scope_predicate($brand_id);

    if ($state !== '') {
        $CI->db->where('state', $state);
    }

    if ($scope !== '') {
        $CI->db->where($scope, null, false);
    }

    $CI->db->order_by('id', 'DESC')->limit((int) $limit);

    $rows = $CI->db->get(db_prefix() . 'se_meta_leadgen_events')->result_array();

    // Strip the raw payload: it carries the lead's contact details.
    foreach ($rows as &$r) {
        unset($r['payload']);
    }

    return $rows;
}

/** Configured CRM lead statuses, so the default is a real one, not 0. */
function se_meta_ui_lead_statuses()
{
    $CI = &get_instance();
    $CI->db->order_by('statusorder', 'ASC');

    return $CI->db->get(db_prefix() . 'leads_status')->result_array();
}

function se_meta_ui_lead_sources()
{
    $CI = &get_instance();
    $CI->db->order_by('id', 'ASC');

    return $CI->db->get(db_prefix() . 'leads_sources')->result_array();
}

function se_meta_ui_save_defaults($brand_id, $status_id, $source_id)
{
    update_option('se_meta_default_status_' . (int) $brand_id, (int) $status_id);
    update_option('se_meta_default_source_' . (int) $brand_id, (int) $source_id);

    log_activity('SE meta defaults saved [brand ' . (int) $brand_id . ', staff ' . (int) get_staff_user_id() . ']');

    return true;
}

/**
 * The brand a leadgen event is routed to via its ACTIVE, unique page+form
 * mapping, or null when the event is (still) unrouted/ambiguous.
 */
function se_meta_event_brand(array $event)
{
    $route = se_leadgen_route((string) ($event['page_id'] ?? ''), (string) ($event['form_id'] ?? ''));

    return $route ? (int) $route['brand_id'] : null;
}

/** Requeue a held/failed leadgen event so a configuration fix can take effect. */
function se_meta_ui_requeue($id)
{
    $CI = &get_instance();

    $CI->db->where('id', (int) $id);
    $row = $CI->db->get(db_prefix() . 'se_meta_leadgen_events')->row_array();

    if (!$row) {
        return ['ok' => false, 'message' => _l('se_meta_requeue_denied')];
    }

    /* BRAND GUARD (mirrors Se_outbox::requeue's scoped pattern). The event is
     * loaded by bare id, so without this a configure-capable staff member
     * mapped to ONE brand could reset another brand's event. The brand comes
     * from the routed page+form mapping; an event with no (unique, active)
     * mapping has no assertable brand and may only be touched by staff with
     * cross-brand reach (admin / se_tenancy.all_brands). */
    $brand = se_meta_event_brand($row);

    if ($brand === null ? !se_staff_sees_all_brands() : !se_can_access_brand($brand)) {
        return ['ok' => false, 'message' => _l('se_meta_requeue_denied')];
    }

    if (!in_array($row['state'], ['held', 'failed'], true)) {
        return ['ok' => false, 'message' => _l('se_meta_requeue_not_eligible')];
    }

    $CI->db->where('id', (int) $id)->update(db_prefix() . 'se_meta_leadgen_events', [
        'state'      => 'pending',
        'attempts'   => 0,
        'last_error' => null,
    ]);

    log_activity('SE leadgen requeue [event ' . (int) $id . ', staff ' . (int) get_staff_user_id() . ']');

    return ['ok' => true, 'message' => _l('se_meta_requeued')];
}

/* ========================= GOOGLE DATA MANAGER ========================== */

function se_google_ui_status($brand_id = 0)
{
    $brand = null;

    if ($brand_id > 0 && se_can_access_brand($brand_id)) {
        $CI = &get_instance();
        $CI->db->where('id', (int) $brand_id);
        $brand = $CI->db->get(db_prefix() . 'se_brands')->row();
    }

    $cred = se_gdm_credential_status($brand_id);

    return [
        'enabled'            => $brand_id > 0 ? se_google_dm_enabled($brand_id) : false,
        'customer_id'        => $brand ? ($brand->google_ads_customer_id ?: null) : null,
        'login_account'      => get_option('se_google_login_account_' . (int) $brand_id) ?: null,
        // Booleans and metadata only — never a token, never a key fragment.
        'credential_ready'   => $cred['file_present'],
        'credential_mode_ok' => $cred['file_mode_ok'],
        'credential_valid'   => $cred['credential_valid'],
        'client_email'       => $cred['client_email'],
        'project_id'         => $cred['project_id'],
        'signer_available'   => $cred['signer_available'],
        'token_cached'       => $cred['token_cached'],
        'token_expires_at'   => $cred['token_expires_at'],
        'token_valid_now'    => $cred['token_valid_now'],
        'ready'              => $cred['ready'],
        'last_auth_at'       => $cred['last_auth_at'],
        'last_error'         => $cred['last_error'],
        // The abstraction is built; the signer and poller are pluggable and
        // are not registered here, so both remain honestly reported.
        'token_renewal_implemented'  => $cred['signer_available'],
        'status_polling_implemented' => se_gdm_status_polling_implemented(),
        // Honest polling status — implemented, but not live-tested, and names
        // the blocker when the service-account credential is missing.
        'polling_status_text' => se_gdm_status_polling_implemented()
            ? ($cred['ready']
                ? 'Implemented, not live-tested — awaiting a live status poll'
                : 'Implemented, not live-tested — blocked by service-account credential')
            : 'Not implemented',
        'last_poll_result'   => get_option('se_gdm_last_poll_result_' . (int) $brand_id) ?: (get_option('se_gdm_last_poll_result') ?: null),
        'last_poll_reason'   => get_option('se_gdm_last_poll_reason_' . (int) $brand_id) ?: (get_option('se_gdm_last_poll_reason') ?: null),
        'min_age_seconds'    => se_gdm_min_age_seconds($brand_id),
        'max_age_days'       => se_gdm_max_age_days($brand_id),
    ];
}

function se_google_ui_counters($brand_id = 0)
{
    $CI = &get_instance();

    $CI->db->select('status, COUNT(*) AS c')->where('destination', 'google_dm')->group_by('status');
    se_apply_scope_in('brand_id');

    if ($brand_id > 0 && se_can_access_brand($brand_id)) {
        $CI->db->where('brand_id', (int) $brand_id);
    }

    $rows = $CI->db->get(db_prefix() . 'se_conversion_outbox')->result_array();

    $out = ['pending' => 0, 'submitted' => 0, 'confirmed' => 0, 'failed' => 0, 'skipped' => 0];

    foreach ($rows as $r) {
        $out[$r['status']] = (int) $r['c'];
    }

    return $out;
}

function se_google_ui_requests($brand_id = 0, $limit = 25)
{
    $CI = &get_instance();

    se_apply_scope_in('brand_id');

    if ($brand_id > 0 && se_can_access_brand($brand_id)) {
        $CI->db->where('brand_id', (int) $brand_id);
    }

    $CI->db->order_by('id', 'DESC')->limit((int) $limit);

    return $CI->db->get(db_prefix() . 'se_gdm_requests')->result_array();
}

/**
 * Stage → conversion-action mapping rows for a brand.
 *
 * Each row carries whether the stage is uploadable at all (policy allowlist) so
 * the view can render clinical-adjacent stages as a locked "Do not upload"
 * rather than an editable field.
 */
function se_google_ui_mappings($brand_id)
{
    $out = [];

    if ($brand_id <= 0) {
        return $out;
    }

    $uploadable = function_exists('se_gdm_uploadable_stages') ? se_gdm_uploadable_stages() : [];

    foreach (se_pipeline_stages() as $stage) {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($stage));
        $out[$stage] = [
            'action_id'  => (string) get_option('se_google_conv_action_' . (int) $brand_id . '_' . $slug),
            'uploadable' => in_array($stage, $uploadable, true),
        ];
    }

    return $out;
}

function se_google_ui_save_mapping($brand_id, $stage, $action_id)
{
    if (!in_array($stage, se_pipeline_stages(), true)) {
        return false;
    }

    // POLICY GUARD: a clinical-adjacent stage may never be mapped to a Google
    // conversion, whatever the UI sends. Only allowlisted generic stages persist.
    if (function_exists('se_gdm_stage_uploadable') && !se_gdm_stage_uploadable($stage)) {
        log_activity('SE google mapping refused for non-uploadable stage [' . $stage
            . ', brand ' . (int) $brand_id . ', staff ' . (int) get_staff_user_id() . ']');

        return false;
    }

    // Conversion-action ids are numeric/short identifiers, not free text.
    $action_id = preg_replace('/[^A-Za-z0-9_\-\/]/', '', (string) $action_id);
    $slug      = preg_replace('/[^a-z0-9]+/', '_', strtolower($stage));

    update_option('se_google_conv_action_' . (int) $brand_id . '_' . $slug, mb_substr($action_id, 0, 64));

    log_activity('SE google conversion-action mapping saved [brand ' . (int) $brand_id
        . ', stage ' . $stage . ', staff ' . (int) get_staff_user_id() . ']');

    return true;
}

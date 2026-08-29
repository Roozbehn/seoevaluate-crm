<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Read/act layer for the Meta Lead Ads and Google Data Manager screens.
 *
 * Everything returned here is safe to render: booleans, counts, timestamps,
 * sanitized codes. No token, no raw payload, no personal data.
 */

/* ============================ META LEAD ADS ============================= */

function se_meta_ui_status($brand_id = 0)
{
    $webhookUrl = site_url('se_whatsapp/webhook');   // shared receiver base
    $leadgenUrl = admin_url('se_core/leadgen');

    $tokenConfigured  = se_secret_configured('meta_page', $brand_id);
    $appSecretPresent = se_secret_configured('meta_app', 0);
    $verifyPresent    = se_secret_configured('wa_verify', 0);

    return [
        'enabled'            => $brand_id > 0 ? se_capi_enabled($brand_id) : false,
        'page_token'         => $tokenConfigured,
        'app_secret'         => $appSecretPresent,
        'verify_token'       => $verifyPresent,
        'webhook_url'        => $leadgenUrl,
        'webhook_ready'      => $appSecretPresent && $verifyPresent,
        'app_owner'          => get_option('se_meta_app_owner_label') ?: null,
        'last_webhook_at'    => get_option('se_meta_last_webhook_at') ?: null,
        'last_reconcile_at'  => get_option('se_meta_last_reconcile_at') ?: null,
        'last_error'         => $brand_id > 0 ? (get_option('se_meta_token_last_error_' . $brand_id) ?: null) : null,
        // Reconciliation is a heartbeat only; saying otherwise would be a lie.
        'reconcile_implemented' => false,
    ];
}

function se_meta_ui_counters($brand_id = 0)
{
    $CI = &get_instance();

    $CI->db->select('state, COUNT(*) AS c')->group_by('state');

    if ($brand_id > 0 && se_can_access_brand($brand_id)) {
        // leadgen events carry no brand column until they are routed, so the
        // brand filter is applied through the form mapping.
        $CI->db->where('form_id IN (SELECT form_id FROM ' . db_prefix()
            . 'se_meta_forms WHERE brand_id = ' . (int) $brand_id . ')', null, false);
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

    if ($state !== '') {
        $CI->db->where('state', $state);
    }

    if ($brand_id > 0 && se_can_access_brand($brand_id)) {
        $CI->db->where('form_id IN (SELECT form_id FROM ' . db_prefix()
            . 'se_meta_forms WHERE brand_id = ' . (int) $brand_id . ')', null, false);
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

/** Requeue a held/failed leadgen event so a configuration fix can take effect. */
function se_meta_ui_requeue($id)
{
    $CI = &get_instance();

    $CI->db->where('id', (int) $id);
    $row = $CI->db->get(db_prefix() . 'se_meta_leadgen_events')->row_array();

    if (!$row) {
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

    $credential = se_secret_status('google_sa', $brand_id);

    return [
        'enabled'          => $brand_id > 0 ? se_google_dm_enabled($brand_id) : false,
        'customer_id'      => $brand ? ($brand->google_ads_customer_id ?: null) : null,
        'login_account'    => get_option('se_google_login_account_' . (int) $brand_id) ?: null,
        // Boolean readiness only — never a token, never an expiry value we do
        // not actually have.
        'credential_ready' => $credential['configured'],
        'credential_mode_ok' => $credential['mode_ok'],
        'last_auth_at'     => $credential['last_auth_at'],
        'last_error'       => $credential['last_error'],
        // Honest: the renewable-credential flow and status polling are not built.
        'token_renewal_implemented' => false,
        'status_polling_implemented' => false,
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

/** Stage → conversion-action id mappings for a brand. */
function se_google_ui_mappings($brand_id)
{
    $out = [];

    if ($brand_id <= 0) {
        return $out;
    }

    foreach (se_pipeline_stages() as $stage) {
        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($stage));
        $out[$stage] = (string) get_option('se_google_conv_action_' . (int) $brand_id . '_' . $slug);
    }

    return $out;
}

function se_google_ui_save_mapping($brand_id, $stage, $action_id)
{
    if (!in_array($stage, se_pipeline_stages(), true)) {
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

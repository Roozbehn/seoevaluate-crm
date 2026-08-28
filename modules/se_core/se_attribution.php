<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Attribution capture for Perfex web-to-lead forms.
 *
 * Click identifiers, UTMs and consent are captured in the visitor's browser,
 * kept in a first-party cookie, and posted back as hidden fields on submission.
 * Values land in the real columns se_core added to tblleads.
 *
 * Attribution semantics (deliberate, and what Meta/Google expect):
 *   - FIRST-touch is immutable. The original click ids, UTMs, fbc/fbp,
 *     ctwa_clid, landing_url, referrer and first_touch_at are recorded once and
 *     never overwritten — they live in the primary columns.
 *   - LAST-touch is reported separately in parallel `last_*` columns, so the
 *     latest click never destroys the original attribution.
 *   - fbc/fbp are read from the Meta pixel's _fbc/_fbp cookies; ctwa_clid from
 *     the landing URL. Neither is ever hashed here (raw per Meta's spec).
 *   - Consent (ads + marketing + the consent-text version) is captured from the
 *     form and mirrored into the brand-scoped consent ledger.
 */

hooks()->add_action('app_web_to_lead_form_head', 'se_attr_form_head');
hooks()->add_action('web_to_lead_form_start', 'se_attr_hidden_fields');
hooks()->add_action('web_to_lead_form_submitted', 'se_attr_persist');

/** Immutable first-touch fields, persisted to the primary lead columns. */
function se_attr_first_touch_fields()
{
    return [
        'gclid', 'gbraid', 'wbraid', 'fbclid', 'fbc', 'fbp', 'ctwa_clid',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'landing_url', 'referrer', 'first_touch_at',
    ];
}

/** Parallel last-touch fields, persisted to last_* columns. */
function se_attr_last_touch_map()
{
    return [
        'last_gclid'        => 'gclid',
        'last_gbraid'       => 'gbraid',
        'last_wbraid'       => 'wbraid',
        'last_fbclid'       => 'fbclid',
        'last_utm_source'   => 'utm_source',
        'last_utm_medium'   => 'utm_medium',
        'last_utm_campaign' => 'utm_campaign',
        'last_utm_term'     => 'utm_term',
        'last_utm_content'  => 'utm_content',
        'last_touch_at'     => 'last_touch_at',
    ];
}

function se_attr_hidden_fields()
{
    $names = se_attr_first_touch_fields();
    foreach (array_keys(se_attr_last_touch_map()) as $lastCol) {
        $names[] = $lastCol;
    }

    foreach ($names as $field) {
        echo '<input type="hidden" name="se_' . $field . '" id="se_' . $field . '" value="" />' . PHP_EOL;
    }
}

function se_attr_form_head()
{
    // URL-driven values captured last-touch; the rest are read from cookies.
    $urlKeys = json_encode(['gclid', 'gbraid', 'wbraid', 'fbclid',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'ctwa_clid']);

    echo <<<HTML
<script>
(function () {
  var COOKIE = 'se_attr', DAYS = 90;
  var URLK = $urlKeys;

  function readCookie(n) {
    try { var m = document.cookie.match(new RegExp('(?:^|;\\\\s*)' + n + '=([^;]*)'));
      return m ? decodeURIComponent(m[1]) : ''; } catch (e) { return ''; }
  }
  function readStore() { try { return JSON.parse(readCookie(COOKIE) || '{}'); } catch (e) { return {}; } }
  function writeStore(d) {
    try { var e = new Date(); e.setTime(e.getTime() + DAYS * 864e5);
      document.cookie = COOKIE + '=' + encodeURIComponent(JSON.stringify(d)) + ';expires=' + e.toUTCString()
        + ';path=/;SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : ''); } catch (e) {}
  }
  function nowStr() { return new Date().toISOString().slice(0, 19).replace('T', ' '); }

  var p = new URLSearchParams(location.search);
  var s = readStore();
  s.first = s.first || {};
  s.last  = s.last  || {};

  // First-touch bootstrap: recorded once, never overwritten.
  if (!s.first.first_touch_at) {
    s.first.first_touch_at = nowStr();
    s.first.landing_url = location.href.slice(0, 1000);
    s.first.referrer = (document.referrer || '').slice(0, 1000);
  }
  // fbc/fbp from the Meta pixel cookies (raw), first-touch only.
  var fbc = readCookie('_fbc'), fbp = readCookie('_fbp');
  if (fbc && !s.first.fbc) { s.first.fbc = fbc; }
  if (fbp && !s.first.fbp) { s.first.fbp = fbp; }

  var touched = false;
  URLK.forEach(function (k) {
    var v = p.get(k);
    if (v) {
      if (!s.first[k]) { s.first[k] = v; }   // first-touch: keep original
      s.last[k] = v;                         // last-touch: always freshest
      touched = true;
    }
  });
  if (touched) { s.last.last_touch_at = nowStr(); }

  writeStore(s);

  function fill() {
    var set = function (id, val) { var el = document.getElementById(id); if (el && val) { el.value = val; } };
    Object.keys(s.first).forEach(function (k) { set('se_' + k, s.first[k]); });
    Object.keys(s.last).forEach(function (k) {
      set('se_last_' + k, s.last[k]);           // se_last_gclid, ...
    });
    set('se_last_touch_at', s.last.last_touch_at);
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fill); } else { fill(); }
})();
</script>
HTML;
}

/**
 * Persists posted attribution + consent onto the newly created lead.
 * Runs on web_to_lead_form_submitted where \$_POST is available. The brand is
 * resolved from the submitted form, never from the visitor.
 */
function se_attr_persist($data)
{
    if (empty($data['lead_id'])) {
        return;
    }

    $CI = &get_instance();
    $lead_id = (int) $data['lead_id'];

    $update = [];

    // First-touch -> primary columns.
    foreach (se_attr_first_touch_fields() as $field) {
        $value = $CI->input->post('se_' . $field);
        if ($value !== null && $value !== '') {
            $update[$field] = mb_substr((string) $value, 0, 1000);
        }
    }
    if (!empty($update['first_touch_at']) && !strtotime($update['first_touch_at'])) {
        unset($update['first_touch_at']);
    }

    // Last-touch -> last_* columns.
    foreach (se_attr_last_touch_map() as $col => $srcField) {
        $postName = $col === 'last_touch_at' ? 'se_last_touch_at' : 'se_last_' . $srcField;
        $value = $CI->input->post($postName);
        if ($value !== null && $value !== '') {
            $update[$col] = mb_substr((string) $value, 0, 1000);
        }
    }
    if (!empty($update['last_touch_at']) && !strtotime($update['last_touch_at'])) {
        unset($update['last_touch_at']);
    }

    // Consent: coerce to 0/1; capture the consent-text version.
    $consentAds       = (int) (bool) $CI->input->post('se_consent_ads');
    $consentMarketing = (int) (bool) $CI->input->post('se_consent_marketing');
    $consentVersion   = $CI->input->post('se_consent_text_version');
    $update['consent_ads']       = $consentAds;
    $update['consent_marketing'] = $consentMarketing;
    if ($consentVersion !== null && $consentVersion !== '') {
        $update['consent_text_version'] = mb_substr((string) $consentVersion, 0, 32);
    }

    // Brand comes from the form.
    $brand_id = 0;
    if (!empty($data['form_id'])) {
        $CI->db->select('brand_id')->where('id', (int) $data['form_id']);
        $form = $CI->db->get(db_prefix() . 'web_to_lead')->row();
        if ($form && (int) $form->brand_id > 0) {
            $brand_id = (int) $form->brand_id;
            $update['brand_id'] = $brand_id;
        }
    }

    $CI->db->where('id', $lead_id)->update(db_prefix() . 'leads', $update);

    // Mirror consent into the brand-scoped ledger (append-only).
    if (function_exists('se_consent_record')) {
        $version = $update['consent_text_version'] ?? null;
        if ($consentAds) {
            se_consent_record($brand_id, 'lead', $lead_id, 'ads', 'granted', $version, 'web_to_lead');
        }
        if ($consentMarketing) {
            se_consent_record($brand_id, 'lead', $lead_id, 'marketing', 'granted', $version, 'web_to_lead');
        }
    }

    log_activity('SE attribution captured [lead ' . $lead_id . ']');
}

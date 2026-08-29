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

/**
 * Render the consent control for a web-to-lead form.
 *
 * Server-rendered from approved configuration, never from the page author's
 * markup. Unchecked by default and with no way to express a pre-checked box —
 * a pre-ticked consent control is not freely given consent.
 *
 * With no approved text for the brand, NOTHING grant-capable is rendered.
 */
function se_attr_consent_fields($brand_id = 0, $lang = 'en')
{
    if (!function_exists('se_consent_text_configured')) {
        return;
    }

    $any = false;

    foreach (se_consent_configurable_purposes() as $purpose) {
        if (!se_consent_text_configured($brand_id, $purpose)) {
            continue;
        }

        $any  = true;
        $text = se_consent_text($brand_id, $purpose, $lang);

        echo '<div class="form-group se-consent-field">'
           . '<label style="font-weight:normal">'
           . '<input type="checkbox" name="se_consent_' . htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8')
           . '" value="yes" />&nbsp;'
           . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
           . '</label></div>' . PHP_EOL;
    }

    // No hidden version field is emitted: the server resolves the version.
    if (!$any) {
        echo '<!-- se: no approved consent text configured for this brand -->' . PHP_EOL;
    }
}

function se_attr_form_head()
{
    // URL-driven values captured last-touch; the rest are read from cookies.
    $urlKeys = json_encode(['gclid', 'gbraid', 'wbraid', 'fbclid',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'ctwa_clid']);

    $trackingAllowed = function_exists('se_consent_tracking_allowed')
        ? (se_consent_tracking_allowed(se_attr_form_brand()) ? 'true' : 'false')
        : 'false';

    echo '<script>window.SE_ATTR_TRACKING_ALLOWED = ' . $trackingAllowed . ';</script>' . PHP_EOL;

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

  // Tracking storage is gated: the 90-day first-party attribution cookie is
  // only written when this brand has approved, enabled ads-consent text.
  // It used to be written on every page view, before anyone agreed to
  // anything.
  if (!window.SE_ATTR_TRACKING_ALLOWED) { return; }

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

    /* --- BRAND FIRST -------------------------------------------------- *
     * The brand is resolved from the submitted FORM, server-side, before any
     * consent decision — the decision depends on that brand's approved text.
     * A visitor never chooses the brand.
     */
    $brand_id = 0;

    if (!empty($data['form_id'])) {
        $CI->db->select('brand_id')->where('id', (int) $data['form_id']);
        $form = $CI->db->get(db_prefix() . 'web_to_lead')->row();

        if ($form && (int) $form->brand_id > 0) {
            $brand_id = (int) $form->brand_id;
            $update['brand_id'] = $brand_id;
        }
    }

    /* --- CONSENT ------------------------------------------------------- *
     * The old rule was `(int) (bool) $CI->input->post('se_consent_ads')`.
     * Every non-empty string is truthy in PHP, so posting "no", "hayir",
     * "false" or "0"(string) granted consent — the same class of defect that
     * was fixed for Meta Lead Ads, still live on the web form.
     *
     * The decision now comes from se_consent_decide()'s affirmative allowlist,
     * and NOTHING else. A missing field is unknown, which is not consent.
     */
    $decisions = [];

    foreach (['ads' => 'se_consent_ads', 'marketing' => 'se_consent_marketing'] as $purpose => $field) {
        $raw = $CI->input->post($field);

        $decisions[$purpose] = [
            // A field that was never submitted is UNKNOWN, not a refusal:
            // the form may simply not offer that purpose.
            'state' => $raw === null ? SE_CONSENT_UNKNOWN : se_consent_decide($raw),
            'raw'   => $raw === null ? null : (string) $raw,
            'field' => $field,
        ];
    }

    // The derived flag is written from the DECISION, never from the raw post.
    $update['consent_ads']       = $decisions['ads']['state'] === SE_CONSENT_GRANTED ? 1 : 0;
    $update['consent_marketing'] = $decisions['marketing']['state'] === SE_CONSENT_GRANTED ? 1 : 0;

    /* The consent-text version is resolved SERVER-side from this brand's
     * approved configuration. `se_consent_text_version` arrives as a hidden
     * field and is attacker-controlled, so it is read only to be ignored —
     * and a mismatch is worth knowing about. */
    $claimedVersion = $CI->input->post('se_consent_text_version');
    $serverVersion  = function_exists('se_consent_configured_version')
        ? se_consent_configured_version($brand_id)
        : '';

    if ($serverVersion !== '') {
        $update['consent_text_version'] = $serverVersion;
    }

    if ($claimedVersion !== null && $serverVersion !== '' && (string) $claimedVersion !== $serverVersion) {
        log_activity('SE consent version mismatch ignored [lead ' . $lead_id . ']');
    }

    $CI->db->where('id', $lead_id)->update(db_prefix() . 'leads', $update);

    /* --- LEDGER (authoritative) ---------------------------------------- *
     * A grant is a grant; an explicit refusal is recorded as a WITHDRAWAL so
     * "we asked and they said no" is provable. An unknown answer records
     * nothing, and nothing is granted.
     */
    if (function_exists('se_consent_grant')) {
        foreach ($decisions as $purpose => $d) {
            if ($d['state'] === SE_CONSENT_GRANTED) {
                // Fail closed: never record a grant for a purpose that has no
                // approved text behind it.
                if (function_exists('se_consent_text_configured')
                    && !se_consent_text_configured($brand_id, $purpose)) {
                    log_activity('SE consent grant refused, no approved text [lead ' . $lead_id . ', ' . $purpose . ']');
                    continue;
                }

                se_consent_grant($brand_id, $lead_id, $purpose, 'web_to_lead', $d['field'], $d['raw']);
            } elseif ($d['state'] === SE_CONSENT_WITHDRAWN) {
                se_consent_withdraw($brand_id, $lead_id, $purpose, 'web_to_lead', $d['field'], $d['raw']);
            }
        }
    }

    log_activity('SE attribution captured [lead ' . $lead_id . ']');
}

/**
 * Brand for the web-to-lead form currently being rendered.
 * Resolved server-side from the form id in the URI; never from a request field.
 */
function se_attr_form_brand()
{
    $CI = &get_instance();

    // /forms/wtl/<key> — the last URI segment identifies the form.
    $key = $CI->uri->segment(3);

    if (!$key) {
        return 0;
    }

    $CI->db->select('brand_id')->where('form_key', $key);
    $row = $CI->db->get(db_prefix() . 'web_to_lead')->row();

    return $row ? (int) $row->brand_id : 0;
}


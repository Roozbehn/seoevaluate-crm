<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Attribution capture for Perfex web-to-lead forms.
 *
 * Click identifiers and UTMs are captured in the visitor's browser, kept in a
 * first-party cookie, and posted back as hidden fields when a form is
 * submitted. The values land in the real columns se_core added to tblleads.
 *
 * Attribution semantics, chosen deliberately:
 *   - click ids and UTMs are LAST-touch. A newer ad click overwrites an older
 *     one, because that is how Meta and Google themselves attribute.
 *   - first_touch_at, landing_url and referrer are FIRST-touch and never
 *     overwritten, so the original entry point survives.
 */

hooks()->add_action('app_web_to_lead_form_head', 'se_attr_form_head');
hooks()->add_action('web_to_lead_form_start', 'se_attr_hidden_fields');
hooks()->add_action('web_to_lead_form_submitted', 'se_attr_persist');

function se_attr_last_touch_fields()
{
    return [
        'gclid', 'gbraid', 'wbraid', 'fbclid',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
    ];
}

function se_attr_first_touch_fields()
{
    return ['landing_url', 'referrer', 'first_touch_at'];
}

function se_attr_all_fields()
{
    return array_merge(se_attr_last_touch_fields(), se_attr_first_touch_fields());
}

function se_attr_hidden_fields()
{
    foreach (se_attr_all_fields() as $field) {
        echo '<input type="hidden" name="se_' . $field . '" id="se_' . $field . '" value="" />' . PHP_EOL;
    }
}

function se_attr_form_head()
{
    $last  = json_encode(se_attr_last_touch_fields());
    $first = json_encode(se_attr_first_touch_fields());

    echo <<<HTML
<script>
(function () {
  var COOKIE = 'se_attr', DAYS = 90;
  var LAST = $last, FIRST = $first;

  function readCookie() {
    try {
      var m = document.cookie.match(/(?:^|;\s*)se_attr=([^;]*)/);
      return m ? JSON.parse(decodeURIComponent(m[1])) : {};
    } catch (e) { return {}; }
  }

  function writeCookie(data) {
    try {
      var d = new Date();
      d.setTime(d.getTime() + DAYS * 864e5);
      document.cookie = COOKIE + '=' + encodeURIComponent(JSON.stringify(data))
        + ';expires=' + d.toUTCString() + ';path=/;SameSite=Lax'
        + (location.protocol === 'https:' ? ';Secure' : '');
    } catch (e) {}
  }

  var params = new URLSearchParams(location.search);
  var store  = readCookie();

  // Last touch: a fresh click id or campaign overwrites what was stored.
  LAST.forEach(function (k) {
    var v = params.get(k);
    if (v) { store[k] = v; }
  });

  // First touch: recorded once, never overwritten.
  if (!store.first_touch_at) {
    store.first_touch_at = new Date().toISOString().slice(0, 19).replace('T', ' ');
    store.landing_url = location.href.slice(0, 1000);
    store.referrer = (document.referrer || '').slice(0, 1000);
  }

  writeCookie(store);

  function fill() {
    LAST.concat(FIRST).forEach(function (k) {
      var el = document.getElementById('se_' + k);
      if (el && store[k]) { el.value = store[k]; }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fill);
  } else { fill(); }
})();
</script>
HTML;
}

/**
 * Persists what the form posted onto the lead record.
 *
 * Runs on web_to_lead_form_submitted, where \$_POST is still available. The
 * brand comes from the form, not the visitor - a form belongs to one clinic.
 */
function se_attr_persist($data)
{
    if (empty($data['lead_id'])) {
        return;
    }

    $CI = &get_instance();

    $update = [];

    foreach (se_attr_all_fields() as $field) {
        $value = $CI->input->post('se_' . $field);

        if ($value !== null && $value !== '') {
            $update[$field] = substr((string) $value, 0, 1000);
        }
    }

    if (!empty($update['first_touch_at']) && !strtotime($update['first_touch_at'])) {
        unset($update['first_touch_at']);
    }

    // Resolve the brand from the form that was submitted.
    if (!empty($data['form_id'])) {
        $CI->db->select('brand_id')->where('id', (int) $data['form_id']);
        $form = $CI->db->get(db_prefix() . 'web_to_lead')->row();

        if ($form && (int) $form->brand_id > 0) {
            $update['brand_id'] = (int) $form->brand_id;
        }
    }

    if (empty($update)) {
        return;
    }

    $CI->db->where('id', (int) $data['lead_id']);
    $CI->db->update(db_prefix() . 'leads', $update);

    log_activity('SE attribution captured [lead ' . (int) $data['lead_id'] . ']');
}

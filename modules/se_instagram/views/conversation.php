<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $c = $conversation;
$evidence_redacted = $this->input->get('evidence') === 'redacted';
$contact_label = se_ig_redacted_contact($c->igsid); ?>
<div id="wrapper"><div class="content">

<?php se_ui_header(_l('se_instagram') . ' — ' . $contact_label, [
    ['href' => admin_url('se_instagram/se_instagram/inbox'), 'label' => _l('se_back'), 'icon' => 'fa-arrow-left'],
]); ?>

<div class="row">
  <div class="col-md-8"><div class="panel_s"><div class="panel-body">

    <?php /* Thread + composer shared with WhatsApp (se_core/se_chat_ui.php).
             Instagram has no template mechanism: outside the 24h window the
             server gates sending and the composer says so. */ ?>
    <?php se_ui_chat_thread($messages, $media ?? [], [
        'channel'  => 'ig',
        'redacted' => $evidence_redacted,
        'empty'    => _l('se_ig_no_messages'),
    ]); ?>

    <?php if (!$policy['allowed']) {
        se_ui_chat_composer([
            'mode'   => 'gated',
            'title'  => _l('se_ig_sending_gated'),
            'reason' => _l('se_ig_blocked_' . $policy['reason']),
        ]);
    } else {
        se_ui_chat_composer([
            'mode'         => 'freeform',
            'action'       => admin_url('se_instagram/se_instagram/reply/' . (int) $c->id),
            'window_label' => _l('se_ig_window_open'),
            'window_text'  => _l('se_ig_window_until') . ' ' . (string) $policy['expires_at'],
            'maxlength'    => 1000,
            'accept'       => 'image/*,audio/*,video/*',
            'max_upload_mb' => 8,
            'attach_hint_key' => 'se_chat_attach_hint_ig',
            'placeholder'  => _l('se_chat_placeholder'),
            'label_send'   => _l('se_chat_send'),
        ]);
    } ?>

  </div></div></div>

  <div class="col-md-4">
    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_ig_conversation')); ?></h5>
      <?php se_ui_kv([
          _l('se_brand')          => se_ui_brand_badge((int) $c->brand_id),
          _l('se_ig_contact')     => html_escape($contact_label),
          _l('se_ig_ad_referral') => !empty($c->referral_ad_id)
              ? '<span class="label label-info">ad ' . html_escape($c->referral_ad_id) . '</span>'
                . (!empty($c->referral_source) ? ' <small class="text-muted">' . html_escape($c->referral_source) . '</small>' : '')
              : '<span class="text-muted">&mdash;</span>',
          _l('se_appt_lead')      => !empty($c->lead_id)
              ? '<a href="' . admin_url('leads/index/' . (int) $c->lead_id) . '">#' . (int) $c->lead_id . '</a>'
              : '<span class="text-muted">&mdash;</span>',
          _l('se_ig_window')      => $policy['window_open'] ? se_ui_badge('open', _l('se_ig_window_open')) : se_ui_badge('closed', _l('se_ig_window_closed')),
          _l('se_ig_last_inbound') => html_escape((string) ($c->last_inbound_at ?: '—')),
          _l('se_ig_unread')      => (int) $c->unread_count,
      ], true); ?>
    </div></div>

    <?php if (staff_can('edit', 'se_instagram')) { ?>
    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_ig_assigned_staff')); ?></h5>
      <?php echo form_open(admin_url('se_instagram/se_instagram/assign/' . (int) $c->id)); ?>
        <div class="form-group"><select class="form-control" name="staff_id">
          <option value="0"><?php echo html_escape(_l('se_ig_unassigned')); ?></option>
          <?php foreach ($staff as $s) { ?>
            <option value="<?php echo (int) $s['staffid']; ?>"<?php echo (int) $c->assigned_staff === (int) $s['staffid'] ? ' selected' : ''; ?>>
              <?php echo html_escape(trim($s['firstname'] . ' ' . $s['lastname'])); ?></option>
          <?php } ?>
        </select></div>
        <button type="submit" class="btn btn-default btn-sm"><?php echo html_escape(_l('submit')); ?></button>
      <?php echo form_close(); ?>
    </div></div>
    <?php } ?>

    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_ig_queue_health')); ?></h5>
      <?php se_ui_counters($queued); ?>
      <?php if (isset($tracker) && function_exists('se_ui_outbound_tracker') && is_array($dispatch_eta)) {
          se_ui_outbound_tracker($tracker, $dispatch_eta);
      } ?>
    </div></div>
  </div>
</div>

</div></div>
<?php init_tail(); ?></body></html>

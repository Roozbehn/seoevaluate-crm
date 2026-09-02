<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $c = $conversation;
$evidence_redacted = $this->input->get('evidence') === 'redacted';
$contact_label = $evidence_redacted ? se_wa_redacted_contact($c->wa_user_id) : $c->wa_user_id; ?>
<div id="wrapper"><div class="content">

<?php se_ui_header(_l('se_whatsapp') . ' — ' . $contact_label, [
    ['href' => admin_url('se_whatsapp/se_whatsapp/inbox'), 'label' => _l('se_back'), 'icon' => 'fa-arrow-left'],
]); ?>

<div class="row">
  <div class="col-md-8"><div class="panel_s"><div class="panel-body">

    <?php /* Thread + composer are shared with Instagram (se_core/se_chat_ui.php).
             Which composer is offered is decided by the SERVER from the service
             window (se_wa_compose_policy), never by the page. */ ?>
    <?php se_ui_chat_thread($messages, $media ?? [], [
        'channel'  => 'wa',
        'redacted' => $evidence_redacted,
        'empty'    => _l('se_wa_no_messages'),
    ]); ?>

    <?php if (!$policy['allowed']) {
        se_ui_chat_composer([
            'mode'   => 'gated',
            'title'  => _l('se_wa_sending_gated'),
            'reason' => _l('se_wa_blocked_' . $policy['reason']),
        ]);
    } elseif ($policy['mode'] === 'freeform') {
        se_ui_chat_composer([
            'mode'         => 'freeform',
            'action'       => admin_url('se_whatsapp/se_whatsapp/reply/' . (int) $c->id),
            'window_label' => _l('se_wa_window_open'),
            'window_text'  => _l('se_wa_window_until') . ' ' . (string) $policy['expires_at'],
            'maxlength'    => 4096,
            'placeholder'  => _l('se_chat_placeholder'),
            'label_send'   => _l('se_chat_send'),
        ]);
    } else {
        $can_sync = function_exists('se_staff_can_configure_brands') && se_staff_can_configure_brands()
                 && function_exists('se_wa_waba_for_brand') && se_wa_waba_for_brand((int) $c->brand_id) !== '';
        se_ui_chat_composer([
            'mode'         => 'template',
            'action'       => admin_url('se_whatsapp/se_whatsapp/reply/' . (int) $c->id),
            'window_label' => _l('se_wa_window_closed'),
            'window_text'  => _l('se_wa_reply_template_required'),
            'templates'    => $templates,
            'label_send'   => _l('se_chat_send_template'),
            'sync'         => $can_sync ? [
                'action' => admin_url('se_whatsapp/se_whatsapp/sync_templates'),
                'brand'  => (int) $c->brand_id,
                'back'   => admin_url('se_whatsapp/se_whatsapp/conversation/' . (int) $c->id),
            ] : null,
        ]);
    } ?>

  </div></div></div>

  <div class="col-md-4">
    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_wa_conversation')); ?></h5>
      <?php se_ui_kv([
          _l('se_wa_brand')          => se_ui_brand_badge((int) $c->brand_id),
          _l('se_wa_contact')        => html_escape($contact_label),
          _l('se_appt_lead')         => !empty($c->lead_id)
              ? '<a href="' . admin_url('leads/index/' . (int) $c->lead_id) . '">#' . (int) $c->lead_id . '</a>'
              : '<span class="text-muted">&mdash;</span>',
          _l('se_wa_window')         => $policy['window_open']
              ? se_ui_badge('open', _l('se_wa_window_open'))
              : se_ui_badge('closed', _l('se_wa_window_closed')),
          _l('se_wa_last_inbound')   => html_escape((string) ($c->last_inbound_at ?: '—')),
          _l('se_wa_unread')         => (int) $c->unread_count,
      ], true); ?>
    </div></div>

    <?php if (staff_can('edit', 'se_whatsapp')) { ?>
    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_wa_assigned_staff')); ?></h5>
      <?php echo form_open(admin_url('se_whatsapp/se_whatsapp/assign/' . (int) $c->id)); ?>
        <div class="form-group">
          <select class="form-control" name="staff_id">
            <option value="0"><?php echo html_escape(_l('se_wa_unassigned')); ?></option>
            <?php foreach ($staff as $s) { ?>
              <option value="<?php echo (int) $s['staffid']; ?>"
                <?php echo (int) $c->assigned_staff === (int) $s['staffid'] ? ' selected' : ''; ?>>
                <?php echo html_escape($evidence_redacted
                    ? ('Authorized staff #' . (int) $s['staffid'])
                    : trim($s['firstname'] . ' ' . $s['lastname'])); ?>
              </option>
            <?php } ?>
          </select>
        </div>
        <button type="submit" class="btn btn-default btn-sm"><?php echo html_escape(_l('submit')); ?></button>
      <?php echo form_close(); ?>
    </div></div>
    <?php } ?>

    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_wa_queue_health')); ?></h5>
      <?php se_ui_counters($queued); ?>
      <?php if (isset($tracker) && function_exists('se_ui_outbound_tracker') && is_array($dispatch_eta)) {
          se_ui_outbound_tracker($tracker, $dispatch_eta);
      } ?>
    </div></div>
  </div>
</div>

</div></div>
<?php init_tail(); ?></body></html>

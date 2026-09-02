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
    <?php if (empty($messages)) { se_ui_empty(_l('se_ig_no_messages')); } else { ?>
      <div style="max-height:520px;overflow-y:auto">
      <?php foreach ($messages as $m) { $out = ($m['direction'] ?? 'in') === 'out'; ?>
        <div class="mbot15" style="text-align:<?php echo $out ? 'right' : 'left'; ?>">
          <div style="display:inline-block;max-width:80%;padding:8px 12px;border-radius:6px;border:1px solid rgba(128,128,128,.35)">
            <small class="text-muted">
              <?php echo html_escape($out ? _l('se_ig_direction_out') : _l('se_ig_direction_in')); ?>
              <?php if ($out && !empty($m['source'])) { echo ' &middot; ' . html_escape(_l('se_ig_source_' . $m['source'])); } ?>
              &middot; <?php echo html_escape($m['type'] ?? 'text'); ?>
              <?php if ($out && !empty($m['delivery_state'])) { echo ' &middot; ' . se_ui_badge($m['delivery_state']); } ?>
            </small><br />
            <?php if (!empty($m['body'])) {
                echo $evidence_redacted ? '<span class="text-muted">[message redacted for evidence]</span>' : nl2br(html_escape($m['body']));
            } elseif (!empty($m['media_ref'])) { ?>
              <span class="label label-default"><i class="fa fa-paperclip"></i> <?php echo html_escape(_l('se_ig_media_placeholder')); ?></span>
            <?php } else { ?><span class="text-muted">&mdash;</span><?php } ?>
            <br /><small class="text-muted"><?php echo html_escape((string) ($m['received_at'] ?: $m['sent_at'] ?: $m['date_created'])); ?></small>
          </div>
        </div>
      <?php } ?>
      </div>
    <?php } ?>

    <hr />

    <?php if (!$policy['allowed']) { ?>
      <div class="alert alert-warning"><i class="fa fa-lock"></i>
        <strong><?php echo html_escape(_l('se_ig_sending_gated')); ?></strong><br />
        <?php echo html_escape(_l('se_ig_blocked_' . $policy['reason'])); ?>
      </div>
      <textarea class="form-control" rows="3" disabled placeholder="<?php echo html_escape(_l('se_ig_composer_disabled')); ?>"></textarea>
    <?php } else { ?>
      <?php echo form_open(admin_url('se_instagram/se_instagram/reply/' . (int) $c->id)); ?>
        <div class="form-group">
          <label for="body" class="control-label">
            <?php echo html_escape(_l('se_ig_reply_freeform')); ?>
            <?php echo se_ui_badge('open', _l('se_ig_window_open')); ?>
            <small class="text-muted"><?php echo html_escape(_l('se_ig_window_until')); ?> <?php echo html_escape((string) $policy['expires_at']); ?></small>
          </label>
          <textarea class="form-control" rows="3" id="body" name="body" maxlength="1000" required></textarea>
        </div>
        <button type="submit" class="btn btn-primary"><?php echo html_escape(_l('se_ig_queue_reply')); ?></button>
      <?php echo form_close(); ?>
    <?php } ?>
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

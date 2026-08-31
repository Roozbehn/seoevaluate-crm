<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $c = $conversation; ?>
<div id="wrapper"><div class="content">

<?php se_ui_header(_l('se_whatsapp') . ' — ' . $c->wa_user_id, [
    ['href' => admin_url('se_whatsapp/se_whatsapp/inbox'), 'label' => _l('se_back'), 'icon' => 'fa-arrow-left'],
]); ?>

<div class="row">
  <div class="col-md-8"><div class="panel_s"><div class="panel-body">

    <?php /* Thread. Direction drives alignment so a scan of the column reads
             as a conversation rather than a log. */ ?>
    <?php if (empty($messages)) { se_ui_empty(_l('se_wa_no_messages')); } else { ?>
      <div style="max-height:520px;overflow-y:auto">
      <?php foreach ($messages as $m) {
          $out = ($m['direction'] ?? 'in') === 'out'; ?>
        <div class="mbot15" style="text-align:<?php echo $out ? 'right' : 'left'; ?>">
          <div style="display:inline-block;max-width:80%;padding:8px 12px;border-radius:6px;
                      border:1px solid rgba(128,128,128,.35)">
            <small class="text-muted">
              <?php echo html_escape($out ? _l('se_wa_direction_out') : _l('se_wa_direction_in')); ?>
              <?php if ($out && !empty($m['source'])) {
                  echo ' &middot; ' . html_escape(_l('se_wa_source_' . $m['source']));
              } ?>
              &middot; <?php echo html_escape($m['type'] ?? 'text'); ?>
              <?php if ($out && !empty($m['delivery_state'])) {
                  echo ' &middot; ' . se_ui_badge($m['delivery_state']);
              } ?>
            </small><br />
            <?php if (!empty($m['body'])) { ?>
              <?php echo nl2br(html_escape($m['body'])); ?>
            <?php } elseif (!empty($m['template_name'])) { ?>
              <em><?php echo html_escape(_l('se_wa_template')); ?>: <?php echo html_escape($m['template_name']); ?></em>
            <?php } elseif (!empty($m['media_ref'])) { ?>
              <?php /* Media is referenced, never inlined: the file lives on
                       Meta's CDN and fetching it needs a controlled, validated
                       download path that does not exist yet. */ ?>
              <span class="label label-default"><i class="fa fa-paperclip"></i>
                <?php echo html_escape(_l('se_wa_media_placeholder')); ?></span>
            <?php } else { ?>
              <span class="text-muted">&mdash;</span>
            <?php } ?>
            <br /><small class="text-muted"><?php echo html_escape((string) ($m['received_at'] ?: $m['sent_at'] ?: $m['date_created'])); ?></small>
          </div>
        </div>
      <?php } ?>
      </div>
    <?php } ?>

    <hr />

    <?php /* Composer. Which control is offered is decided by the SERVER from
             the service window, never by the page. */ ?>
    <?php if (!$policy['allowed']) { ?>
      <div class="alert alert-warning">
        <i class="fa fa-lock"></i>
        <strong><?php echo html_escape(_l('se_wa_sending_gated')); ?></strong><br />
        <?php echo html_escape(_l('se_wa_blocked_' . $policy['reason'])); ?>
      </div>
      <textarea class="form-control" rows="3" disabled
                placeholder="<?php echo html_escape(_l('se_wa_composer_disabled')); ?>"></textarea>

    <?php } elseif ($policy['mode'] === 'freeform') { ?>
      <?php echo form_open(admin_url('se_whatsapp/se_whatsapp/reply/' . (int) $c->id)); ?>
        <input type="hidden" name="kind" value="text" />
        <div class="form-group">
          <label for="body" class="control-label">
            <?php echo html_escape(_l('se_wa_reply_freeform')); ?>
            <?php echo se_ui_badge('open', _l('se_wa_window_open')); ?>
            <small class="text-muted"><?php echo html_escape(_l('se_wa_window_until')); ?>
              <?php echo html_escape((string) $policy['expires_at']); ?></small>
          </label>
          <textarea class="form-control" rows="3" id="body" name="body"
                    maxlength="4096" required></textarea>
        </div>
        <button type="submit" class="btn btn-primary"><?php echo html_escape(_l('se_wa_queue_reply')); ?></button>
      <?php echo form_close(); ?>

    <?php } else { ?>
      <div class="alert alert-info">
        <i class="fa fa-clock"></i> <?php echo html_escape(_l('se_wa_reply_template_required')); ?>
      </div>
      <?php if (empty($templates)) { ?>
        <?php se_ui_empty(_l('se_wa_no_templates')); ?>
      <?php } else { ?>
        <?php echo form_open(admin_url('se_whatsapp/se_whatsapp/reply/' . (int) $c->id)); ?>
          <input type="hidden" name="kind" value="template" />
          <div class="form-group">
            <label for="template" class="control-label"><?php echo html_escape(_l('se_wa_template')); ?></label>
            <select class="form-control" id="template" name="template" required>
              <?php foreach ($templates as $t) { ?>
                <option value="<?php echo html_escape($t['name']); ?>">
                  <?php echo html_escape($t['name'] . ' (' . $t['language'] . ')'); ?>
                </option>
              <?php } ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary"><?php echo html_escape(_l('se_wa_queue_reply')); ?></button>
        <?php echo form_close(); ?>
      <?php } ?>
    <?php } ?>

  </div></div></div>

  <div class="col-md-4">
    <div class="panel_s"><div class="panel-body">
      <h5><?php echo html_escape(_l('se_wa_conversation')); ?></h5>
      <?php se_ui_kv([
          _l('se_wa_brand')          => se_ui_brand_badge((int) $c->brand_id),
          _l('se_wa_contact')        => html_escape($c->wa_user_id),
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
                <?php echo html_escape(trim($s['firstname'] . ' ' . $s['lastname'])); ?>
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
    </div></div>
  </div>
</div>

</div></div>
<?php init_tail(); ?></body></html>

<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-8 col-md-offset-2">
        <div class="panel_s">
          <div class="panel-body">
            <a href="<?php echo admin_url('se_whatsapp/inbox'); ?>">&laquo; <?php echo _l('se_wa_all'); ?></a>
            <h4><?php echo html_escape($conversation->wa_user_id); ?>
              <small><?php echo $window_open ? _l('se_wa_window_open') : _l('se_wa_window_closed'); ?></small>
            </h4>
            <hr />
            <?php foreach ($messages as $msg) : ?>
              <div class="<?php echo $msg['direction'] === 'in' ? 'text-left' : 'text-right'; ?>" style="margin-bottom:8px">
                <span class="label label-<?php echo $msg['direction'] === 'in' ? 'default' : 'success'; ?>">
                  <?php echo html_escape($msg['type']); ?>
                </span>
                <?php if ($msg['type'] === 'text') : ?>
                  <div><?php echo nl2br(html_escape($msg['body'])); ?></div>
                <?php else : ?>
                  <div class="text-muted"><?php echo html_escape($msg['media_ref'] ?: $msg['type']); ?></div>
                <?php endif; ?>
                <small class="text-muted">
                  <?php echo html_escape($msg['received_at'] ?: $msg['sent_at']); ?>
                  <?php echo $msg['delivery_state'] ? '· ' . html_escape($msg['delivery_state']) : ''; ?>
                </small>
              </div>
            <?php endforeach; ?>

            <hr />
            <?php if ($window_open) : ?>
              <p class="text-muted"><?php echo _l('se_wa_reply_freeform'); ?></p>
            <?php else : ?>
              <p class="text-warning"><?php echo _l('se_wa_reply_template_required'); ?></p>
            <?php endif; ?>
            <p class="text-info small"><?php echo _l('se_wa_sending_gated'); ?></p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php init_tail(); ?>
</body></html>

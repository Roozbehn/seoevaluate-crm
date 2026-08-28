<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">
            <h4 class="no-margin"><?php echo _l('se_whatsapp'); ?></h4>
            <hr />
            <div class="btn-group" style="margin-bottom:12px">
              <a href="<?php echo admin_url('se_whatsapp/inbox'); ?>" class="btn btn-default btn-sm"><?php echo _l('se_wa_all'); ?></a>
              <a href="<?php echo admin_url('se_whatsapp/inbox?assigned=me'); ?>" class="btn btn-default btn-sm"><?php echo _l('se_wa_assigned_me'); ?></a>
              <a href="<?php echo admin_url('se_whatsapp/inbox?assigned=unassigned'); ?>" class="btn btn-default btn-sm"><?php echo _l('se_wa_unassigned'); ?></a>
            </div>
            <?php if (empty($conversations)) : ?>
              <p class="text-muted"><?php echo _l('se_wa_no_conversations'); ?></p>
            <?php else : ?>
              <table class="table table-striped">
                <thead><tr>
                  <th><?php echo _l('se_wa_contact'); ?></th>
                  <th><?php echo _l('se_wa_unread'); ?></th>
                  <th><?php echo _l('se_wa_window'); ?></th>
                  <th><?php echo _l('se_wa_last_inbound'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($conversations as $c) : ?>
                  <tr>
                    <td><a href="<?php echo admin_url('se_whatsapp/conversation/' . (int) $c['id']); ?>"><?php echo html_escape($c['wa_user_id']); ?></a></td>
                    <td><?php echo (int) $c['unread_count']; ?></td>
                    <td><?php echo (!empty($c['window_expires_at']) && strtotime($c['window_expires_at']) > time()) ? _l('se_wa_window_open') : _l('se_wa_window_closed'); ?></td>
                    <td><?php echo html_escape($c['last_inbound_at']); ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php init_tail(); ?>
</body></html>

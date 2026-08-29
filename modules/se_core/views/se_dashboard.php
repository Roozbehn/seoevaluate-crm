<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">

<?php se_ui_header(_l('se_group'), [
    ['href' => admin_url('se_core/se_reports/health'), 'label' => _l('se_reports_health'), 'icon' => 'fa-heartbeat'],
]); ?>

<?php if (empty($has_brand)) { se_ui_no_brand_screen(); } else { ?>

  <?php if (!empty($warnings)) { ?>
    <div class="row"><div class="col-md-12">
      <?php foreach ($warnings as $w) {
          $cls = $w['level'] === 'error' ? 'alert-danger' : ($w['level'] === 'warning' ? 'alert-warning' : 'alert-info'); ?>
        <div class="alert <?php echo $cls; ?>"><i class="fa fa-exclamation-triangle"></i> <?php echo html_escape($w['text']); ?></div>
      <?php } ?>
    </div></div>
  <?php } ?>

  <?php /* Brand context: with several brands a bare number is ambiguous. */ ?>
  <div class="row"><div class="col-md-12"><div class="mbot15">
    <span class="text-muted"><?php echo html_escape(_l('se_brand_context')); ?>:</span>
    <?php foreach ($brands as $b) { echo ' ' . se_ui_brand_badge((int) $b['id']); } ?>
    <?php if (empty($brands)) { echo ' <span class="text-muted">&mdash;</span>'; } ?>
  </div></div></div>

  <div class="row">
    <div class="col-md-3 col-sm-6"><?php echo se_ui_stat_card(_l('leads'), $stats['leads'], admin_url('leads'), 'fa-filter'); ?></div>
    <div class="col-md-3 col-sm-6"><?php echo se_ui_stat_card(_l('se_patients'), $stats['patients'], admin_url('se_core/se_patients'), 'fa-user-md'); ?></div>
    <div class="col-md-3 col-sm-6"><?php echo se_ui_stat_card(_l('se_dash_appts_today'), $stats['appts_today'], admin_url('se_appointments/se_appointments/manage'), 'fa-calendar'); ?></div>
    <div class="col-md-3 col-sm-6"><?php echo se_ui_stat_card(_l('se_dash_appts_upcoming'), $stats['appts_upcoming'], admin_url('se_appointments/se_appointments/manage'), 'fa-calendar-plus-o'); ?></div>
  </div>

  <div class="row">
    <div class="col-md-3 col-sm-6"><?php echo se_ui_stat_card(_l('se_appt_status_no_show'), $stats['appts_no_show'], admin_url('se_appointments/se_appointments/manage?status=no_show'), 'fa-user-times'); ?></div>
    <div class="col-md-3 col-sm-6"><?php echo se_ui_stat_card(_l('se_wa_unread'), $stats['wa_unread'], admin_url('se_whatsapp/se_whatsapp/inbox'), 'fa-whatsapp'); ?></div>
    <div class="col-md-3 col-sm-6"><?php echo se_ui_stat_card(_l('se_dash_meta_pending'), $stats['meta_pending'], admin_url('se_core/se_meta'), 'fa-facebook-square'); ?></div>
    <div class="col-md-3 col-sm-6"><?php echo se_ui_stat_card(_l('se_dash_outbox_pending'), $stats['outbox_pending'], admin_url('se_core/se_outbox?status=pending'), 'fa-paper-plane-o'); ?></div>
  </div>

  <div class="row">
    <div class="col-md-3 col-sm-6"><?php echo se_ui_stat_card(_l('se_dash_outbox_failed'), $stats['outbox_failed'], admin_url('se_core/se_outbox?status=failed'), 'fa-exclamation-circle'); ?></div>
    <div class="col-md-3 col-sm-6"><?php echo se_ui_stat_card(_l('se_dash_google_submitted'), $stats['google_submitted'], admin_url('se_core/se_google'), 'fa-google'); ?></div>
    <div class="col-md-3 col-sm-6"><?php echo se_ui_stat_card(_l('se_credentials'), '', admin_url('se_core/se_credentials'), 'fa-key'); ?></div>
    <div class="col-md-3 col-sm-6"><?php echo se_ui_stat_card(_l('se_consent_settings'), '', admin_url('se_core/se_consent'), 'fa-check-square-o'); ?></div>
  </div>

<?php } ?>

</div></div>
<?php init_tail(); ?></body></html>

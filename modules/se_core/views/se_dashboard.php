<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">

<?php
$header_actions = [];
if (!empty($show_health_button)) {
    $header_actions[] = ['href' => admin_url('se_core/se_reports/health'), 'label' => _l('se_reports_health'), 'icon' => 'fa-heartbeat'];
}
se_ui_header(_l('se_group'), $header_actions, _l('se_dashboard'));
?>

<?php if (empty($has_brand)) { se_ui_no_brand_screen(); } else { ?>

  <?php if (!empty($warnings)) { ?>
    <div class="row"><div class="col-md-12">
      <?php foreach ($warnings as $w) {
          $cls = $w['level'] === 'error' ? 'alert-danger' : ($w['level'] === 'warning' ? 'alert-warning' : 'alert-info'); ?>
        <div class="alert <?php echo $cls; ?>"><i class="fa fa-exclamation-triangle"></i> <?php echo html_escape($w['text']); ?></div>
      <?php } ?>
    </div></div>
  <?php } ?>

  <?php /* Brand context: only rendered with several brands, where a bare number is ambiguous. */ ?>
  <?php if (!empty($brands)) { ?>
  <div class="row"><div class="col-md-12"><div class="mbot15">
    <span class="text-muted"><?php echo html_escape(_l('se_brand_context')); ?>:</span>
    <?php foreach ($brands as $b) { echo ' ' . se_ui_brand_badge((int) $b['id']); } ?>
  </div></div></div>
  <?php } ?>

  <?php
  /* One row: Bootstrap wraps the cards, so a role with fewer cards never
   * shows a half-empty second row. Every link is a screen its holder may open. */
  $cards = [
      [_l('leads'), $stats['leads'], admin_url('leads'), 'fa-filter'],
      [_l('se_patients'), $stats['patients'], admin_url('se_core/se_patients'), 'fa-user-md'],
      [_l('se_dash_appts_today'), $stats['appts_today'], admin_url('se_appointments/se_appointments/manage'), 'fa-calendar'],
      [_l('se_dash_appts_upcoming'), $stats['appts_upcoming'], admin_url('se_appointments/se_appointments/manage'), 'fa-calendar-plus'],
      [_l('se_appt_status_no_show'), $stats['appts_no_show'], admin_url('se_appointments/se_appointments/manage?status=no_show'), 'fa-user-times'],
      [_l('se_wa_unread'), $stats['wa_unread'], admin_url('se_whatsapp/se_whatsapp/inbox'), 'fab fa-whatsapp'],
  ];
  if (!empty($show_integrations)) {
      $cards[] = [_l('se_dash_outbox_pending'), $stats['outbox_pending'], admin_url('se_core/se_outbox?status=pending'), 'fa-paper-plane'];
      $cards[] = [_l('se_dash_outbox_failed'), $stats['outbox_failed'], admin_url('se_core/se_outbox?status=failed'), 'fa-exclamation-circle'];
  }
  if (!empty($show_health)) {
      $cards[] = [_l('se_dash_meta_pending'), $stats['meta_pending'], admin_url('se_core/se_meta'), 'fab fa-facebook-square'];
      $cards[] = [_l('se_dash_google_submitted'), $stats['google_submitted'], admin_url('se_core/se_google'), 'fab fa-google'];
      $cards[] = [_l('se_credentials'), '', admin_url('se_core/se_credentials'), 'fa-key'];
  }
  if (!empty($show_consent)) {
      $cards[] = [_l('se_consent_settings'), '', admin_url('se_core/se_consent'), 'fa-check-square'];
  }
  ?>
  <div class="row">
    <?php foreach ($cards as $c) { ?>
    <div class="col-md-3 col-sm-6"><?php echo se_ui_stat_card($c[0], $c[1], $c[2], $c[3]); ?></div>
    <?php } ?>
  </div>

<?php } ?>

</div></div>
<?php init_tail(); ?></body></html>

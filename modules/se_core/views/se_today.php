<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content se-page se-today">
<?php
/*
 * Bugün (CRM-M023, UIUX §D, DS §2.3): one attention queue with one button per
 * row, sorted by priority then age. No all-time counters — the "Hasta akışı"
 * pills count active journeys only. The right column is today's appointments,
 * unread threads and (for configurers) the Sistem card, which only lists what
 * needs a hand. Rendered in $build_ms; the budget is 600 ms (PERF).
 */
$rows   = $queue['rows'] ?? [];
$total  = (int) ($queue['total'] ?? count($rows));
$counts = $queue['counts'] ?? ['p1' => 0, 'p2' => 0, 'p3' => 0];
$active = array_sum((array) $stages);
?>
  <div class="se-page-head">
    <h1><?php echo _l('se_nav_today'); ?></h1>
    <span class="se-sub"><?php echo html_escape(_l('se_today_subtitle', [count($appts), $total])); ?></span>
    <div class="se-actions">
      <?php if (!empty($can_create_appt)) { echo se_ui_btn(_l('se_today_new_appt'), admin_url('se_appointments/create'), 'secondary', ['icon' => '＋']); } ?>
      <?php if (!empty($can_create_lead)) { echo se_ui_btn(_l('se_today_new_patient'), admin_url('leads'), 'primary', ['icon' => '＋']); } ?>
    </div>
  </div>

  <div class="se-grid se-grid-8-4">
    <div class="se-stack">
      <section class="se-card" aria-labelledby="se-today-queue-h">
        <h2 id="se-today-queue-h"><?php echo _l('se_today_queue'); ?>
          <?php if ($total > 0) { ?>
            <span class="se-count"><?php echo (int) $total; ?></span>
            <?php if ((int) $counts['p1'] > 0) { echo ' ', se_ui_ds_badge('danger', (int) $counts['p1'], true); } ?>
          <?php } ?>
        </h2>
        <?php if (!$rows) { ?>
          <p class="se-help"><?php echo _l('se_today_queue_empty'); ?> <?php echo _l('se_today_queue_empty_hint'); ?></p>
        <?php } else { ?>
          <ul class="se-attn">
            <?php foreach ($rows as $r) { echo se_ui_attention_row($r); } ?>
          </ul>
          <?php if ($total > count($rows)) { ?>
            <p class="se-help"><a href="<?php echo admin_url('se_core/se_hastalar?sort=attention'); ?>"><?php echo _l('se_today_see_all'); ?> (<?php echo (int) $total; ?>) →</a></p>
          <?php } ?>
        <?php } ?>
      </section>

      <section class="se-card" aria-labelledby="se-today-flow-h">
        <h2 id="se-today-flow-h"><?php echo _l('se_today_flow'); ?> <span class="se-count"><?php echo (int) $active; ?> <?php echo _l('se_today_flow_hint'); ?></span></h2>
        <div class="se-pipe">
          <?php foreach ((array) $stages as $stage => $n) { ?>
            <a href="<?php echo admin_url('se_core/se_hastalar?stage=' . rawurlencode($stage)); ?>" class="se-pipe-link"><span><?php echo html_escape(se_ui_stage_label($stage)); ?> <b><?php echo (int) $n; ?></b></span></a>
          <?php } ?>
        </div>
      </section>
    </div>

    <div class="se-stack">
      <section class="se-card" aria-labelledby="se-today-appts-h">
        <h2 id="se-today-appts-h"><?php echo _l('se_today_appts'); ?> <span class="se-count"><?php echo count($appts); ?></span></h2>
        <?php if (!$appts) { ?>
          <p class="se-help"><?php echo _l('se_today_appts_empty'); ?></p>
        <?php } else { ?>
          <ul class="se-mini">
            <?php foreach ($appts as $a) {
                $place = (string) ($a['location'] ?? '') !== '' ? $a['location'] : (($a['is_online'] ?? 0) ? _l('se_today_place_online') : _l('se_today_place_clinic'));
                $typeLabel = function_exists('se_appt_type_label') ? se_appt_type_label($a['type']) : $a['type']; ?>
              <li>
                <span class="t"><?php echo date('H:i', strtotime($a['start_at'])); ?></span>
                <a class="n" href="<?php echo admin_url('se_appointments/se_appointments/view/' . (int) $a['id']); ?>"><?php echo html_escape($a['patient'] !== '' ? se_ui_short_name($a['patient']) : ($a['title'] ?? '')); ?></a>
                <span class="m"><?php echo html_escape($typeLabel . ' · ' . $place); ?></span>
              </li>
            <?php } ?>
          </ul>
        <?php } ?>
        <p class="se-help"><a href="<?php echo admin_url('se_appointments'); ?>"><?php echo _l('se_today_open_calendar'); ?></a></p>
      </section>

      <section class="se-card" aria-labelledby="se-today-unread-h">
        <h2 id="se-today-unread-h"><?php echo _l('se_today_unread'); ?> <span class="se-count"><?php echo count($unread); ?></span></h2>
        <?php if (!$unread) { ?>
          <p class="se-help"><?php echo _l('se_today_unread_empty'); ?></p>
        <?php } else { ?>
          <ul class="se-mini">
            <?php foreach ($unread as $u) {
                $who = $u['patient'] !== '' ? se_ui_short_name($u['patient']) : se_ui_phone($u['wa_user_id'] ?? '', true, false); ?>
              <li>
                <a class="n" href="<?php echo admin_url('se_whatsapp/se_whatsapp/conversation/' . (int) $u['id']); ?>"><?php echo html_escape($who); ?></a>
                <?php echo se_ui_ds_badge('action', (int) $u['unread_count'], true); ?>
                <span class="m"><?php echo html_escape(se_ui_age($u['last_inbound_at'] ?? null)); ?></span>
              </li>
            <?php } ?>
          </ul>
        <?php } ?>
      </section>

      <?php if (!empty($system)) { ?>
      <section class="se-card" aria-labelledby="se-today-sys-h">
        <h2 id="se-today-sys-h"><?php echo _l('se_today_system'); ?></h2>
        <?php foreach ((array) $system['alerts'] as $al) {
            echo se_ui_alert($al['tone'], $al['text'], ['label' => _l('se_today_review'), 'href' => $al['href']]);
        } ?>
        <p class="se-help"><?php echo html_escape($system['summary']); ?>
          <?php if (!empty($show_health_button)) { ?> · <a href="<?php echo admin_url('se_core/se_reports/health'); ?>"><?php echo _l('se_reports_health'); ?> →</a><?php } ?>
        </p>
      </section>
      <?php } ?>
    </div>
  </div>
  <?php if (is_admin()) { ?><p class="se-help se-build" data-ms="<?php echo (int) $build_ms; ?>">⏱ <?php echo (int) $build_ms; ?> ms</p><?php } ?>
</div></div>
<?php init_tail(); ?>
</body>
</html>

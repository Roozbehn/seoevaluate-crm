<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content se-page se-hastalar">
<?php
/* Hastalar (CRM-M024, UIUX §E, DS §2.9/2.10): one list, one search box, chips,
 * the same next action as Bugün, one button per row. Phones are masked for
 * staff without the health capability. Columns marked hide-m collapse ≤767px
 * so the table never scrolls sideways on a phone. */
$rows   = $result['rows'];
$self   = admin_url('se_core/se_hastalar');
$link   = function (array $over = []) use ($f, $self) {
    $q = array_filter(array_merge(['q' => $f['q'], 'f' => $f['f'], 'sort' => $f['sort']], $over), function ($v) { return $v !== '' && $v !== null; });
    if (($q['f'] ?? 'active') === 'active') { unset($q['f']); }
    return $self . ($q ? '?' . http_build_query($q) : '');
};
$active = array_sum((array) $stages);
?>
  <div class="se-page-head">
    <h1><?php echo _l('se_nav_hastalar'); ?></h1>
    <span class="se-sub"><?php echo html_escape(sprintf(_l('se_hastalar_subtitle'), (int) $active)); ?></span>
    <div class="se-actions">
      <?php if (!empty($can_create_lead)) { echo se_ui_btn(_l('se_today_new_patient'), admin_url('leads'), 'primary', ['icon' => '＋']); } ?>
    </div>
  </div>

  <?php if (empty($has_brand)) { se_ui_no_brand_screen(); } else { ?>
  <form class="se-toolbar" method="get" action="<?php echo $self; ?>" role="search">
    <input type="hidden" name="f" value="<?php echo html_escape($f['f']); ?>">
    <label class="se-sr" for="se-hastalar-q"><?php echo _l('se_hastalar_search'); ?></label>
    <input class="se-input" id="se-hastalar-q" type="search" name="q" value="<?php echo html_escape($f['q']); ?>" placeholder="<?php echo _l('se_hastalar_search_ph'); ?>" style="flex:1 1 260px;max-width:420px" autocomplete="off" inputmode="search">
    <button type="submit" class="se-btn se-btn-secondary"><?php echo _l('se_hastalar_search'); ?></button>
    <?php if ($f['q'] !== '') { echo se_ui_btn(_l('se_hastalar_clear'), $link(['q' => '', 'page' => '']), 'ghost'); } ?>
  </form>
  <div class="se-chipgroup se-hastalar-chips" role="group" aria-label="<?php echo _l('se_hastalar_filter'); ?>">
    <?php foreach (se_hastalar_chips() as $chip) {
        $n = in_array($chip, se_ui_stages_list(), true) ? (int) ($stages[$chip] ?? 0) : null;
        if ($n === 0 && $f['f'] !== $chip) { continue; } ?>
      <a class="se-chip<?php echo $f['f'] === $chip ? ' on' : ''; ?>" href="<?php echo $link(['f' => $chip, 'page' => '']); ?>"<?php echo $f['f'] === $chip ? ' aria-current="true"' : ''; ?>><?php echo html_escape(se_hastalar_chip_label($chip)); ?><?php if ($n !== null) { ?> <b><?php echo $n; ?></b><?php } ?></a>
    <?php } ?>
    <span class="se-help" style="margin-inline-start:auto"><?php echo _l('se_hastalar_sort'); ?>:
      <?php foreach (['attention', 'recent', 'name'] as $s) { ?><a href="<?php echo $link(['sort' => $s, 'page' => '']); ?>"<?php echo $f['sort'] === $s ? ' aria-current="true" style="font-weight:600"' : ''; ?>><?php echo _l('se_hastalar_sort_' . $s); ?></a> <?php } ?>
    </span>
  </div>

  <?php if (!$rows) { ?>
    <?php echo se_ui_empty_state(_l('se_hastalar_empty'), $f['q'] !== '' ? _l('se_hastalar_empty_search') : _l('se_hastalar_empty_hint'), $f['q'] !== '' ? ['label' => _l('se_hastalar_clear'), 'href' => $link(['q' => '', 'page' => ''])] : null); ?>
  <?php } else { ?>
  <div class="se-card" style="padding:0">
    <div class="se-tablewrap"><table class="se-table">
      <thead><tr>
        <th><?php echo _l('se_hastalar_col_patient'); ?></th>
        <th><?php echo _l('se_hastalar_col_stage'); ?></th>
        <th><?php echo _l('se_hastalar_col_next'); ?></th>
        <th class="hide-m"><?php echo _l('se_hastalar_col_touch'); ?></th>
        <th class="hide-m"><?php echo _l('se_hastalar_col_appt'); ?></th>
        <th class="hide-m"><?php echo _l('se_hastalar_col_owner'); ?></th>
        <th class="hide-m"><?php echo _l('se_hastalar_col_source'); ?></th>
        <th><span class="se-sr"><?php echo _l('se_hastalar_col_action'); ?></span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r) {
          $srcKey = 'se_journey_source_' . $r['source']; $src = _l($srcKey); if ($src === $srcKey) { $src = $r['source'] !== '' ? $r['source'] : '—'; } ?>
        <tr>
          <td><a class="se-name" href="<?php echo html_escape($r['url']); ?>"><?php echo html_escape($r['who']); ?><?php if ($r['urgent']) { echo ' ', se_ui_ds_badge('danger', _l('se_journey_urgent'), true); } ?></a>
              <span class="se-meta"><?php echo html_escape($r['phone']); ?><?php if ($r['name'] === '') { echo ' · ', _l('se_hastalar_no_name'); } ?></span></td>
          <td><?php echo se_ui_ds_badge($r['tone'], $r['state_label']); ?><?php if ($r['automation_state'] !== 'active') { echo ' ', se_ui_automation_badge($r['automation_state']); } ?></td>
          <td><?php echo html_escape($r['next'] !== '' ? $r['next'] : '—'); ?><?php if ($r['next_meta'] !== '') { ?><span class="se-meta"><?php echo html_escape($r['next_meta']); ?></span><?php } ?></td>
          <td class="hide-m num"><?php echo html_escape(se_ui_age($r['last_updated'])); ?><?php if ($r['unread'] > 0) { ?> · 💬 <?php echo (int) $r['unread']; ?><?php } ?></td>
          <td class="hide-m num"><?php echo $r['next_appointment'] ? html_escape((function_exists('se_appt_type_label') ? se_appt_type_label($r['next_appointment']['type']) . ' ' : '') . se_ui_when($r['next_appointment']['start_at'])) : '—'; ?></td>
          <td class="hide-m"><?php echo html_escape($r['assigned'] !== '' ? se_ui_short_name($r['assigned']) : '—'); ?></td>
          <td class="hide-m"><?php echo html_escape($src); ?></td>
          <td><?php echo $r['action_label'] !== '' ? se_ui_btn($r['action_label'], $r['action_url'], $r['priority'] <= 2 ? 'primary' : 'secondary', ['sm' => true, 'aria' => $r['who'] . ' — ' . $r['action_label']]) : se_ui_btn(_l('se_hastalar_open'), $r['url'], 'secondary', ['sm' => true, 'aria' => $r['who'] . ' — ' . _l('se_hastalar_open')]); ?></td>
        </tr>
      <?php } ?>
      </tbody></table></div>
    <div class="se-pager">
      <span><?php echo html_escape(sprintf(_l('se_hastalar_range'), ($result['page'] - 1) * SE_HASTALAR_PAGE + 1, min($result['total'], $result['page'] * SE_HASTALAR_PAGE), $result['total'])); ?><?php if ($result['capped']) { echo ' · ', html_escape(sprintf(_l('se_hastalar_capped'), SE_HASTALAR_SCAN)); } ?></span>
      <span style="margin-inline-start:auto"></span>
      <?php if ($result['page'] > 1) { echo se_ui_btn(_l('se_hastalar_prev'), $link(['page' => $result['page'] - 1]), 'secondary', ['sm' => true]); } ?>
      <?php if ($result['page'] < $result['pages']) { echo se_ui_btn(_l('se_hastalar_next'), $link(['page' => $result['page'] + 1]), 'secondary', ['sm' => true]); } ?>
    </div>
  </div>
  <?php } ?>
  <?php } ?>
  <?php if (is_admin()) { ?><p class="se-help se-build" data-ms="<?php echo (int) $build_ms; ?>">⏱ <?php echo (int) $build_ms; ?> ms</p><?php } ?>
</div></div>
<?php init_tail(); ?>
</body>
</html>

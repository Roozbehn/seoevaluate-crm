<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head();
/*
 * Mesajlar (CRM-M032..M037 / UIUX §G / DS §2.14, 2.16, 2.18).
 * Desktop ≥1024: list | thread | context. Tablet 768–1023: list | thread,
 * context behind ⓘ (sheet). Phone ≤767: list OR thread (thread-first when a
 * thread is selected; the tab bar hides in a thread), context as a sheet,
 * a one-line context strip above the messages.
 * The composer offered is decided by the SERVER (se_wa_compose_policy).
 */
$c      = $conversation;
$mode   = $c ? 'se-wa-thread' : 'se-wa-list';
$self   = admin_url('se_whatsapp/se_whatsapp/inbox');
$link   = function (array $over = []) use ($f, $self, $selected) {
    $q = array_filter(array_merge(['q' => $f['q'], 'f' => $f['f'], 'c' => $selected ?: ''], $over), function ($v) { return $v !== '' && $v !== null && $v !== 0; });
    if (($q['f'] ?? 'all') === 'all') { unset($q['f']); }
    return $self . ($q ? '?' . http_build_query($q) : '');
};
$contact_label = $c ? ($evidence_redacted ? se_wa_redacted_contact($c->wa_user_id) : ($row['name'] ?? se_ui_phone($c->wa_user_id, true, false))) : '';
?>
<div id="wrapper"><div class="content se-page se-messages" style="padding-bottom:0">

<?php if (empty($has_brand)) { se_ui_no_brand_screen(); } else { ?>

<?php if ($blocked !== '') { echo se_ui_alert('info', _l('se_wa_sending_gated') . ' — ' . _l('se_wa_blocked_' . $blocked), se_staff_can_configure_brands() ? ['label' => _l('se_wa_readiness'), 'href' => admin_url('se_whatsapp/se_whatsapp/readiness')] : null); } ?>

<div class="se-wa <?php echo $mode; ?>" id="se-wa">

  <?php /* ---------------- column 1: conversations ---------------- */ ?>
  <section class="se-convlist" aria-label="<?php echo _l('se_wa_conversations'); ?>">
    <form class="se-toolbar" method="get" action="<?php echo $self; ?>" role="search">
      <?php if ($f['f'] !== 'all') { ?><input type="hidden" name="f" value="<?php echo html_escape($f['f']); ?>"><?php } ?>
      <label class="se-sr" for="se-wa-q"><?php echo _l('se_hastalar_search'); ?></label>
      <input class="se-input" id="se-wa-q" type="search" name="q" value="<?php echo html_escape($f['q']); ?>" placeholder="<?php echo _l('se_wa_search_ph'); ?>" style="flex:1 1 100%;height:36px" autocomplete="off" inputmode="search">
      <div class="se-chipgroup" role="group" aria-label="<?php echo _l('se_hastalar_filter'); ?>" style="flex-basis:100%">
        <?php foreach (se_wa_inbox_chips() as $chip) { ?>
          <a class="se-chip<?php echo $f['f'] === $chip ? ' on' : ''; ?>" href="<?php echo $link(['f' => $chip, 'c' => '']); ?>"<?php echo $f['f'] === $chip ? ' aria-current="true"' : ''; ?>><?php echo _l('se_wa_chip_' . $chip); ?><?php if ($chip === 'unread' && $list['counts']['unread'] > 0) { ?> <b><?php echo (int) $list['counts']['unread']; ?></b><?php } ?></a>
        <?php } ?>
      </div>
      <?php if ($f['q'] !== '') { echo se_ui_btn(_l('se_hastalar_clear'), $link(['q' => '', 'c' => '']), 'ghost', ['sm' => true]); } ?>
    </form>
    <div class="se-convscroll">
      <?php if (!$list['rows']) { ?>
        <p class="se-help" style="padding:16px"><?php echo $f['q'] !== '' ? _l('se_hastalar_empty_search') : _l('se_wa_no_conversations'); ?></p>
      <?php } else { foreach ($list['rows'] as $r) { ?>
        <a class="se-conv<?php echo $selected === $r['id'] ? ' active' : ''; ?>" href="<?php echo html_escape($link(['c' => $r['id']])); ?>"<?php echo $selected === $r['id'] ? ' aria-current="true"' : ''; ?>>
          <div class="se-avatar" style="width:40px;height:40px;font-size:13px" aria-hidden="true"><?php echo html_escape($r['initials'] ?: '?'); ?></div>
          <div>
            <div class="n"><?php echo html_escape($evidence_redacted ? se_wa_redacted_contact($r['wa_user_id']) : $r['name']); ?> <span class="sb"<?php echo $r['urgent'] ? ' style="color:var(--se-danger)"' : ''; ?>><?php echo html_escape($r['state_label']); ?></span></div>
            <div class="p"><?php echo html_escape($r['preview'] !== '' ? $r['preview'] : '—'); ?></div>
          </div>
          <div><div class="t"><?php echo html_escape($r['last_at'] !== '' ? se_ui_age($r['last_at']) : ''); ?></div><?php if ($r['unread'] > 0) { ?><span class="u" aria-label="<?php echo (int) $r['unread']; ?> <?php echo _l('se_wa_unread'); ?>"><?php echo (int) $r['unread']; ?></span><?php } ?></div>
        </a>
      <?php } } ?>
      <?php if ($list['has_more']) { ?><p style="padding:12px;text-align:center"><?php echo se_ui_btn(_l('se_wa_more_threads'), $link(['before' => $list['next_before'], 'c' => '']), 'secondary', ['sm' => true]); ?></p><?php } ?>
    </div>
  </section>

  <?php /* ---------------- column 2: thread ---------------- */ ?>
  <section class="se-threadcol" aria-label="<?php echo $c ? html_escape(_l('se_wa_conversation') . ': ' . $contact_label) : _l('se_wa_conversation'); ?>">
    <?php if (!$c) { ?>
      <div class="se-empty" style="margin:auto;text-align:center;padding:40px 16px"><h2><?php echo _l('se_wa_pick_thread'); ?></h2><p class="se-help"><?php echo _l('se_wa_pick_thread_hint'); ?></p></div>
    <?php } else { ?>
      <div class="se-thread-head">
        <a class="se-iconbtn visible-xs" href="<?php echo html_escape($back_url); ?>" aria-label="<?php echo _l('se_back'); ?>">←</a>
        <div class="se-avatar" style="width:36px;height:36px;font-size:12px" aria-hidden="true"><?php echo html_escape($row['initials'] ?? '?'); ?></div>
        <div style="min-width:0"><div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo html_escape($contact_label); ?></div>
          <div class="se-help"><span class="hidden-xs"><?php echo html_escape($evidence_redacted ? '' : se_ui_phone($c->wa_user_id, !se_journey_can('view_health'), false)); ?> · </span><?php echo $policy['window_open'] ? se_ui_ds_badge('positive', _l('se_wa_window_open'), true) : se_ui_ds_badge('inactive', _l('se_wa_window_closed'), true); ?></div></div>
        <div style="margin-inline-start:auto;display:flex;gap:6px;align-items:center">
          <?php if (!empty($journey)) { echo se_ui_btn(_l('se_journey_patient'), admin_url('se_journey/se_journey/view/' . (int) $journey->id), 'secondary', ['sm' => true, 'class' => 'hidden-xs']); } ?>
          <button type="button" class="se-iconbtn se-ctx-toggle" data-se-sheet="#se-ctx-sheet" aria-controls="se-ctx-sheet" aria-expanded="false" aria-label="<?php echo _l('se_wa_ctx_info'); ?>">ⓘ</button>
        </div>
      </div>
      <?php if (!empty($row) && $row['state'] !== '') { ?>
        <div class="se-strip"><?php echo se_ui_ds_badge($row['tone'], $row['state_label']); ?>
          <?php if (!empty($journey)) { $na = se_journey_next_action_for($journey); if (!empty($na['sentence'])) { ?><span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo html_escape($na['sentence']); ?></span><?php } if (!empty($na['action_label']) && !empty($na['url']) && ($na['owner'] ?? '') === 'staff') { echo se_ui_btn($na['action_label'], $na['url'], 'primary', ['sm' => true]); } } ?>
        </div>
      <?php } ?>
      <?php if (!empty($older_before)) { ?><p style="text-align:center;margin:8px 0 0"><?php echo se_ui_btn(_l('se_wa_load_older'), $link(['before' => $older_before]), 'ghost', ['sm' => true]); ?></p><?php } ?>
      <?php se_ui_chat_thread($messages, $media ?? [], ['channel' => 'wa', 'redacted' => $evidence_redacted, 'empty' => _l('se_wa_no_messages')]); ?>
      <?php $back = $link([]);
      if (!$policy['allowed']) {
          se_ui_chat_composer(['mode' => 'gated', 'title' => _l('se_wa_sending_gated'), 'reason' => _l('se_wa_blocked_' . $policy['reason'])]);
      } elseif ($policy['mode'] === 'freeform') {
          se_ui_chat_composer([
              'mode' => 'freeform', 'action' => admin_url('se_whatsapp/se_whatsapp/reply/' . (int) $c->id), 'back' => $back,
              'window_label' => _l('se_wa_window_open'), 'window_text' => _l('se_wa_window_until') . ' ' . (string) $policy['expires_at'],
              'maxlength' => 4096, 'voice_ogg_ok' => true, 'placeholder' => _l('se_chat_placeholder'), 'label_send' => _l('se_chat_send'),
              'templates' => $templates, 'label_send_template' => _l('se_chat_send_template'),
              'journey_active' => !empty($journey) && (string) $journey->automation_state === 'active',
          ]);
      } else {
          $can_sync = function_exists('se_staff_can_configure_brands') && se_staff_can_configure_brands() && function_exists('se_wa_waba_for_brand') && se_wa_waba_for_brand((int) $c->brand_id) !== '';
          se_ui_chat_composer([
              'mode' => 'template', 'action' => admin_url('se_whatsapp/se_whatsapp/reply/' . (int) $c->id), 'back' => $back,
              'window_label' => _l('se_wa_window_closed'), 'window_text' => _l('se_wa_reply_template_required'), 'templates' => $templates, 'label_send' => _l('se_chat_send_template'),
              'sync' => $can_sync ? ['action' => admin_url('se_whatsapp/se_whatsapp/sync_templates'), 'brand' => (int) $c->brand_id, 'back' => $back] : null,
          ]);
      } ?>
    <?php } ?>
  </section>

  <?php /* ---------------- column 3: context (desktop) / sheet (tablet+phone) ---------------- */ ?>
  <aside class="se-ctx se-sheet" id="se-ctx-sheet" aria-label="<?php echo _l('se_wa_ctx_info'); ?>" aria-hidden="true">
    <?php if ($c) { ?>
      <button type="button" class="se-iconbtn se-sheet-close" aria-label="<?php echo _l('close'); ?>" style="align-self:flex-end">✕</button>
      <?php echo $ctx_html !== '' ? $ctx_html : '<p class="se-help">' . html_escape(_l('se_journey_panel_disabled')) . '</p>'; ?>
      <?php if (staff_can('edit', 'se_whatsapp')) { ?>
        <?php echo form_open(admin_url('se_whatsapp/se_whatsapp/assign/' . (int) $c->id), ['class' => 'se-field']); ?>
          <label for="se-wa-assign"><?php echo _l('se_wa_assigned_staff'); ?></label>
          <div style="display:flex;gap:8px"><select id="se-wa-assign" class="se-input" name="staff_id">
            <option value="0"><?php echo _l('se_wa_unassigned'); ?></option>
            <?php foreach ($staff as $s) { ?><option value="<?php echo (int) $s['staffid']; ?>"<?php echo (int) $c->assigned_staff === (int) $s['staffid'] ? ' selected' : ''; ?>><?php echo html_escape($evidence_redacted ? ('#' . (int) $s['staffid']) : trim($s['firstname'] . ' ' . $s['lastname'])); ?></option><?php } ?>
          </select><button type="submit" class="se-btn se-btn-secondary"><?php echo _l('se_journey_assign'); ?></button></div>
        <?php echo form_close(); ?>
      <?php } ?>
      <?php if (isset($tracker) && function_exists('se_ui_outbound_tracker') && is_array($dispatch_eta)) { echo '<div>'; se_ui_outbound_tracker($tracker, $dispatch_eta); echo '</div>'; } ?>
    <?php } else { ?>
      <div><h3><?php echo _l('se_wa_queue_health'); ?></h3><?php se_ui_counters($out_health); ?></div>
    <?php } ?>
  </aside>
</div>
<?php if (is_admin()) { ?><p class="se-help se-build" data-ms="<?php echo (int) $build_ms; ?>">⏱ <?php echo (int) $build_ms; ?> ms</p><?php } ?>
<?php } ?>

</div></div>
<?php init_tail(); ?></body></html>

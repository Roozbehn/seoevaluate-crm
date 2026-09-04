<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head();
/*
 * Mesajlar · Instagram — the same page model as the WhatsApp inbox
 * (UX-W09 / CRM-M038): desktop ≥1024 list | thread | context; tablet
 * list | thread with the context behind ⓘ; phone list OR thread
 * (thread-first, tab bar hidden in a thread), context as a sheet, a
 * one-line strip above the messages. Same CSS (.se-wa), same chat UI,
 * same next-action engine (through the thread's lead).
 */
$c      = $conversation;
$mode   = $c ? 'se-wa-thread' : 'se-wa-list';
$self   = admin_url('se_instagram/se_instagram/inbox');
$link   = function (array $over = []) use ($f, $self, $selected) {
    $q = array_filter(array_merge(['q' => $f['q'], 'f' => $f['f'], 'c' => $selected ?: ''], $over), function ($v) { return $v !== '' && $v !== null && $v !== 0; });
    if (($q['f'] ?? 'all') === 'all') { unset($q['f']); }
    return $self . ($q ? '?' . http_build_query($q) : '');
};
$contact_label = $c ? ($evidence_redacted || empty($row['name']) ? se_ig_redacted_contact($c->igsid) : $row['name']) : '';
?>
<div id="wrapper"><div class="content se-page se-messages" style="padding-bottom:0">

<?php if (empty($has_brand)) { se_ui_no_brand_screen(); } else { ?>

<?php if ($blocked !== '') { echo se_ui_alert('info', _l('se_ig_sending_gated') . ' — ' . _l('se_ig_blocked_' . $blocked)); } ?>

<div class="se-wa <?php echo $mode; ?>" id="se-wa">

  <?php /* ---------------- column 1: conversations ---------------- */ ?>
  <section class="se-convlist" aria-label="<?php echo _l('se_ig_conversations'); ?>">
    <form class="se-toolbar" method="get" action="<?php echo $self; ?>" role="search">
      <?php if ($f['f'] !== 'all') { ?><input type="hidden" name="f" value="<?php echo html_escape($f['f']); ?>"><?php } ?>
      <?php if (function_exists('se_messages_channel_switch')) { echo se_messages_channel_switch('instagram'); } ?>
      <label class="se-sr" for="se-ig-q"><?php echo _l('se_hastalar_search'); ?></label>
      <input class="se-input" id="se-ig-q" type="search" name="q" value="<?php echo html_escape($f['q']); ?>" placeholder="<?php echo _l('se_ig_search_ph'); ?>" style="flex:1 1 100%;height:36px" autocomplete="off" inputmode="search">
      <div class="se-chipgroup" role="group" aria-label="<?php echo _l('se_hastalar_filter'); ?>" style="flex-basis:100%">
        <?php foreach (se_ig_inbox_chips() as $chip) { ?>
          <a class="se-chip<?php echo $f['f'] === $chip ? ' on' : ''; ?>" href="<?php echo $link(['f' => $chip, 'c' => '']); ?>"<?php echo $f['f'] === $chip ? ' aria-current="true"' : ''; ?>><?php echo _l('se_ig_chip_' . $chip); ?><?php if ($chip === 'unread' && $list['counts']['unread'] > 0) { ?> <b><?php echo (int) $list['counts']['unread']; ?></b><?php } ?></a>
        <?php } ?>
      </div>
      <?php if ($f['q'] !== '') { echo se_ui_btn(_l('se_hastalar_clear'), $link(['q' => '', 'c' => '']), 'ghost', ['sm' => true]); } ?>
    </form>
    <div class="se-convscroll">
      <?php if (!$list['rows']) { ?>
        <p class="se-help" style="padding:16px"><?php echo $f['q'] !== '' ? _l('se_hastalar_empty_search') : _l('se_ig_no_conversations'); ?></p>
      <?php } else { foreach ($list['rows'] as $r) { ?>
        <a class="se-conv<?php echo $selected === $r['id'] ? ' active' : ''; ?>" href="<?php echo html_escape($link(['c' => $r['id']])); ?>"<?php echo $selected === $r['id'] ? ' aria-current="true"' : ''; ?>>
          <div class="se-avatar" style="width:40px;height:40px;font-size:13px" aria-hidden="true"><?php echo html_escape($r['initials'] ?: '?'); ?></div>
          <div>
            <div class="n"><?php echo html_escape($evidence_redacted ? se_ig_redacted_contact($r['igsid']) : $r['name']); ?> <span class="sb"<?php echo $r['urgent'] ? ' style="color:var(--se-danger)"' : ''; ?>><?php echo html_escape($r['state_label']); ?></span><?php if ($r['ad'] !== '') { ?> <span class="sb" title="<?php echo _l('se_ig_ad_referral'); ?>">📣</span><?php } ?></div>
            <div class="p"><?php echo html_escape($r['preview'] !== '' ? $r['preview'] : '—'); ?></div>
          </div>
          <div><div class="t"><?php echo html_escape($r['last_at'] !== '' ? se_ui_age($r['last_at']) : ''); ?></div><?php if ($r['unread'] > 0) { ?><span class="u" aria-label="<?php echo (int) $r['unread']; ?> <?php echo _l('se_ig_unread'); ?>"><?php echo (int) $r['unread']; ?></span><?php } ?></div>
        </a>
      <?php } } ?>
      <?php if ($list['has_more']) { ?><p style="padding:12px;text-align:center"><?php echo se_ui_btn(_l('se_ig_more_threads'), $link(['before' => $list['next_before'], 'c' => '']), 'secondary', ['sm' => true]); ?></p><?php } ?>
    </div>
  </section>

  <?php /* ---------------- column 2: thread ---------------- */ ?>
  <section class="se-threadcol" aria-label="<?php echo $c ? html_escape(_l('se_ig_conversation') . ': ' . $contact_label) : _l('se_ig_conversation'); ?>">
    <?php if (!$c) { ?>
      <div class="se-empty" style="margin:auto;text-align:center;padding:40px 16px"><h2><?php echo _l('se_ig_pick_thread'); ?></h2><p class="se-help"><?php echo _l('se_ig_pick_thread_hint'); ?></p></div>
    <?php } else { ?>
      <div class="se-thread-head">
        <a class="se-iconbtn visible-xs" href="<?php echo html_escape($back_url); ?>" aria-label="<?php echo _l('se_back'); ?>">←</a>
        <div class="se-avatar" style="width:36px;height:36px;font-size:12px" aria-hidden="true"><?php echo html_escape($row['initials'] ?? '?'); ?></div>
        <div style="min-width:0"><div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php echo html_escape($contact_label); ?></div>
          <div class="se-help"><span class="hidden-xs">Instagram · </span><?php echo $policy['window_open'] ? se_ui_ds_badge('positive', _l('se_ig_window_open'), true) : se_ui_ds_badge('inactive', _l('se_ig_window_closed'), true); ?></div></div>
        <div style="margin-inline-start:auto;display:flex;gap:6px;align-items:center">
          <?php if (!empty($journey)) { echo se_ui_btn(_l('se_journey_patient'), admin_url('se_journey/se_journey/view/' . (int) $journey->id), 'secondary', ['sm' => true, 'class' => 'hidden-xs']); } ?>
          <button type="button" class="se-iconbtn se-ctx-toggle" data-se-sheet="#se-ctx-sheet" aria-controls="se-ctx-sheet" aria-expanded="false" aria-label="<?php echo _l('se_ig_ctx_info'); ?>">ⓘ</button>
        </div>
      </div>
      <?php if (!empty($row) && $row['state'] !== '') { ?>
        <div class="se-strip"><?php echo se_ui_ds_badge($row['tone'], $row['state_label']); ?>
          <?php if (!empty($journey)) { $na = se_journey_next_action_for($journey); if (!empty($na['sentence'])) { ?><span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo html_escape($na['sentence']); ?></span><?php } if (!empty($na['action_label']) && !empty($na['url']) && ($na['owner'] ?? '') === 'staff') { echo se_ui_btn($na['action_label'], $na['url'], 'primary', ['sm' => true]); } } ?>
        </div>
      <?php } ?>
      <?php if (!empty($older_before)) { ?><p style="text-align:center;margin:8px 0 0"><?php echo se_ui_btn(_l('se_ig_load_older'), $link(['before' => $older_before]), 'ghost', ['sm' => true]); ?></p><?php } ?>
      <?php se_ui_chat_thread($messages, $media ?? [], ['channel' => 'ig', 'redacted' => $evidence_redacted, 'empty' => _l('se_ig_no_messages')]); ?>
      <?php $back = $link([]);
      /* Instagram has no template mechanism: outside the 24 h window the server gates sending and the composer says so. */
      if (!$policy['allowed']) {
          se_ui_chat_composer(['mode' => 'gated', 'title' => _l('se_ig_sending_gated'), 'reason' => _l('se_ig_blocked_' . $policy['reason'])]);
      } else {
          se_ui_chat_composer([
              'mode' => 'freeform', 'action' => admin_url('se_instagram/se_instagram/reply/' . (int) $c->id), 'back' => $back,
              'window_label' => _l('se_ig_window_open'), 'window_text' => _l('se_ig_window_until') . ' ' . (string) $policy['expires_at'],
              'maxlength' => 1000, 'accept' => 'image/*,audio/*,video/*', 'max_upload_mb' => 8, 'attach_hint_key' => 'se_chat_attach_hint_ig',
              'placeholder' => _l('se_chat_placeholder'), 'label_send' => _l('se_chat_send'),
          ]);
      } ?>
    <?php } ?>
  </section>

  <?php /* ---------------- column 3: context (desktop) / sheet (tablet+phone) ---------------- */ ?>
  <aside class="se-ctx se-sheet" id="se-ctx-sheet" aria-label="<?php echo _l('se_ig_ctx_info'); ?>" aria-hidden="true">
    <?php if ($c) { ?>
      <button type="button" class="se-iconbtn se-sheet-close" aria-label="<?php echo _l('close'); ?>" style="align-self:flex-end">✕</button>
      <?php echo $ctx_html !== '' ? $ctx_html : '<p class="se-help">' . html_escape(_l('se_journey_panel_disabled')) . '</p>'; ?>
      <div>
        <h3 style="margin-bottom:8px"><?php echo html_escape(_l('se_ig_conversation')); ?></h3>
        <?php se_ui_kv([
            _l('se_brand')           => se_ui_brand_badge((int) $c->brand_id),
            _l('se_ig_contact')      => html_escape(se_ig_redacted_contact($c->igsid)),
            _l('se_ig_ad_referral')  => !empty($c->referral_ad_id)
                ? se_ui_ds_badge('info', 'ad ' . $c->referral_ad_id, true) . (!empty($c->referral_source) ? ' <span class="se-help">' . html_escape($c->referral_source) . '</span>' : '')
                : '<span class="se-help">—</span>',
            _l('se_appt_lead')       => !empty($c->lead_id) && is_admin()
                ? '<a href="' . admin_url('leads/index/' . (int) $c->lead_id) . '">#' . (int) $c->lead_id . '</a>'
                : (!empty($c->lead_id) ? '#' . (int) $c->lead_id : '<span class="se-help">—</span>'),
            _l('se_ig_last_inbound') => html_escape($c->last_inbound_at ? se_ui_age($c->last_inbound_at) : '—'),
        ], true); ?>
      </div>
      <?php if (staff_can('edit', 'se_instagram')) { ?>
        <?php echo form_open(admin_url('se_instagram/se_instagram/assign/' . (int) $c->id), ['class' => 'se-field']); ?>
          <label for="se-ig-assign"><?php echo _l('se_ig_assigned_staff'); ?></label>
          <div style="display:flex;gap:8px"><select id="se-ig-assign" class="se-input" name="staff_id">
            <option value="0"><?php echo _l('se_ig_unassigned'); ?></option>
            <?php foreach ($staff as $s) { ?><option value="<?php echo (int) $s['staffid']; ?>"<?php echo (int) $c->assigned_staff === (int) $s['staffid'] ? ' selected' : ''; ?>><?php echo html_escape($evidence_redacted ? ('#' . (int) $s['staffid']) : trim($s['firstname'] . ' ' . $s['lastname'])); ?></option><?php } ?>
          </select><button type="submit" class="se-btn se-btn-secondary"><?php echo _l('se_journey_assign'); ?></button></div>
        <?php echo form_close(); ?>
      <?php } ?>
      <?php if (isset($tracker) && function_exists('se_ui_outbound_tracker') && is_array($dispatch_eta)) { echo '<div>'; se_ui_outbound_tracker($tracker, $dispatch_eta); echo '</div>'; } ?>
    <?php } else { ?>
      <div><h3><?php echo _l('se_ig_queue_health'); ?></h3><?php se_ui_counters($out_health); ?></div>
    <?php } ?>
  </aside>
</div>
<?php if (is_admin()) { ?><p class="se-help se-build" data-ms="<?php echo (int) $build_ms; ?>">⏱ <?php echo (int) $build_ms; ?> ms</p><?php } ?>
<?php } ?>

</div></div>
<?php init_tail(); ?></body></html>

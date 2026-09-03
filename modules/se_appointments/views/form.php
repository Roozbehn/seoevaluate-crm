<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head();
/*
 * Appointment form v2 (CRM-M039 / UX-A03 / DS §2.11 / UX-COPY §3.4, §5):
 * type chips → default duration; date + time + duration (end computed);
 * patient selector; exact conflict message with the next free slot on the
 * time field; the same-day procedure shortcut arrives prefilled (?from=).
 */
$a  = $appointment;
$pf = $prefill ?? [];
$v  = function ($k, $default = '') use ($a, $pf) {
    if (array_key_exists($k, $pf)) { return (string) $pf[$k]; }
    return $a && isset($a->$k) ? (string) $a->$k : (string) $default;
};
$type     = se_appt_type_key($v('appointment_type', 'consultation'));
$startRaw = $v('start_at');
$endRaw   = $v('end_at');
$date     = $startRaw !== '' && strtotime($startRaw) ? date('Y-m-d', strtotime($startRaw)) : '';
$time     = $startRaw !== '' && strtotime($startRaw) ? date('H:i', strtotime($startRaw)) : '';
$duration = (int) ($pf['duration'] ?? 0);
if ($duration <= 0 && $startRaw !== '' && $endRaw !== '' && strtotime($endRaw) > strtotime($startRaw)) { $duration = (int) round((strtotime($endRaw) - strtotime($startRaw)) / 60); }
if ($duration <= 0) { $duration = se_appt_type_minutes($type); }
$durations = [15, 20, 30, 45, 60, 90, 120, 180, 240, 300];
if (!in_array($duration, $durations, true)) { $durations[] = $duration; sort($durations); }
$relType = $v('rel_type', 'lead'); $relId = (int) $v('rel_id', 0);
$back = $this->input->get('back') ?: ($pf['back'] ?? '');
$fmtDur = function ($m) { return $m >= 60 && $m % 60 === 0 ? ($m / 60) . ' ' . _l('se_appt_hours') : ($m >= 60 ? floor($m / 60) . ' ' . _l('se_appt_hours') . ' ' . ($m % 60) . ' ' . _l('se_appt_minutes') : $m . ' ' . _l('se_appt_minutes')); };
$patientName = '';
foreach ($leads as $l) { if ($relType === 'lead' && (int) $l['id'] === $relId) { $patientName = $l['name']; } }
?>
<div id="wrapper"><div class="content se-page se-appt-form">
  <div class="se-page-head">
    <h1><?php echo $a ? _l('edit') : _l('se_appt_new'); ?></h1>
    <?php if ($patientName !== '') { ?><span class="se-sub"><?php echo html_escape(se_ui_short_name($patientName)); ?><?php if (!empty($pf['from_id'])) { ?> · <?php echo _l('se_appt_prefilled_from'); ?><?php } ?></span><?php } ?>
    <div class="se-actions"><?php echo se_ui_btn(_l('se_appt_calendar'), admin_url('se_appointments'), 'ghost'); ?></div>
  </div>

  <?php if (!empty($error)) { echo se_ui_alert($error_kind === 'conflict' ? 'warning' : 'danger', $error); } ?>

  <div class="se-grid se-grid-8-4">
    <?php echo form_open(admin_url('se_appointments/se_appointments/save/' . ($a ? (int) $a->id : '')), ['class' => 'se-card se-stack', 'id' => 'se-appt-form', 'style' => 'max-width:760px']); ?>
      <?php if (!empty($pf['journey_id'])) { ?><input type="hidden" name="journey_id" value="<?php echo (int) $pf['journey_id']; ?>"><?php } ?>
      <?php if ($back !== '') { ?><input type="hidden" name="back" value="<?php echo html_escape($back); ?>"><?php } ?>
      <?php if (!$a) { ?>
        <?php if (count($brands) === 1) { ?><input type="hidden" name="brand_id" value="<?php echo (int) $brands[0]['id']; ?>"><?php } else { ?>
          <div class="se-field"><label for="brand_id"><?php echo _l('se_appt_brand'); ?></label>
            <select class="se-input" id="brand_id" name="brand_id" required><option value=""></option>
              <?php foreach ($brands as $b) { ?><option value="<?php echo (int) $b['id']; ?>"<?php echo (int) $v('brand_id') === (int) $b['id'] ? ' selected' : ''; ?>><?php echo html_escape($b['name']); ?></option><?php } ?>
            </select></div>
        <?php } ?>
      <?php } ?>

      <fieldset class="se-field" style="border:0;padding:0;margin:0">
        <legend style="font-size:var(--se-fs-sm);font-weight:600;color:var(--se-text-2);border:0;margin:0 0 6px"><?php echo _l('se_appt_type'); ?></legend>
        <div class="se-chipgroup" role="radiogroup">
          <?php foreach (se_appt_types() as $k => $t) { ?>
            <label class="se-chip<?php echo $type === $k ? ' on' : ''; ?>"><input type="radio" name="appointment_type" value="<?php echo $k; ?>" data-minutes="<?php echo (int) $t['minutes']; ?>"<?php echo $type === $k ? ' checked' : ''; ?> class="se-sr"> <?php echo html_escape($t['label']); ?></label>
          <?php } ?>
        </div>
        <span class="se-help" id="se-type-hint"><?php echo html_escape(sprintf(_l('se_appt_type_default_duration'), se_appt_type_label($type), $fmtDur(se_appt_type_minutes($type)))); ?></span>
      </fieldset>

      <div class="se-field"><label for="rel_id"><?php echo _l('se_appt_patient'); ?></label>
        <input type="hidden" name="rel_type" id="rel_type" value="<?php echo html_escape($relType); ?>">
        <select class="se-input" id="rel_id" name="rel_id" data-live-search="true">
          <option value="0">—</option>
          <optgroup label="<?php echo _l('se_appt_lead'); ?>">
            <?php foreach ($leads as $l) { ?><option value="<?php echo (int) $l['id']; ?>" data-type="lead"<?php echo $relType === 'lead' && $relId === (int) $l['id'] ? ' selected' : ''; ?>><?php echo html_escape($l['name']); ?></option><?php } ?>
          </optgroup>
          <?php if ($clients) { ?><optgroup label="<?php echo _l('se_appt_customer'); ?>">
            <?php foreach ($clients as $c) { ?><option value="<?php echo (int) $c['userid']; ?>" data-type="client"<?php echo $relType === 'client' && $relId === (int) $c['userid'] ? ' selected' : ''; ?>><?php echo html_escape($c['company']); ?></option><?php } ?>
          </optgroup><?php } ?>
        </select>
        <?php if (!empty($pf['from_id'])) { ?><span class="se-help"><?php echo _l('se_appt_prefilled_from'); ?></span><?php } ?>
      </div>

      <div class="se-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
        <div class="se-field"><label for="date"><?php echo _l('se_appt_date'); ?></label><input class="se-input" type="date" id="date" name="date" value="<?php echo html_escape($date); ?>" required></div>
        <div class="se-field"><label for="time"><?php echo _l('se_appt_time'); ?></label>
          <input class="se-input<?php echo !empty($error) && $error_kind === 'conflict' ? ' se-invalid' : ''; ?>" type="time" id="time" name="time" step="900" value="<?php echo html_escape($time); ?>" required<?php echo !empty($error) && $error_kind === 'conflict' ? ' aria-describedby="se-time-err" aria-invalid="true"' : ''; ?>>
          <?php if (!empty($error) && $error_kind === 'conflict') { ?><span class="se-error" id="se-time-err" role="alert">⚠ <?php echo html_escape($error); ?></span><?php } ?>
        </div>
        <div class="se-field"><label for="duration"><?php echo _l('se_appt_duration'); ?></label>
          <select class="se-input" id="duration" name="duration"><?php foreach ($durations as $m) { ?><option value="<?php echo $m; ?>"<?php echo $m === $duration ? ' selected' : ''; ?>><?php echo html_escape($fmtDur($m)); ?></option><?php } ?></select>
          <span class="se-help" id="se-end-hint"></span></div>
        <div class="se-field"><label for="staff_id"><?php echo _l('se_appt_performer'); ?></label>
          <select class="se-input" id="staff_id" name="staff_id"><option value="0">—</option>
            <?php foreach ($staff as $s) { ?><option value="<?php echo (int) $s['staffid']; ?>"<?php echo (int) $v('staff_id', 0) === (int) $s['staffid'] ? ' selected' : ''; ?>><?php echo html_escape(trim($s['firstname'] . ' ' . $s['lastname'])); ?></option><?php } ?>
          </select></div>
        <div class="se-field"><label for="consultation_format"><?php echo _l('se_appt_format'); ?></label>
          <select class="se-input" id="consultation_format" name="consultation_format"><?php foreach ($formats as $fm) { ?><option value="<?php echo $fm; ?>"<?php echo $v('consultation_format', 'in_person') === $fm ? ' selected' : ''; ?>><?php echo _l('se_appt_format_' . $fm); ?></option><?php } ?></select></div>
        <div class="se-field"><label for="location"><?php echo _l('se_appt_location'); ?></label><input class="se-input" type="text" id="location" name="location" maxlength="191" value="<?php echo html_escape($v('location')); ?>" placeholder="<?php echo _l('se_appt_location_ph'); ?>"></div>
      </div>

      <?php if ($a) { ?>
      <div class="se-grid" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
        <div class="se-field"><label for="status"><?php echo _l('se_appt_status'); ?></label>
          <select class="se-input" id="status" name="status"><?php foreach ($statuses as $st) { ?><option value="<?php echo $st; ?>"<?php echo $v('status') === $st ? ' selected' : ''; ?>><?php echo _l('se_appt_status_' . $st); ?></option><?php } ?></select></div>
        <div class="se-field"><label for="cancellation_reason"><?php echo _l('se_appt_cancellation_reason'); ?></label><input class="se-input" type="text" id="cancellation_reason" name="cancellation_reason" maxlength="191" value="<?php echo html_escape($v('cancellation_reason')); ?>"></div>
      </div>
      <?php } else { ?><input type="hidden" name="status" value="scheduled"><?php } ?>

      <div class="se-field"><label for="notes"><?php echo _l('se_appt_notes'); ?></label><textarea class="se-input" id="notes" name="notes" rows="2" maxlength="5000" style="height:auto;padding:10px" placeholder="<?php echo _l('se_appt_notes_ph'); ?>"><?php echo html_escape($v('notes')); ?></textarea></div>
      <details><summary class="se-help"><?php echo _l('se_appt_more_fields'); ?></summary>
        <div class="se-grid" style="grid-template-columns:1fr 1fr;margin-top:8px">
          <div class="se-field"><label for="title"><?php echo _l('se_appt_title'); ?></label><input class="se-input" type="text" id="title" name="title" maxlength="191" value="<?php echo html_escape($v('title')); ?>" placeholder="<?php echo html_escape(se_appt_type_label($type)); ?>"></div>
          <div class="se-field"><label for="staff_timezone"><?php echo _l('se_appt_timezone'); ?></label>
            <select class="se-input" id="staff_timezone" name="staff_timezone"><?php $curTz = $v('staff_timezone') ?: (get_option('default_timezone') ?: 'Europe/Istanbul'); foreach ($timezones as $tz) { ?><option value="<?php echo html_escape($tz); ?>"<?php echo $curTz === $tz ? ' selected' : ''; ?>><?php echo html_escape($tz); ?></option><?php } ?></select></div>
        </div>
      </details>

      <div class="se-field"><label><?php echo _l('se_appt_notify'); ?></label>
        <ul class="se-checks"><li><span class="ok">✓</span> <?php echo _l('se_appt_notify_confirm_auto'); ?></li><li><span class="ok">✓</span> <?php echo _l('se_appt_notify_reminder_auto'); ?></li></ul></div>

      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="submit" class="se-btn se-btn-primary"><?php echo _l('se_appt_save'); ?></button>
        <?php echo se_ui_btn(_l('cancel'), $back !== '' ? $back : admin_url('se_appointments'), 'secondary'); ?>
      </div>
    <?php echo form_close(); ?>

    <aside class="se-stack">
      <div class="se-card"><h2><?php echo _l('se_appt_availability'); ?></h2><p class="se-help"><?php echo _l('se_appt_conflict_hint'); ?></p>
        <?php if ($a) { ?><dl class="se-facts"><div><dt><?php echo _l('se_appt_sync_state'); ?></dt><dd><?php echo html_escape((string) ($a->gcal_sync_state ?? '—')); ?></dd></div></dl><?php } ?>
      </div>
    </aside>
  </div>
</div></div>
<?php init_tail(); ?>
<script>
(function () {
  var form = document.getElementById('se-appt-form'); if (!form) { return; }
  var radios = form.querySelectorAll('input[name=appointment_type]'), dur = document.getElementById('duration'), hint = document.getElementById('se-type-hint'), endHint = document.getElementById('se-end-hint');
  var dateEl = document.getElementById('date'), timeEl = document.getElementById('time'), rel = document.getElementById('rel_id'), relType = document.getElementById('rel_type'), title = document.getElementById('title');
  var labels = <?php echo json_encode(array_map(function ($t) { return $t['label']; }, se_appt_types()), JSON_UNESCAPED_UNICODE); ?>;
  var L = { defaultDur: <?php echo json_encode(_l('se_appt_type_default_duration')); ?>, ends: <?php echo json_encode(_l('se_appt_ends_at')); ?>, h: <?php echo json_encode(_l('se_appt_hours')); ?>, m: <?php echo json_encode(_l('se_appt_minutes')); ?> };
  function fmt(m) { return m >= 60 && m % 60 === 0 ? (m / 60) + ' ' + L.h : (m >= 60 ? Math.floor(m / 60) + ' ' + L.h + ' ' + (m % 60) + ' ' + L.m : m + ' ' + L.m); }
  function pick(r) { var m = parseInt(r.getAttribute('data-minutes'), 10); if (!dur.querySelector('option[value="' + m + '"]')) { var o = document.createElement('option'); o.value = m; o.textContent = fmt(m); dur.appendChild(o); } dur.value = m;
    hint.textContent = L.defaultDur.replace('%s', labels[r.value]).replace('%s', fmt(m)); if (title) { title.placeholder = labels[r.value]; }
    radios.forEach(function (x) { x.parentNode.classList.toggle('on', x === r); }); end(); }
  function end() { if (!dateEl.value || !timeEl.value) { endHint.textContent = ''; return; } var t = timeEl.value.split(':'), d = new Date(dateEl.value + 'T' + timeEl.value + ':00'); d.setMinutes(d.getMinutes() + parseInt(dur.value, 10));
    endHint.textContent = L.ends.replace('%s', ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2)); }
  radios.forEach(function (r) { r.addEventListener('change', function () { pick(r); }); });
  dur.addEventListener('change', end); dateEl.addEventListener('change', end); timeEl.addEventListener('change', end);
  rel.addEventListener('change', function () { var o = rel.options[rel.selectedIndex]; relType.value = o && o.getAttribute('data-type') ? o.getAttribute('data-type') : 'lead'; });
  end();
})();
</script>
</body>
</html>

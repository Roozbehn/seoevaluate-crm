<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $a = $appointment; ?>
<div id="wrapper"><div class="content">

<?php se_ui_header($a ? _l('edit') : _l('se_appt_new'), [
    ['href' => admin_url('se_appointments/se_appointments/manage'), 'label' => _l('se_appt_list'), 'icon' => 'fa-arrow-left'],
]); ?>

<?php echo form_open(admin_url('se_appointments/se_appointments/save/' . ($a ? (int) $a->id : ''))); ?>
<div class="row">
  <div class="col-md-8"><div class="panel_s"><div class="panel-body">

    <div class="form-group">
      <label for="title" class="control-label"><?php echo html_escape(_l('se_appt_title')); ?> <span class="text-danger">*</span></label>
      <input type="text" class="form-control" id="title" name="title" maxlength="191" required
             value="<?php echo $a ? html_escape($a->title) : ''; ?>" />
    </div>

    <div class="row">
      <div class="col-md-6"><div class="form-group">
        <label for="start_at" class="control-label"><?php echo html_escape(_l('se_appt_start')); ?> <span class="text-danger">*</span></label>
        <input type="datetime-local" class="form-control" id="start_at" name="start_at" required
               value="<?php echo $a ? html_escape(str_replace(' ', 'T', substr($a->start_at, 0, 16))) : ''; ?>" />
      </div></div>
      <div class="col-md-6"><div class="form-group">
        <label for="end_at" class="control-label"><?php echo html_escape(_l('se_appt_end')); ?> <span class="text-danger">*</span></label>
        <input type="datetime-local" class="form-control" id="end_at" name="end_at" required
               value="<?php echo $a && $a->end_at ? html_escape(str_replace(' ', 'T', substr($a->end_at, 0, 16))) : ''; ?>" />
      </div></div>
    </div>

    <div class="row">
      <div class="col-md-6"><div class="form-group">
        <label for="brand_id" class="control-label"><?php echo html_escape(_l('se_appt_brand')); ?> <span class="text-danger">*</span></label>
        <?php if ($a) { ?>
          <?php /* An appointment does not change tenant: the brand is fixed on
                   edit and the model drops any posted brand_id anyway. */ ?>
          <p class="form-control-static"><?php echo se_ui_brand_badge((int) $a->brand_id); ?></p>
        <?php } else { ?>
          <select class="form-control" id="brand_id" name="brand_id" required>
            <option value=""></option>
            <?php foreach ($brands as $b) { ?>
              <option value="<?php echo (int) $b['id']; ?>"><?php echo html_escape($b['name']); ?></option>
            <?php } ?>
          </select>
        <?php } ?>
      </div></div>
      <div class="col-md-6"><div class="form-group">
        <label for="staff_id" class="control-label"><?php echo html_escape(_l('se_appt_staff')); ?></label>
        <select class="form-control" id="staff_id" name="staff_id" data-live-search="true">
          <option value="0">&mdash;</option>
          <?php foreach ($staff as $s) { ?>
            <option value="<?php echo (int) $s['staffid']; ?>"<?php echo $a && (int) $a->staff_id === (int) $s['staffid'] ? ' selected' : ''; ?>>
              <?php echo html_escape(trim($s['firstname'] . ' ' . $s['lastname'])); ?>
            </option>
          <?php } ?>
        </select>
      </div></div>
    </div>

    <?php /* Searchable, same-brand relation selectors. The raw numeric
             "se_appt_lead (ID)" input is gone: it invited a foreign id and
             showed the operator a number instead of a name. */ ?>
    <div class="row">
      <div class="col-md-6"><div class="form-group">
        <label for="rel_type" class="control-label"><?php echo html_escape(_l('se_appt_relation_type')); ?></label>
        <select class="form-control" id="rel_type" name="rel_type">
          <option value="lead"<?php echo $a && $a->rel_type === 'lead' ? ' selected' : ''; ?>><?php echo html_escape(_l('se_appt_lead')); ?></option>
          <option value="client"<?php echo $a && $a->rel_type === 'client' ? ' selected' : ''; ?>><?php echo html_escape(_l('se_appt_customer')); ?></option>
        </select>
      </div></div>
      <div class="col-md-6"><div class="form-group">
        <label for="rel_id" class="control-label"><?php echo html_escape(_l('se_appt_relation')); ?></label>
        <select class="form-control" id="rel_id" name="rel_id" data-live-search="true">
          <option value="0">&mdash;</option>
          <optgroup label="<?php echo html_escape(_l('se_appt_lead')); ?>">
            <?php foreach ($leads as $l) { ?>
              <option value="<?php echo (int) $l['id']; ?>"<?php echo $a && $a->rel_type === 'lead' && (int) $a->rel_id === (int) $l['id'] ? ' selected' : ''; ?>>
                <?php echo html_escape($l['name']); ?> (#<?php echo (int) $l['id']; ?>)
              </option>
            <?php } ?>
          </optgroup>
          <optgroup label="<?php echo html_escape(_l('se_appt_customer')); ?>">
            <?php foreach ($clients as $c) { ?>
              <option value="<?php echo (int) $c['userid']; ?>"<?php echo $a && $a->rel_type === 'client' && (int) $a->rel_id === (int) $c['userid'] ? ' selected' : ''; ?>>
                <?php echo html_escape($c['company']); ?> (#<?php echo (int) $c['userid']; ?>)
              </option>
            <?php } ?>
          </optgroup>
        </select>
      </div></div>
    </div>

    <div class="row">
      <div class="col-md-4"><div class="form-group">
        <label for="status" class="control-label"><?php echo html_escape(_l('se_appt_status')); ?></label>
        <select class="form-control" id="status" name="status">
          <?php foreach ($statuses as $st) { ?>
            <option value="<?php echo html_escape($st); ?>"<?php echo $a && $a->status === $st ? ' selected' : ''; ?>>
              <?php echo html_escape(_l('se_appt_status_' . $st)); ?>
            </option>
          <?php } ?>
        </select>
      </div></div>
      <div class="col-md-4"><div class="form-group">
        <label for="consultation_format" class="control-label"><?php echo html_escape(_l('se_appt_format')); ?></label>
        <select class="form-control" id="consultation_format" name="consultation_format">
          <?php foreach ($formats as $fm) { ?>
            <option value="<?php echo html_escape($fm); ?>"<?php echo $a && ($a->consultation_format ?? '') === $fm ? ' selected' : ''; ?>>
              <?php echo html_escape(_l('se_appt_format_' . $fm)); ?>
            </option>
          <?php } ?>
        </select>
      </div></div>
      <div class="col-md-4"><div class="form-group">
        <label for="staff_timezone" class="control-label"><?php echo html_escape(_l('se_appt_timezone')); ?></label>
        <select class="form-control" id="staff_timezone" name="staff_timezone" data-live-search="true">
          <?php $curTz = $a ? ($a->staff_timezone ?? '') : (get_option('default_timezone') ?: 'Europe/Istanbul');
          foreach ($timezones as $tz) { ?>
            <option value="<?php echo html_escape($tz); ?>"<?php echo $curTz === $tz ? ' selected' : ''; ?>><?php echo html_escape($tz); ?></option>
          <?php } ?>
        </select>
      </div></div>
    </div>

    <div class="form-group">
      <label for="location" class="control-label"><?php echo html_escape(_l('se_appt_location')); ?></label>
      <input type="text" class="form-control" id="location" name="location" maxlength="191"
             value="<?php echo $a ? html_escape($a->location) : ''; ?>" />
    </div>

    <div class="form-group">
      <label for="cancellation_reason" class="control-label"><?php echo html_escape(_l('se_appt_cancellation_reason')); ?></label>
      <input type="text" class="form-control" id="cancellation_reason" name="cancellation_reason" maxlength="191"
             value="<?php echo $a ? html_escape((string) ($a->cancellation_reason ?? '')) : ''; ?>" />
    </div>

    <div class="form-group">
      <label for="notes" class="control-label"><?php echo html_escape(_l('se_appt_notes')); ?></label>
      <textarea class="form-control" rows="4" id="notes" name="notes" maxlength="5000"><?php echo $a ? html_escape($a->notes) : ''; ?></textarea>
    </div>

    <button type="submit" class="btn btn-primary"><?php echo html_escape(_l('submit')); ?></button>
    <a href="<?php echo admin_url('se_appointments/se_appointments/manage'); ?>" class="btn btn-default"><?php echo html_escape(_l('cancel')); ?></a>

  </div></div></div>

  <div class="col-md-4"><div class="panel_s"><div class="panel-body">
    <h5><?php echo html_escape(_l('se_appt_availability')); ?></h5>
    <p class="text-muted"><small><?php echo html_escape(_l('se_appt_conflict_hint')); ?></small></p>
    <?php if ($a) { ?>
      <hr />
      <?php se_ui_kv([
          _l('se_appt_sync_state') => se_ui_badge((string) ($a->gcal_sync_state ?? 'unknown')),
          _l('se_appt_reminder')   => se_ui_badge('unknown', _l('se_wa_sending_gated')),
      ], true); ?>
    <?php } ?>
  </div></div></div>
</div>
<?php echo form_close(); ?>

</div></div>
<?php init_tail(); ?></body></html>

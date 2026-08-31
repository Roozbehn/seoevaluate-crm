<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">
            <h4 class="no-margin"><?php echo _l('se_brands'); ?></h4>
            <hr class="hr-panel-heading" />
            <p class="text-muted">
              Each brand is one clinic. Staff see only the brands mapped to them;
              records with no brand stay visible to everyone as a triage bucket.
            </p>

            <table class="table dt-table">
              <thead>
                <tr>
                  <th><?php echo _l('se_brand_name'); ?></th>
                  <th><?php echo _l('se_brand_slug'); ?></th>
                  <th><?php echo _l('se_brand_active'); ?></th>
                  <th><?php echo _l('se_brand_staff'); ?></th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($brands as $brand) { ?>
                  <?php $assigned = $this->se_brands_model->staff_ids($brand['id']); ?>
                  <tr>
                    <td><?php echo html_escape($brand['name']); ?></td>
                    <td><code><?php echo html_escape($brand['slug']); ?></code></td>
                    <td><?php echo $brand['active'] ? '<span class="label label-success">'
                        . _l('se_brand_active') . '</span>' : '&mdash;'; ?></td>
                    <td><?php echo count($assigned); ?></td>
                    <td class="text-right">
                      <a href="#" class="btn btn-default btn-xs"
                         onclick="se_edit_brand(<?php echo html_escape(json_encode($brand)); ?>,
                                                <?php echo html_escape(json_encode($assigned)); ?>); return false;">
                        <?php echo _l('edit'); ?>
                      </a>
                    </td>
                  </tr>
                <?php } ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="row">
      <div class="col-md-8">
        <div class="panel_s">
          <div class="panel-body">
            <h4 id="se-form-title"><?php echo _l('se_brand_add'); ?></h4>
            <hr class="hr-panel-heading" />
            <?php echo form_open(admin_url('se_core/brands/save'), ['id' => 'se-brand-form']); ?>
              <input type="hidden" name="id" id="se-brand-id" value="" />

              <?php echo render_input('name', 'se_brand_name'); ?>
              <?php echo render_input('slug', 'se_brand_slug'); ?>

              <div class="checkbox checkbox-primary">
                <input type="checkbox" name="active" id="active" checked />
                <label for="active"><?php echo _l('se_brand_active'); ?></label>
              </div>

              <hr />
              <h5><?php echo _l('se_brand_staff'); ?></h5>
              <?php foreach ($staff as $member) { ?>
                <div class="checkbox checkbox-primary">
                  <input type="checkbox" name="staff[]" class="se-staff"
                         id="staff_<?php echo $member['staffid']; ?>"
                         value="<?php echo $member['staffid']; ?>" />
                  <label for="staff_<?php echo $member['staffid']; ?>">
                    <?php echo html_escape($member['firstname'] . ' ' . $member['lastname']); ?>
                  </label>
                </div>
              <?php } ?>

              <hr />
              <h5><?php echo _l('se_brand_platform_ids'); ?></h5>
              <p class="text-muted small">
                Filled in as each integration is connected. Leave blank until then.
              </p>
              <?php
                foreach ([
                    'meta_page_id', 'meta_ad_account_id', 'meta_dataset_id',
                    'whatsapp_waba_id', 'whatsapp_phone_number_id',
                    'google_ads_customer_id', 'ga4_property_id', 'gsc_site_url',
                ] as $field) {
                    echo render_input($field, 'se_' . $field);
                }
              ?>

              <button type="submit" class="btn btn-primary"><?php echo _l('submit'); ?></button>
            <?php echo form_close(); ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php init_tail(); ?>
<script>
function se_edit_brand(brand, staffIds) {
    $('#se-form-title').text('<?php echo _l('se_brand_edit'); ?>');
    $('#se-brand-form').attr('action', admin_url + 'se_core/brands/save/' + brand.id);
    $('#se-brand-id').val(brand.id);

    $.each(brand, function (key, value) {
        var field = $('#se-brand-form [name="' + key + '"]');
        if (field.length && field.attr('type') !== 'checkbox') {
            // Platform identifiers are opaque strings. Assign through the DOM
            // property as well so long numeric-looking IDs cannot be dropped by
            // form helpers or jQuery value hooks when the edit form is opened.
            field.each(function () {
                this.value = value == null ? '' : String(value);
            });
        }
    });

    $('#active').prop('checked', brand.active == 1);

    $('.se-staff').prop('checked', false);
    $.each(staffIds, function (i, id) {
        $('#staff_' + id).prop('checked', true);
    });

    $('html, body').animate({ scrollTop: $('#se-brand-form').offset().top - 80 }, 300);
}
</script>
</body>
</html>

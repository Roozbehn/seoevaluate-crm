<?php defined('BASEPATH') or exit('No direct script access allowed');
require __DIR__ . '/_shell.php';
se_journey_public_shell_open('Fotoğraf yükleme', isset($j) && !empty($j->language) ? (string) $j->language : 'tr');
$kinds = $followup ? ['followup' => 'Güncel fotoğraf (takip)'] : ['frontal' => '1. Tam karşıdan (iki kaş birlikte)', 'left' => '2. Sol kaş yakın plan', 'right' => '3. Sağ kaş yakın plan', 'donor' => '4. Donör alan (yalnızca ekibimiz istediyse)'];
$reasons = ['ok' => 'Yüklendi ✓', 'too_large' => 'Dosya çok büyük (en fazla 5 MB)', 'unsupported_type' => 'Yalnızca JPEG, PNG veya WebP', 'too_small' => 'Fotoğraf çok küçük (en az 300 px)',
            'too_large_dimensions' => 'Fotoğraf boyutu çok büyük', 'undecodable' => 'Dosya okunamadı', 'not_an_image' => 'Bu bir fotoğraf değil', 'extension_mismatch' => 'Dosya türü uzantıyla uyuşmuyor',
            'polyglot_suspected' => 'Dosya reddedildi', 'consent_required' => 'Önce formdaki tercihlerinizi tamamlayın', 'upload_error' => 'Yükleme hatası', 'storage_unavailable' => 'Şu anda kaydedilemiyor', 'duplicate' => 'Bu fotoğraf zaten alındı'];
?>
<div class="card">
  <h2><?php echo $followup ? 'Takip fotoğrafı' : 'Kaş fotoğraflarınız'; ?></h2>
  <?php if (!$consent_ok) { ?>
    <p class="warn">Fotoğrafları işleyebilmemiz için önce güvenli formdaki tercihlerinizi tamamlamanız gerekiyor.</p>
  <?php } else { ?>
    <p class="muted">Makyajsız, filtresiz ve gün ışığına yakın aydınlık bir ortamda çekin. Fotoğraflar yalnızca değerlendirme amacıyla işlenir; tanıtım/paylaşım izni bundan ayrıdır.</p>
    <?php if (!$followup) { ?>
    <ul class="check">
      <?php foreach (['frontal', 'left', 'right'] as $k) { ?><li><?php echo !empty($checklist[$k]) ? '<span class="ok">✓</span>' : '○'; ?> <?php echo html_escape($kinds[$k]); ?></li><?php } ?>
      <?php if (in_array('donor', se_journey_required_photo_kinds($j), true)) { ?><li><?php echo !empty($checklist['donor']) ? '<span class="ok">✓</span>' : '○'; ?> <?php echo html_escape($kinds['donor']); ?></li><?php } ?>
    </ul>
    <p class="muted">Alınan fotoğraf: <?php echo (int) $count; ?></p>
    <?php } ?>
    <?php foreach ($results as $k => $r) { ?><p class="<?php echo $r === 'ok' ? 'ok' : 'err'; ?>"><?php echo html_escape(($kinds[$k] ?? $k) . ': ' . ($reasons[$r] ?? $r)); ?></p><?php } ?>
    <form method="post" action="<?php echo html_escape($action); ?>" enctype="multipart/form-data">
      <input type="hidden" name="<?php echo html_escape($csrf_name); ?>" value="<?php echo html_escape($csrf_hash); ?>" />
      <?php foreach ($kinds as $k => $label) { ?>
        <label class="f" for="p_<?php echo $k; ?>"><?php echo html_escape($label); ?></label>
        <input type="file" id="p_<?php echo $k; ?>" name="photo_<?php echo $k; ?>" accept="image/jpeg,image/png,image/webp" capture="user" />
      <?php } ?>
      <button class="btn primary" type="submit">Fotoğrafları yükle</button>
    </form>
    <p class="muted">Fotoğrafları dilerseniz WhatsApp üzerinden de gönderebilirsiniz.</p>
  <?php } ?>
</div>
<?php se_journey_public_shell_close(); ?>

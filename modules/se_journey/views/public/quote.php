<?php defined('BASEPATH') or exit('No direct script access allowed');
require __DIR__ . '/_shell.php';
se_journey_public_shell_open('Ön değerlendirme sonucu');
$s = $snapshot;
$fmt = function ($n, $cur) { return number_format((float) $n, 0, ',', '.') . ' ' . html_escape($cur); };
?>
<div class="card">
  <h2>Ön değerlendirme sonucu ve teklifiniz</h2>
  <p class="muted"><?php echo html_escape($s['clinic']); ?> · <?php echo html_escape($s['title']); ?> · Sürüm <?php echo (int) $s['version']; ?></p>
  <?php if (!empty($s['prepared_for'])) { ?><p>Sayın <?php echo html_escape($s['prepared_for']); ?>,</p><?php } ?>
  <p>Paylaştığınız bilgiler ve fotoğraflar ekibimiz tarafından incelendi. Önerimiz:
    <strong><?php echo $s['recommendation'] === 'procedure_after_consultation' ? 'Görüşme sonrasında işlem planlaması' : 'Öncelikle klinisyenle bir ön görüşme'; ?></strong>.</p>
  <?php if (!empty($s['amount'])) { ?>
    <div class="notice"><strong>Ön teklif:</strong>
      <?php echo $s['amount']['kind'] === 'range' ? $fmt($s['amount']['min'], $s['amount']['currency']) . ' – ' . $fmt($s['amount']['max'], $s['amount']['currency']) : $fmt($s['amount']['value'], $s['amount']['currency']); ?>
      <?php if (!empty($s['valid_until'])) { ?><br /><small>Geçerlilik: <?php echo html_escape(date('d.m.Y', strtotime($s['valid_until']))); ?></small><?php } ?></div>
  <?php } elseif (!empty($s['valid_until'])) { ?><p class="muted">Geçerlilik: <?php echo html_escape(date('d.m.Y', strtotime($s['valid_until']))); ?></p><?php } ?>
  <?php if (!empty($s['included'])) { ?><h2>Kapsam</h2><ul class="check"><?php foreach ($s['included'] as $i) { ?><li>✓ <?php echo html_escape($i); ?></li><?php } ?></ul><?php } ?>
  <?php if (!empty($s['excluded'])) { ?><h2>Kapsam dışı</h2><ul class="check"><?php foreach ($s['excluded'] as $i) { ?><li>– <?php echo html_escape($i); ?></li><?php } ?></ul><?php } ?>
  <?php if (!empty($s['deposit_terms'])) { ?><p><strong>Ödeme / depozito:</strong> <?php echo html_escape($s['deposit_terms']); ?></p><?php } ?>
  <?php if (!empty($s['travel_notes'])) { ?><p><strong>Seyahat ve konaklama:</strong> <?php echo nl2br(html_escape($s['travel_notes'])); ?></p><?php } ?>
  <p class="notice"><?php echo html_escape($s['disclaimer']); ?></p>
  <p class="muted">Sorularınız için WhatsApp üzerinden bu numaraya yazabilirsiniz: +90 547 120 70 70</p>
</div>
<?php se_journey_public_shell_close(); ?>

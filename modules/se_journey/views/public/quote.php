<?php defined('BASEPATH') or exit('No direct script access allowed');
require __DIR__ . '/_shell.php';
se_journey_public_shell_open('Ön değerlendirme sonucu');
$s = $snapshot;
$fmt = function ($n, $cur) { return number_format((float) $n, 0, ',', '.') . ' ' . html_escape($cur); };
$response = (string) ($response ?? '');
$notice   = (string) ($notice ?? '');
$booking  = $booking ?? null;
$state    = (string) ($state ?? '');
$answerable = in_array($state, ['quote_sent', 'quote_accepted', 'quote_revision_requested'], true);
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
</div>

<div class="card" id="karar">
  <h2>Kararınız</h2>
  <?php if ($notice === 'revision') { ?>
    <p class="ok">Fiyat revizyonu talebiniz alındı. Danışmanınız teklifinizi gözden geçirip WhatsApp üzerinden sizinle iletişime geçecektir.</p>
  <?php } elseif ($notice === 'handoff') { ?>
    <p class="ok">Talebiniz ekibimize iletildi; bir danışmanımız WhatsApp üzerinden sizinle iletişime geçecektir.</p>
  <?php } elseif ($notice === 'failed') { ?>
    <p class="err">İşlem şu anda tamamlanamadı. Lütfen WhatsApp üzerinden bize yazın.</p>
  <?php } ?>

  <?php if ($booking) { ?>
    <p class="ok">Klinikte ön görüşmeniz planlandı: <strong><?php echo html_escape(date('d.m.Y H:i', strtotime((string) $booking->start_at))); ?></strong>.
      Değişiklik için WhatsApp üzerinden bize yazabilirsiniz.</p>
  <?php } elseif ($response === 'accepted') { ?>
    <p class="ok">Teklifi kabul ettiğinizi kaydettik. Klinikte yüz yüze ön görüşme için size uygun tarih ve saati seçebilirsiniz.</p>
    <form method="post" action="<?php echo html_escape($action); ?>">
      <input type="hidden" name="<?php echo html_escape($csrf_name); ?>" value="<?php echo html_escape($csrf_hash); ?>" />
      <input type="hidden" name="action" value="book" />
      <button class="btn primary" type="submit">Görüşme tarihi seç</button>
    </form>
  <?php } elseif ($answerable) { ?>
    <?php if ($response === 'revision_requested') { ?><p class="muted">Fiyat revizyonu talebiniz ekibimizde. Dilerseniz mevcut teklifi kabul edebilirsiniz.</p><?php } else { ?>
    <p class="muted">Teklifi kabul ederseniz bir sonraki adımda klinikte yüz yüze ön görüşme için takvimden size uygun bir tarih seçebilirsiniz.</p><?php } ?>
    <form method="post" action="<?php echo html_escape($action); ?>">
      <input type="hidden" name="<?php echo html_escape($csrf_name); ?>" value="<?php echo html_escape($csrf_hash); ?>" />
      <input type="hidden" name="action" value="accept" />
      <button class="btn primary" type="submit">Teklifi kabul et ve görüşme tarihi seç</button>
    </form>
    <?php if ($response !== 'revision_requested') { ?>
    <form method="post" action="<?php echo html_escape($action); ?>">
      <input type="hidden" name="<?php echo html_escape($csrf_name); ?>" value="<?php echo html_escape($csrf_hash); ?>" />
      <input type="hidden" name="action" value="revise" />
      <button class="btn ghost" type="submit">Fiyat revizyonu talep et</button>
    </form>
    <?php } ?>
    <form method="post" action="<?php echo html_escape($action); ?>">
      <input type="hidden" name="<?php echo html_escape($csrf_name); ?>" value="<?php echo html_escape($csrf_hash); ?>" />
      <input type="hidden" name="action" value="handoff" />
      <button class="btn link" type="submit">Danışmana bağlan</button>
    </form>
  <?php } else { ?>
    <p class="muted">Bu teklif için işlem yapılamıyor. Sorularınız için WhatsApp üzerinden bize yazabilirsiniz.</p>
  <?php } ?>
  <p class="muted">Sorularınız için WhatsApp üzerinden bu numaraya yazabilirsiniz: +90 547 120 70 70</p>
</div>
<?php se_journey_public_shell_close(); ?>

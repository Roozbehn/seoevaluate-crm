<?php defined('BASEPATH') or exit('No direct script access allowed');
require __DIR__ . '/_shell.php';
se_journey_public_shell_open('Eksik bilgi');
?>
<div class="card"><h2 class="warn">Form henüz gönderilemedi</h2>
  <?php if ($reason === 'validation') { ?>
    <p>Aşağıdaki alanları tamamlamanız gerekiyor:</p>
    <ul class="check">
      <?php foreach ($missing as $k) { ?><li>○ <?php echo html_escape($fields[$k]['label'] ?? $k); ?></li><?php } ?>
      <?php foreach ($errors as $k => $e) { ?><li class="err">! <?php echo html_escape(($fields[$k]['label'] ?? $k) . ' — geçersiz değer'); ?></li><?php } ?>
    </ul>
  <?php } else { ?>
    <p><?php echo html_escape($reason === 'consent_required' ? 'Önce aydınlatma metnini onaylamanız gerekiyor.' : 'Form şu anda kaydedilemiyor; lütfen daha sonra tekrar deneyin.'); ?></p>
  <?php } ?>
  <a class="btn primary" href="<?php echo html_escape($base); ?>">Forma dön</a>
</div>
<?php se_journey_public_shell_close(); ?>

<?php defined('BASEPATH') or exit('No direct script access allowed');
require __DIR__ . '/_shell.php';
se_journey_public_shell_open('Formunuz alındı', isset($j) && !empty($j->language) ? (string) $j->language : 'tr');
?>
<div class="card"><h2 class="ok">Formunuz alındı ✓</h2>
  <p>Teşekkür ederiz. Ön değerlendirmenin tamamlanabilmesi için kaş fotoğraflarınızı bekliyoruz: tam karşıdan, sol kaş ve sağ kaş yakın plan.</p>
  <?php if ($photos_url !== '') { ?><a class="btn primary" href="<?php echo html_escape($photos_url); ?>">Fotoğraf yükle</a><?php } ?>
  <p class="muted">Fotoğrafları WhatsApp üzerinden de gönderebilirsiniz. Ekibimiz inceledikten sonra sizinle iletişime geçecektir; bu ön değerlendirme tıbbi tanı veya kesin uygunluk kararı değildir.</p></div>
<?php se_journey_public_shell_close(); ?>

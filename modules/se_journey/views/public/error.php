<?php defined('BASEPATH') or exit('No direct script access allowed');
require __DIR__ . '/_shell.php';
se_journey_public_shell_open('Bağlantı geçersiz');
$msg = [
    'expired'      => 'Bu bağlantının süresi dolmuş. Yeni bir bağlantı için WhatsApp üzerinden "bağlantı" yazmanız yeterlidir.',
    'revoked'      => 'Bu bağlantı artık geçerli değil. Yeni bir bağlantı için WhatsApp üzerinden "bağlantı" yazmanız yeterlidir.',
    'rate_limited' => 'Çok fazla deneme yapıldı. Lütfen birkaç dakika sonra tekrar deneyin.',
    'opted_out'    => 'İletişim tercihiniz nedeniyle bu sayfa kapatıldı. Devam etmek isterseniz WhatsApp üzerinden yazabilirsiniz.',
];
?>
<div class="card"><h2>Bağlantı açılamıyor</h2><p><?php echo html_escape($msg[$reason] ?? 'Bu bağlantı bulunamadı veya geçersiz.'); ?></p></div>
<?php se_journey_public_shell_close(); ?>

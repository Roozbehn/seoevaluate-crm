<?php defined('BASEPATH') or exit('No direct script access allowed');
require __DIR__ . '/_shell.php';
se_journey_public_shell_open('Görüşme tarihi seçin');
$days_tr   = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
$months_tr = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
$fmtDay = function ($date) use ($days_tr, $months_tr) {
    $t = strtotime($date);

    return (int) date('j', $t) . ' ' . $months_tr[(int) date('n', $t)] . ' ' . $days_tr[(int) date('w', $t)];
};
$result  = $result ?? null;
$booking = $booking ?? null;
$cfg     = $avail['cfg'];
?>
<div class="card">
  <h2>Klinikte yüz yüze ön görüşme</h2>
  <?php if ($booking) { ?>
    <p class="ok">Görüşmeniz planlandı: <strong><?php echo html_escape($fmtDay(date('Y-m-d', strtotime((string) $booking->start_at))) . ', ' . date('H:i', strtotime((string) $booking->start_at))); ?></strong></p>
    <?php if (!empty($cfg['location'])) { ?><p><strong>Adres:</strong> <?php echo html_escape($cfg['location']); ?></p><?php } ?>
    <p class="muted">Onay mesajı WhatsApp üzerinden gönderildi. Değişiklik veya iptal için WhatsApp'tan bize yazmanız yeterlidir.</p>
  <?php } else { ?>
    <?php if ($result && !$result['ok']) { ?>
      <p class="err"><?php
        switch ((string) $result['reason']) {
            case 'slot_unavailable': echo 'Seçtiğiniz saat az önce doldu. Lütfen başka bir saat seçin.'; break;
            case 'already_booked':   echo 'Zaten planlanmış bir görüşmeniz var.'; break;
            case 'no_calendar':      echo 'Takvim şu anda kullanılamıyor. Lütfen WhatsApp üzerinden bize yazın.'; break;
            case 'state':            echo 'Bu bağlantı ile şu anda randevu alınamıyor. Lütfen WhatsApp üzerinden bize yazın.'; break;
            default:                 echo 'İşlem tamamlanamadı. Lütfen tekrar deneyin veya WhatsApp üzerinden bize yazın.';
        }
      ?></p>
    <?php } ?>
    <p>Size uygun günü ve saati seçin. Görüşme yaklaşık <?php echo (int) $avail['slot_minutes']; ?> dakika sürer.</p>
    <?php if (!empty($cfg['location'])) { ?><p class="muted"><strong>Adres:</strong> <?php echo html_escape($cfg['location']); ?></p><?php } ?>
    <?php if (!$avail['ok'] || !$avail['days']) { ?>
      <p class="warn"><?php echo $avail['reason'] === 'no_calendar' ? 'Takvim şu anda kullanılamıyor.' : 'Önümüzdeki günlerde uygun saat bulunamadı.'; ?> Lütfen WhatsApp üzerinden bize yazın; ekibimiz sizin için uygun bir zaman planlayacaktır.</p>
    <?php } else { ?>
      <form method="post" action="<?php echo html_escape($action); ?>" id="bookform">
        <input type="hidden" name="<?php echo html_escape($csrf_name); ?>" value="<?php echo html_escape($csrf_hash); ?>" />
        <div class="days" id="days">
          <?php $first = true; foreach ($avail['days'] as $date => $slots) { ?>
            <button type="button" class="day<?php echo $first ? ' on' : ''; ?>" data-day="<?php echo html_escape($date); ?>">
              <span class="d"><?php echo (int) date('j', strtotime($date)); ?></span>
              <span class="m"><?php echo html_escape(mb_substr($months_tr[(int) date('n', strtotime($date))], 0, 3)); ?></span>
              <span class="w"><?php echo html_escape(mb_substr($days_tr[(int) date('w', strtotime($date))], 0, 3)); ?></span>
            </button>
          <?php $first = false; } ?>
        </div>
        <?php $first = true; foreach ($avail['days'] as $date => $slots) { ?>
          <div class="dayslots<?php echo $first ? ' on' : ''; ?>" data-day="<?php echo html_escape($date); ?>">
            <h2><?php echo html_escape($fmtDay($date)); ?></h2>
            <div class="slots">
              <?php foreach ($slots as $sl) { ?>
                <button class="slot" type="submit" name="slot" value="<?php echo html_escape($sl['start']); ?>"><?php echo html_escape(date('H:i', strtotime($sl['start']))); ?></button>
              <?php } ?>
            </div>
          </div>
        <?php $first = false; } ?>
      </form>
      <p class="muted">Saatler klinik yerel saatine göredir. Seçtiğiniz saat için WhatsApp üzerinden onay mesajı alırsınız.</p>
    <?php } ?>
  <?php } ?>
  <p class="muted">Sorularınız için WhatsApp üzerinden bu numaraya yazabilirsiniz: +90 547 120 70 70</p>
</div>
<style>
.days{display:flex;gap:8px;overflow-x:auto;padding:4px 0 10px;-webkit-overflow-scrolling:touch}
.days .day{flex:none;width:64px;padding:8px 4px;border:1px solid var(--line);border-radius:12px;background:#fff;cursor:pointer;text-align:center;font:inherit}
.days .day span{display:block;line-height:1.2}.days .day .d{font-size:20px;font-weight:600}.days .day .m,.days .day .w{font-size:12px;color:var(--muted)}
.days .day.on{border-color:var(--accent);background:var(--accent-2)}.days .day.on .m,.days .day.on .w{color:var(--accent)}
.dayslots{display:none}.dayslots.on{display:block}.nojs .dayslots{display:block}.nojs .days{display:none}
.slots{display:grid;grid-template-columns:repeat(auto-fill,minmax(88px,1fr));gap:8px}
.slots .slot{padding:12px 6px;border:1px solid var(--accent);border-radius:10px;background:#fff;color:var(--accent);font:inherit;font-weight:600;cursor:pointer}
.slots .slot:hover,.slots .slot:focus{background:var(--accent);color:#fff}
</style>
<script>
(function(){
  var days=document.querySelectorAll('#days .day'),panels=document.querySelectorAll('.dayslots');
  for(var i=0;i<days.length;i++){days[i].addEventListener('click',function(){
    var d=this.getAttribute('data-day');
    for(var k=0;k<days.length;k++){days[k].className='day'+(days[k].getAttribute('data-day')===d?' on':'');}
    for(var p=0;p<panels.length;p++){panels[p].className='dayslots'+(panels[p].getAttribute('data-day')===d?' on':'');}
  });}
})();
</script>
<?php se_journey_public_shell_close(); ?>

<?php defined('BASEPATH') or exit('No direct script access allowed');
require __DIR__ . '/_shell.php';
se_journey_public_shell_open('Ön değerlendirme formu', isset($j) && !empty($j->language) ? (string) $j->language : 'tr');
$csrf = '<input type="hidden" name="' . html_escape($csrf_name) . '" value="' . html_escape($csrf_hash) . '" />';
?>

<?php if (!$allowed) { ?>
  <div class="card"><h2>Form hazırlanıyor</h2>
    <p>Ön değerlendirme formumuz şu anda hazırlanıyor. Ekibimiz en kısa sürede sizinle WhatsApp üzerinden iletişime geçecektir.</p></div>

<?php } elseif ($submitted) { ?>
  <div class="card"><h2 class="ok">Formunuz alındı ✓</h2>
    <p>Teşekkür ederiz. Ön değerlendirmenin tamamlanabilmesi için kaş fotoğraflarınızı bekliyoruz.</p>
    <a class="btn primary" href="<?php echo html_escape($photos_url); ?>">Fotoğraf yükle</a>
    <p class="muted">Fotoğrafları WhatsApp üzerinden de gönderebilirsiniz.</p></div>

<?php } elseif (!$consent['health_data']) { ?>
  <?php if ($this->input->get('declined')) { ?>
    <div class="card"><p class="warn">Tercihinizi kaydettik. Sağlık bilgisi paylaşmadan formu tamamlamak mümkün değildir; ekibimizle genel bilgi için WhatsApp'tan görüşebilirsiniz. Fikrinizi değiştirirseniz aşağıdan devam edebilirsiniz.</p></div>
  <?php } ?>
  <div class="card">
    <h2>Aydınlatma metni ve tercihleriniz</h2>
    <?php if ($draft) { ?><p class="notice warn"><strong>TASLAK — hukuk onayı bekliyor.</strong> Bu metin henüz nihai değildir.</p><?php } ?>
    <p class="muted">Sürüm: <?php echo html_escape($version); ?> · Kanal: WhatsApp → güvenli form</p>
    <div class="notice"><?php echo nl2br(html_escape($texts['health_data'])); ?></div>
    <form method="post" action="<?php echo html_escape($base . '/consent'); ?>">
      <?php echo $csrf; ?>
      <label class="opt"><input type="checkbox" name="consent_health_data" value="yes" required /><span><strong>Sağlık bilgilerimin ve kaş/yüz fotoğraflarımın yalnızca ön değerlendirme amacıyla işlenmesine açık rıza veriyorum.</strong> <span class="req">*</span></span></label>
      <?php if ($texts['photo_publication'] !== '') { ?>
        <label class="opt"><input type="checkbox" name="consent_photo_publication" value="yes" /><span><?php echo html_escape($texts['photo_publication']); ?> <em class="muted">(isteğe bağlı — değerlendirmeyi etkilemez)</em></span></label>
      <?php } ?>
      <?php if ($texts['marketing'] !== '') { ?>
        <label class="opt"><input type="checkbox" name="consent_marketing" value="yes" /><span><?php echo html_escape($texts['marketing']); ?> <em class="muted">(isteğe bağlı — değerlendirmeyi etkilemez)</em></span></label>
      <?php } ?>
      <button class="btn primary" type="submit">Devam et</button>
    </form>
    <form method="post" action="<?php echo html_escape($base . '/consent'); ?>">
      <?php echo $csrf; ?><input type="hidden" name="consent_health_data" value="no" />
      <button class="btn link" type="submit">Sağlık bilgisi paylaşmak istemiyorum</button>
    </form>
  </div>

<?php } else {
    $sections = $questionnaire['sections'];
    $keys = array_keys($sections);
    $n = count($keys) + 1; ?>
  <div class="steps" id="steps"><?php for ($i = 0; $i < $n; $i++) { echo '<span data-i="' . $i . '"></span>'; } ?></div>
  <form method="post" action="<?php echo html_escape($base . '/submit'); ?>" id="intake" novalidate>
    <?php echo $csrf; ?>
    <?php foreach ($keys as $idx => $sk) { $section = $sections[$sk]; ?>
      <div class="card section" data-step="<?php echo $idx; ?>" id="adim-<?php echo $idx + 1; ?>">
        <h2><?php echo ($idx + 1) . '/' . $n . ' · ' . html_escape($section['title']); ?></h2>
        <?php if (!empty($section['sensitive'])) { ?><p class="muted">Bu bölümdeki bilgiler özel nitelikli kişisel veridir; şifrelenerek saklanır ve yalnızca değerlendirme için kullanılır. Emin olmadığınız sorularda "Bilmiyorum" ya da "Klinisyenle görüşmeyi tercih ederim" seçeneğini kullanabilirsiniz.</p><?php } ?>
        <?php foreach ($section['fields'] as $fk => $f) { if (!isset($fields[$fk])) { continue; }
            se_journey_public_field($fk, $f, $answers[$fk] ?? null, $masked_phone); } ?>
        <div class="err" data-errors></div>
        <button class="btn primary" type="button" data-next><?php echo $idx + 1 < count($keys) ? 'Kaydet ve devam' : 'Kaydet ve özete geç'; ?></button>
        <?php if ($idx > 0) { ?><button class="btn link" type="button" data-prev>Geri</button><?php } ?>
      </div>
    <?php } ?>
    <div class="card section" data-step="<?php echo count($keys); ?>" id="adim-<?php echo $n; ?>">
      <h2><?php echo $n . '/' . $n; ?> · Özet ve gönderim</h2>
      <p class="muted">Yanıtlarınızı kontrol edin; düzeltmek için ilgili bölüme geri dönebilirsiniz. "Gönder" dedikten sonra ekibimiz inceler; bu bir tıbbi tanı veya kesin uygunluk kararı değildir.</p>
      <div id="summary" class="muted"></div>
      <div class="err" data-errors></div>
      <button class="btn primary" type="submit">Gönder</button>
      <button class="btn link" type="button" data-prev>Geri</button>
    </div>
  </form>

  <script>
  (function () {
    var form = document.getElementById('intake');
    var sections = [].slice.call(form.querySelectorAll('.section'));
    var steps = [].slice.call(document.querySelectorAll('#steps span'));
    var saveUrl = <?php echo json_encode($base . '/save'); ?>;
    var csrfName = <?php echo json_encode($csrf_name); ?>, csrfHash = <?php echo json_encode($csrf_hash); ?>;
    var labels = <?php echo json_encode(array_map(function ($f) { return ['label' => $f['label'], 'options' => $f['options'] ?? null, 'section' => $f['section']]; }, $fields), JSON_UNESCAPED_UNICODE); ?>;
    var current = 0;
    var hash = (location.hash || '').match(/adim-(\d+)/); if (hash) { current = Math.min(sections.length - 1, Math.max(0, parseInt(hash[1], 10) - 1)); }

    function show(i) {
      current = i;
      sections.forEach(function (s, k) { s.classList.toggle('on', k === i); });
      steps.forEach(function (s, k) { s.classList.toggle('on', k <= i); });
      if (i === sections.length - 1) { renderSummary(); }
      window.scrollTo(0, 0);
    }
    function collect(section) {
      var data = new URLSearchParams();
      data.append(csrfName, csrfHash);
      section.querySelectorAll('input, select, textarea').forEach(function (el) {
        if (!el.name) { return; }
        if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) { return; }
        data.append(el.name, el.value);
      });
      return data;
    }
    function save(section, done) {
      var box = section.querySelector('[data-errors]'); box.textContent = '';
      fetch(saveUrl, { method: 'POST', credentials: 'same-origin', body: collect(section), headers: { 'X-Requested-With': 'fetch' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) {
            var msgs = []; Object.keys(j.errors || {}).forEach(function (k) { msgs.push((labels[k] ? labels[k].label : k) + ': geçersiz değer'); });
            box.textContent = msgs.length ? msgs.join(' · ') : 'Kaydedilemedi (' + j.reason + '). Lütfen tekrar deneyin.';
            return;
          }
          done();
        }).catch(function () { box.textContent = 'Bağlantı hatası — lütfen tekrar deneyin.'; });
    }
    function renderSummary() {
      var out = [];
      Object.keys(labels).forEach(function (k) {
        var els = form.querySelectorAll('[name="' + k + '"], [name="' + k + '[]"]'); if (!els.length) { return; }
        var vals = [];
        els.forEach(function (el) { if ((el.type === 'checkbox' || el.type === 'radio')) { if (el.checked) { vals.push(el.value); } } else if (el.value) { vals.push(el.value); } });
        if (!vals.length) { return; }
        var shown = vals.map(function (v) { return labels[k].options && labels[k].options[v] ? labels[k].options[v] : v; }).join(', ');
        out.push('<div><strong>' + labels[k].label + ':</strong> ' + shown.replace(/</g, '&lt;') + '</div>');
      });
      document.getElementById('summary').innerHTML = out.join('') || '<em>Henüz yanıt yok.</em>';
    }
    form.querySelectorAll('[data-next]').forEach(function (b) { b.addEventListener('click', function () { var s = b.closest('.section'); save(s, function () { show(current + 1); }); }); });
    form.querySelectorAll('[data-prev]').forEach(function (b) { b.addEventListener('click', function () { show(Math.max(0, current - 1)); }); });
    show(current);
  })();
  </script>
<?php } ?>

<?php se_journey_public_shell_close(); ?>

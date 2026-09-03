<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Shared shell for the patient-facing pages. Standalone HTML (no CRM chrome),
 * mobile-first, inline CSS only (the CSP allows no external assets), Turkish
 * source strings. $title and $body are provided by the including view.
 */
if (!function_exists('se_journey_public_shell_open')) {
    function se_journey_public_shell_open($title, $lang = 'tr')
    {
        // Patient blocks carry the PATIENT's language (UX-X03 / CRM-M020): Persian/Arabic read right-to-left.
        $lang = preg_match('/^[a-z]{2}$/', (string) $lang) ? (string) $lang : 'tr';
        $dir  = in_array($lang, ['fa', 'ar', 'he', 'ur'], true) ? 'rtl' : 'ltr';
        echo '<!DOCTYPE html><html lang="' . $lang . '" dir="' . $dir . '"><head><meta charset="utf-8" /><meta name="viewport" content="width=device-width, initial-scale=1" />'
           . '<meta name="robots" content="noindex, nofollow" /><title>' . html_escape($title) . '</title><style>'
           . ':root{--ink:#1d1d1f;--muted:#6b6b70;--line:#dcdce0;--accent:#8a4b5e;--accent-2:#f3e7eb;--ok:#2f7d4f;--warn:#a05a00;--bad:#b3261e;--bg:#fbf8f6}'
           . '*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}'
           . '.wrap{max-width:640px;margin:0 auto;padding:16px}header{padding:12px 0 8px}header h1{font-size:18px;margin:0;font-weight:600}header .sub{color:var(--muted);font-size:13px}'
           . '.card{background:#fff;border:1px solid #e6e2e4;border-radius:12px;padding:16px;margin:12px 0}'
           . 'h2{font-size:17px;margin:0 0 8px}p{margin:8px 0}.muted{color:var(--muted);font-size:14px}'
           . 'label.f{display:block;font-weight:600;margin:14px 0 6px;font-size:15px}.help{color:var(--muted);font-size:13px;margin:-2px 0 6px}'
           . 'input[type=text],input[type=number],select,textarea{width:100%;padding:12px;border:1px solid #cfcad0;border-radius:10px;font-size:16px;background:#fff}'
           . 'textarea{min-height:88px}.opt{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:1px solid #e6e2e4;border-radius:10px;margin:6px 0;background:#fff}'
           . '.opt input{margin-top:4px;width:20px;height:20px;flex:none}.opt span{flex:1}'
           . '.btn{display:inline-block;width:100%;padding:14px 16px;border-radius:12px;border:0;font-size:16px;font-weight:600;cursor:pointer;text-align:center;text-decoration:none;margin-top:12px}'
           . '.btn.primary{background:var(--accent);color:#fff}.btn.ghost{background:#fff;color:var(--accent);border:1px solid var(--accent)}.btn.link{background:none;color:var(--muted);font-weight:400;font-size:14px}'
           . '.notice{background:var(--accent-2);border-radius:10px;padding:12px;font-size:14px}.err{color:var(--bad);font-size:14px}.ok{color:var(--ok)}.warn{color:var(--warn)}'
           . '.steps{display:flex;gap:6px;margin:8px 0 4px}.steps span{flex:1;height:6px;border-radius:3px;background:#e6e2e4}.steps span.on{background:var(--accent)}'
           . '.section{display:none}.section.on{display:block}.nojs .section{display:block}.req{color:var(--bad)}'
           . 'footer{color:var(--muted);font-size:12px;padding:16px 0;text-align:center}.thumb{max-width:100%;border-radius:8px}'
           . '.check{list-style:none;padding:0;margin:8px 0}.check li{padding:6px 0;border-bottom:1px solid #eee}.check li:last-child{border:0}'
           . '@media(min-width:768px){.wrap{padding:32px 16px}header h1{font-size:22px}}'
           . '</style></head><body class="nojs"><script>document.body.className="";</script><div class="wrap">'
           . '<header><h1>Azin Asgari · Kaş Ekimi Uzmanı</h1><div class="sub">Güvenli ön değerlendirme</div></header>';
    }

    function se_journey_public_shell_close()
    {
        echo '<footer>Bu sayfa yalnızca size özeldir ve süreli bir bağlantıyla açılır. Sağlık bilgileriniz şifrelenerek saklanır ve yalnızca değerlendirme amacıyla, yetkili ekip üyeleri tarafından görüntülenir.</footer>'
           . '</div></body></html>';
    }

    /** Render one questionnaire field (server-side, no JS needed). */
    function se_journey_public_field($key, array $f, $value, $masked_phone = '')
    {
        $id = 'f_' . $key;
        echo '<label class="f" for="' . $id . '">' . html_escape($f['label']) . (!empty($f['required']) ? ' <span class="req">*</span>' : '') . '</label>';
        if (!empty($f['help'])) {
            echo '<div class="help">' . html_escape($f['help']) . '</div>';
        }
        switch ($f['type']) {
            case 'readonly':
                echo '<input type="text" id="' . $id . '" value="' . html_escape($masked_phone) . '" readonly /><div class="help">WhatsApp numaranız doğrulanmıştır; değiştirilemez.</div>';
                break;
            case 'text':
                echo '<input type="text" id="' . $id . '" name="' . $key . '" maxlength="' . (int) ($f['max'] ?? 200) . '" value="' . html_escape((string) $value) . '" autocomplete="off" />';
                break;
            case 'number':
                echo '<input type="number" id="' . $id . '" name="' . $key . '" inputmode="numeric" min="' . (int) ($f['min'] ?? 0) . '" max="' . (int) ($f['max'] ?? 999) . '" value="' . html_escape((string) $value) . '" />';
                break;
            case 'textarea':
                echo '<textarea id="' . $id . '" name="' . $key . '" maxlength="' . (int) ($f['max'] ?? 1000) . '">' . html_escape((string) $value) . '</textarea>';
                break;
            case 'select':
                echo '<select id="' . $id . '" name="' . $key . '"><option value="">Seçiniz</option>';
                foreach ($f['options'] as $ok => $ol) {
                    echo '<option value="' . html_escape($ok) . '"' . ((string) $value === (string) $ok ? ' selected' : '') . '>' . html_escape($ol) . '</option>';
                }
                echo '</select>';
                break;
            case 'radio':
                foreach ($f['options'] as $ok => $ol) {
                    echo '<label class="opt"><input type="radio" name="' . $key . '" value="' . html_escape($ok) . '"' . ((string) $value === (string) $ok ? ' checked' : '') . ' /><span>' . html_escape($ol) . '</span></label>';
                }
                break;
            case 'multi':
                $sel = is_array($value) ? $value : [];
                foreach ($f['options'] as $ok => $ol) {
                    echo '<label class="opt"><input type="checkbox" name="' . $key . '[]" value="' . html_escape($ok) . '"' . (in_array($ok, $sel, true) ? ' checked' : '') . ' /><span>' . html_escape($ol) . '</span></label>';
                }
                break;
        }
    }
}

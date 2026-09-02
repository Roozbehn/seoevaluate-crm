<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * se_journey — patient-facing messaging.
 *
 * ONE DOOR. Every automated message leaves through se_journey_send(), which
 * applies the central policy in a fixed order:
 *
 *   opt-out  >  automation pause  >  brand enabled  >  sandbox allow-list  >
 *   quiet hours  >  daily frequency cap  >  service window (free-form vs
 *   APPROVED template)  >  queue (se_whatsapp outbound, idempotent)
 *
 * It never sends inline: it queues, and the existing drainer sends. Nothing
 * here can bypass the WhatsApp window rule, because the queue enforces it a
 * second time at send time.
 *
 * COPY. Patient-facing text lives in se_journey_copy_defaults() (Turkish
 * source, localisation-ready) and can be overridden per brand through the
 * versioned option `se_journey_copy_<brand>` from the admin screen; every
 * send records which copy version it used. Nothing in the copy promises a
 * result, a graft count, a price or candidacy — the CI content-safety gate
 * (scripts/tests) is the second line of defence.
 */

define('SE_JOURNEY_COPY_VERSION', 1);
define('SE_JOURNEY_DEFAULT_QUIET', '21:00-09:00');
define('SE_JOURNEY_DEFAULT_DAILY_CAP', 3);
define('SE_JOURNEY_REMINDER_HOURS', '24,72');

/* ===========================================================================
 * Copy registry
 * ======================================================================== */

function se_journey_copy_defaults()
{
    return [
        'tr' => [
            'welcome' =>
                "Merhaba {{name}} 🌸 Ben Azin Asgari Kaş Ekimi ekibinin otomatik danışmanlık asistanıyım. Kaş ekimi planlaması kişiye özel olduğu için önce kısa bir ön değerlendirme yapıyoruz. Yaklaşık 3–5 dakika süren güvenli formu doldurduktan ve kaş fotoğraflarınızı ilettikten sonra ekibimiz durumunuzu inceleyerek ücret, uygun süreç ve sonraki adımlar hakkında sizinle iletişime geçecektir.\n\nSağlık bilgileriniz özel nitelikli kişisel veridir. Formdan önce aydınlatma metnini okuyup tercihlerinizi belirtmeniz istenecektir. Bu ön değerlendirme tıbbi tanı veya kesin uygunluk kararı değildir.\n\nDevam etmek için “Değerlendirme Başlat”, bir ekip üyesiyle görüşmek için “Danışmana Bağlan”, ileti almak istemiyorsanız “İPTAL” yazabilirsiniz.",
            'welcome_buttons_prompt' => 'Nasıl devam etmek istersiniz?',
            'options_repeat' =>
                "Devam etmek için “Değerlendirme Başlat”, bir ekip üyesiyle görüşmek için “Danışmana Bağlan”, ileti almak istemiyorsanız “İPTAL” yazabilirsiniz.",
            'privacy_and_link' =>
                "Teşekkürler. Sağlık bilgileriniz özel nitelikli kişisel veridir; aydınlatma metnini ve tercihlerinizi güvenli formun ilk adımında göreceksiniz.\n\nGüvenli form bağlantınız: {{link}}\n\nBu bağlantı yalnızca size özeldir ve {{ttl}} saat geçerlidir. Form yaklaşık 3–5 dakika sürer; yarım bırakırsanız kaldığınız yerden devam edebilirsiniz.",
            'consent_gate_unavailable' =>
                "Teşekkürler. Ön değerlendirme formumuz şu anda hazırlanıyor; ekibimiz en kısa sürede sizinle bu numaradan iletişime geçecektir.",
            'consent_declined_ack' =>
                "Tercihinizi kaydettik. Sağlık bilgisi paylaşmadan da ekibimizle genel bilgi için görüşebilirsiniz; dilerseniz “Danışmana Bağlan” yazabilirsiniz.",
            'photos_request' =>
                "Teşekkür ederiz. Ön değerlendirmenin tamamlanabilmesi için lütfen makyajsız, filtresiz ve gün ışığına yakın aydınlık bir ortamda çekilmiş fotoğraflarınızı gönderin:\n\n1. İki kaşın birlikte göründüğü tam karşıdan fotoğraf\n2. Sol kaş yakın plan\n3. Sağ kaş yakın plan\n4. Gerekirse ekibimizin isteyeceği donör alan (başın arka kısmı) fotoğrafı\n\nFotoğrafları WhatsApp üzerinden gönderebilir veya güvenli yükleme bağlantısını kullanabilirsiniz: {{link}}\n\nFotoğraflar yalnızca değerlendirme amacıyla işlenecektir; tanıtım/paylaşım izni bundan ayrıdır.",
            'photos_partial_ack' =>
                "Teşekkürler, {{n}} fotoğraf alındı. Değerlendirme için toplam 3 fotoğraf gerekiyor (tam karşıdan, sol kaş, sağ kaş). Kalan fotoğrafları gönderebilirsiniz.",
            'photos_received_ack' =>
                "Fotoğraflarınız alındı, teşekkür ederiz. Ekibimiz ön değerlendirmeyi tamamladığında sizinle iletişime geçecektir.",
            'photos_no_consent' =>
                "Fotoğrafınız için teşekkürler. Fotoğrafları değerlendirme amacıyla işleyebilmemiz için önce güvenli formdaki tercihlerinizi tamamlamanız gerekiyor: {{link}}",
            'photo_retake' =>
                "Teşekkürler. Değerlendirmeyi tamamlayabilmemiz için {{which}} fotoğrafını yeniden göndermenizi rica ediyoruz. {{reason}}",
            'donor_request' =>
                "Ekibimiz değerlendirme için donör alanın (başın arka kısmı) bir fotoğrafını da rica ediyor. Lütfen saçları hafifçe ayırarak, aydınlık bir ortamda çekip gönderin.",
            'more_info_request' =>
                "Merhaba {{name}}, değerlendirmeyi tamamlayabilmemiz için ekibimizin birkaç ek bilgiye ihtiyacı var. Danışmanınız sizinle bu mesaj üzerinden iletişime geçecektir.",
            'evaluation_ready' =>
                "Merhaba {{name}}, ön değerlendirme bilgileriniz ekibimiz tarafından incelendi. Size özel değerlendirme sonucu ve teklifiniz hazır. Ayrıntıları güvenli bağlantıdan görüntüleyebilirsiniz: {{link}}\n\nBu ön değerlendirme, kesin tıbbi uygunluk veya sonuç garantisi değildir. Kararınızı “Teklifi Kabul Et”, “Fiyat Revizyonu” veya “Danışmana Bağlan” seçenekleriyle iletebilirsiniz; teklifi kabul ederseniz klinikte yüz yüze ön görüşme için size uygun bir tarihi takvimden seçebileceksiniz.",
            'quote_options' =>
                "Teklifinizle ilgili kararınızı aşağıdaki seçeneklerden biriyle iletebilirsiniz. Sorunuz varsa danışmanımız bu mesaj üzerinden sizinle ilgilenecektir.",
            'quote_accepted_ack' =>
                "Teşekkürler {{name}}, teklifi kabul ettiğinizi kaydettik. Klinikte yüz yüze ön görüşme için size uygun tarih ve saati güvenli bağlantıdan seçebilirsiniz: {{link}}\n\nBağlantı yalnızca size özeldir. Yardım isterseniz bu mesaja yanıt verebilirsiniz.",
            'booking_link_repeat' =>
                "Merhaba {{name}}, klinikte yüz yüze ön görüşme için size uygun tarih ve saati güvenli bağlantıdan seçebilirsiniz: {{link}}\n\nYardım isterseniz bu mesaja yanıt verebilirsiniz.",
            'quote_revision_ack' =>
                "Talebinizi aldık {{name}}. Danışmanınız teklifinizi gözden geçirip en kısa sürede bu numaradan sizinle iletişime geçecektir. Bu arada sorularınızı bu mesaja yanıt olarak yazabilirsiniz.",
            'consultation_confirmation' =>
                "Merhaba {{name}}, {{when}} tarihli {{format}} görüşmeniz oluşturuldu. Değişiklik veya iptal için bu mesaja yanıt verebilirsiniz.",
            'consultation_reminder' =>
                "Merhaba {{name}}, {{when}} tarihli görüşmenizi hatırlatırız. Katılamayacaksanız lütfen bu mesaja yanıt verin.",
            'procedure_confirmation' =>
                "Merhaba {{name}}, işleminiz {{when}} tarihine planlandı. Hazırlık bilgilerini ekibimiz sizinle paylaşacaktır. Sorularınız için bu mesaja yanıt verebilirsiniz.",
            'preop_information' =>
                "Merhaba {{name}}, işlem öncesi bilgilendirme: {{link}}\n\nİlaç kullanımıyla ilgili her türlü değişiklik yalnızca ekibimizin size özel yönlendirmesiyle yapılmalıdır.",
            'aftercare_checkin' =>
                "Merhaba {{name}}, işleminizin {{day}}. günündeyiz. Nasıl hissediyorsunuz? Lütfen kısaca yazın; ağrı, şişlik, kızarıklık veya başka bir durum varsa belirtin.\n\nŞiddetli ya da hızla kötüleşen bir şikâyetiniz olursa lütfen beklemeden 112'yi arayın.",
            'followup_photo_request' =>
                "Merhaba {{name}}, takip için kaşlarınızın güncel fotoğraflarını rica ediyoruz (tam karşıdan, sol ve sağ yakın plan; makyajsız, aydınlık ortamda). Fotoğraflar yalnızca takip amacıyla işlenir.",
            'aftercare_thanks' =>
                "Teşekkürler, bilginizi ekibimize ilettik. Gerekirse sizinle iletişime geçeceğiz.",
            'handoff_ack' =>
                "Anlaşıldı, sizi bir ekip üyemize yönlendiriyoruz. Çalışma saatleri içinde en kısa sürede yanıt vereceğiz.",
            'urgent_ack' =>
                "Mesajınızı aldık ve ekibimize acil olarak ilettik. Şiddetli veya hızla kötüleşen bir durum yaşıyorsanız lütfen beklemeden 112'yi arayın ya da en yakın acil servise başvurun.",
            'optout_confirm' =>
                "Talebiniz alındı; size otomatik mesaj göndermeyeceğiz. Yeniden iletişim kurmak isterseniz bu numaraya yazmanız yeterlidir.",
            'intake_reminder_1' =>
                "Merhaba {{name}}, kaş ekimi ön değerlendirme formunuz henüz tamamlanmadı. Güvenli bağlantı üzerinden devam edebilirsiniz: {{link}}. Yardım isterseniz bu mesaja yanıt verebilirsiniz. İletişim almak istemiyorsanız İPTAL yazabilirsiniz.",
            'intake_reminder_2' =>
                "Merhaba {{name}}, ön değerlendirme formunuz için son hatırlatmamız: {{link}}. Devam etmek istemezseniz herhangi bir işlem yapmanıza gerek yok; İPTAL yazarak iletişimi durdurabilirsiniz.",
            'photos_reminder_1' =>
                "Merhaba {{name}}, ön değerlendirme için kaş fotoğraflarınızı bekliyoruz. WhatsApp üzerinden gönderebilir ya da güvenli bağlantıyı kullanabilirsiniz: {{link}}. İletişim almak istemiyorsanız İPTAL yazabilirsiniz.",
            'photos_reminder_2' =>
                "Merhaba {{name}}, fotoğraflarınız için son hatırlatmamız: {{link}}. Devam etmek istemezseniz İPTAL yazmanız yeterlidir.",
            'btn_start'   => 'Değerlendirme Başlat',   // Meta reply-button titles are capped at 20 chars; the brief's 21-char label is accepted when typed
            'btn_handoff' => 'Danışmana Bağlan',
            'btn_stop'    => 'İPTAL',
            'btn_quote_accept' => 'Teklifi Kabul Et',
            'btn_quote_revise' => 'Fiyat Revizyonu',
            'photo_kind_frontal' => 'tam karşıdan (iki kaş)',
            'photo_kind_left'    => 'sol kaş yakın plan',
            'photo_kind_right'   => 'sağ kaş yakın plan',
            'photo_kind_donor'   => 'donör alan (başın arka kısmı)',
            'retake_blurry'      => 'Fotoğraf net değildi; lütfen telefonu sabit tutup odaklanmasını bekleyin.',
            'retake_dark'        => 'Ortam yeterince aydınlık değildi; lütfen gün ışığına yakın bir ortamda çekin.',
            'retake_makeup'      => 'Değerlendirme için kaşların makyajsız görünmesi gerekiyor.',
            'retake_filter'      => 'Filtre veya düzenleme uygulanmış görünüyor; lütfen filtresiz gönderin.',
            'retake_angle'       => 'Açı uygun değildi; lütfen kameraya tam karşıdan, göz hizasından bakarak çekin.',
            'retake_crop'        => 'Kaşların tamamı görünmüyordu; lütfen her iki kaşın tam göründüğünden emin olun.',
            'retake_other'       => 'Ekibimiz daha net bir fotoğraf rica ediyor.',
        ],
    ];
}

/** Resolve one copy string (brand override > default). Placeholders {{key}}. */
function se_journey_copy($brand_id, $key, array $vars = [], $lang = 'tr')
{
    $defaults = se_journey_copy_defaults();
    $lang = isset($defaults[$lang]) ? $lang : 'tr';
    $text = $defaults[$lang][$key] ?? '';

    $override = json_decode((string) get_option('se_journey_copy_' . (int) $brand_id), true);
    if (is_array($override) && isset($override[$lang][$key]) && trim((string) $override[$lang][$key]) !== '') {
        $text = (string) $override[$lang][$key];
    }

    $vars += ['name' => '', 'link' => '', 'ttl' => (string) se_journey_intake_ttl_hours()];
    foreach ($vars as $k => $v) {
        $text = str_replace('{{' . $k . '}}', (string) $v, $text);
    }
    // "Merhaba {{name}}" with no name -> "Merhaba" (no dangling space).
    $text = preg_replace('/Merhaba\s+(?=🌸|,|\s|$)/u', 'Merhaba ', $text);
    $text = preg_replace('/Merhaba\s{2,}/u', 'Merhaba ', $text);
    $text = str_replace('Merhaba  ', 'Merhaba ', $text);
    $text = preg_replace('/Merhaba ,/u', 'Merhaba,', $text);

    return trim($text);
}

function se_journey_copy_version($brand_id)
{
    $override = json_decode((string) get_option('se_journey_copy_' . (int) $brand_id), true);

    return is_array($override) && isset($override['version']) ? (string) $override['version'] : 'default-v' . SE_JOURNEY_COPY_VERSION;
}

/** Patient first name for greetings — from the lead, else the WhatsApp profile, else empty. */
/** First name for a template {{1}} — never empty (Meta refuses empty parameters), never "Merhaba Merhaba". */
function se_journey_template_name($j)
{
    $n = se_journey_first_name($j);

    return $n !== '' ? $n : 'değerli danışanımız';
}

function se_journey_first_name($j)
{
    $CI = &get_instance();
    $name = '';
    if ((int) $j->lead_id > 0) {
        $CI->db->select('name')->where('id', (int) $j->lead_id)->where('brand_id', (int) $j->brand_id);
        $lead = $CI->db->get(db_prefix() . 'leads')->row();
        if ($lead && strpos((string) $lead->name, 'WhatsApp ••••') !== 0) {
            $name = (string) $lead->name;
        }
    }
    if ($name === '' && !empty($j->display_name)) {
        $name = (string) $j->display_name;
    }
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($name === '') {
        return '';
    }
    $first = explode(' ', $name)[0];

    return mb_substr($first, 0, 40);
}

/* ===========================================================================
 * Policy helpers
 * ======================================================================== */

function se_journey_intake_ttl_hours()
{
    $h = (int) se_journey_config('intake_ttl_hours', 48);

    return $h > 0 ? min($h, 24 * 14) : 48;
}

function se_journey_quiet_hours()
{
    $raw = (string) se_journey_config('quiet_hours', SE_JOURNEY_DEFAULT_QUIET);
    if (!preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', trim($raw), $m)) {
        $raw = SE_JOURNEY_DEFAULT_QUIET;
        preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $raw, $m);
    }

    return ['start' => (int) $m[1] * 60 + (int) $m[2], 'end' => (int) $m[3] * 60 + (int) $m[4]];
}

/** Unix time at which a schedulable message may go out; $now when outside quiet hours. */
function se_journey_quiet_hours_release($now = null)
{
    $now = $now ?? time();
    $q   = se_journey_quiet_hours();
    if ($q['start'] === $q['end']) {
        return $now;
    }
    $minute = (int) date('G', $now) * 60 + (int) date('i', $now);
    $inQuiet = $q['start'] > $q['end']
        ? ($minute >= $q['start'] || $minute < $q['end'])      // wraps midnight (21:00-09:00)
        : ($minute >= $q['start'] && $minute < $q['end']);
    if (!$inQuiet) {
        return $now;
    }
    $release = strtotime(date('Y-m-d', $now) . ' ' . sprintf('%02d:%02d:00', intdiv($q['end'], 60), $q['end'] % 60));
    if ($release <= $now) {
        $release += 86400;
    }

    return $release;
}

function se_journey_daily_cap()
{
    $c = (int) se_journey_config('daily_cap', SE_JOURNEY_DEFAULT_DAILY_CAP);

    return $c > 0 ? $c : SE_JOURNEY_DEFAULT_DAILY_CAP;
}

/** Automated (origin journey:*) messages queued for this journey's thread in the last 24h. */
function se_journey_sent_last_24h($j)
{
    $CI = &get_instance();
    $CI->db->where('conversation_id', (int) $j->wa_conversation_id)
           ->where('brand_id', (int) $j->brand_id)
           ->where('date_created >', se_db_now(-86400));
    $n = 0;
    foreach ($CI->db->get(db_prefix() . 'se_wa_outbound')->result_array() as $r) {
        if (strpos((string) ($r['origin'] ?? ''), 'journey:') === 0 && ($r['status'] ?? '') !== 'skipped') {
            $n++;
        }
    }

    return $n;
}

/** The conversation row behind a journey (by id, else by brand + user). */
function se_journey_conversation($j)
{
    $CI = &get_instance();
    if ((int) $j->wa_conversation_id > 0) {
        $CI->db->where('id', (int) $j->wa_conversation_id)->where('brand_id', (int) $j->brand_id);
        $c = $CI->db->get(db_prefix() . 'se_wa_conversations')->row();
        if ($c) {
            return $c;
        }
    }
    $CI->db->where('brand_id', (int) $j->brand_id)->where('wa_user_id', (string) $j->wa_user_id);

    return $CI->db->get(db_prefix() . 'se_wa_conversations')->row();
}

/* ===========================================================================
 * The door
 * ======================================================================== */

/**
 * @param object $j     journey row
 * @param array  $spec  purpose, kind (text|interactive), body, buttons[], footer,
 *                      template (logical name for the out-of-window fallback),
 *                      template_vars[], correlation, bypass_pause, schedulable,
 *                      urgent, dedup_salt
 * @return array{ok:bool,mode:string,reason:string,outbound_id:int}
 */
function se_journey_send($j, array $spec)
{
    $purpose = (string) ($spec['purpose'] ?? 'message');
    $corr    = (string) ($spec['correlation'] ?? '');
    $CI      = &get_instance();

    $blocked = function ($reason) use ($j, $purpose, $corr, $CI) {
        $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys',
            ['last_send_block' => mb_substr($purpose . ':' . $reason, 0, 191), 'last_updated' => date('Y-m-d H:i:s')]);
        $j->last_send_block = $purpose . ':' . $reason;
        se_journey_event($j, 'send_blocked', $purpose . ': ' . $reason, [], 'system', null, null, null, $corr);

        return ['ok' => false, 'mode' => 'blocked', 'reason' => $reason, 'outbound_id' => 0];
    };

    /* 1. Opt-out is absolute (only the confirmation itself may follow). */
    if ((string) $j->state === 'opted_out' && $purpose !== 'optout_confirm') {
        return $blocked('opted_out');
    }
    /* 2. Paused/stopped automation: only explicit acknowledgements pass. */
    if (!se_journey_automation_active($j) && empty($spec['bypass_pause']) && $purpose !== 'optout_confirm') {
        return $blocked('automation_' . $j->automation_state);
    }
    /* 3. Brand switch. */
    if (!se_journey_enabled((int) $j->brand_id)) {
        return $blocked('journey_disabled');
    }
    /* 4. Marketing content needs marketing consent; nothing here is marketing,
     *    but the rule is enforced so a future copy key cannot slip through. */
    if (!empty($spec['marketing'])) {
        if (!function_exists('se_consent_granted') || !se_consent_granted((int) $j->brand_id, 'lead', (int) $j->lead_id, 'marketing')) {
            return $blocked('marketing_consent_missing');
        }
    }

    $conv = se_journey_conversation($j);
    if (!$conv) {
        return $blocked('no_conversation');
    }

    /* 5. Sandbox: real sends only to the allow-list; everything else recorded. */
    if (se_journey_sandbox((int) $j->brand_id) && !in_array((string) $j->wa_user_id, se_journey_test_recipients((int) $j->brand_id), true)) {
        se_journey_event($j, 'sandbox_send', $purpose . ' (sandbox — not sent)',
            ['kind' => $spec['kind'] ?? 'text', 'preview' => mb_substr((string) ($spec['body'] ?? ''), 0, 120), 'copy_version' => se_journey_copy_version((int) $j->brand_id)],
            'system', null, null, null, $corr);
        $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys', ['last_outbound_at' => date('Y-m-d H:i:s'), 'last_send_block' => null]);

        return ['ok' => true, 'mode' => 'sandbox', 'reason' => '', 'outbound_id' => 0];
    }

    /* 6/7. Quiet hours and frequency cap apply to SCHEDULED automation
     *      (reminders, aftercare), never to a direct reply to the patient. */
    $sendAfter = 0;
    if (!empty($spec['schedulable']) && empty($spec['urgent'])) {
        $sendAfter = se_journey_quiet_hours_release();
        if (se_journey_sent_last_24h($j) >= se_journey_daily_cap()) {
            return $blocked('frequency_cap');
        }
    }

    /* 8. Window. */
    $policy = function_exists('se_wa_compose_policy') ? se_wa_compose_policy($conv) : ['allowed' => false, 'mode' => 'none', 'reason' => 'no_policy'];
    if (!$policy['allowed']) {
        return $blocked($policy['reason']);
    }

    $origin = 'journey:' . mb_substr($purpose, 0, 36);
    $salt   = (string) ($spec['dedup_salt'] ?? '');

    if ($policy['mode'] === 'freeform') {
        $kind = ($spec['kind'] ?? 'text') === 'interactive' ? 'interactive' : 'text';
        // 'system': the journey resolved the thread by (id, brand) itself; the
        // queue must not apply STAFF scope — automation runs from the dispatcher
        // and the cron, where there is no staff session at all.
        $msg  = ['kind' => $kind, 'body' => (string) ($spec['body'] ?? ''), 'origin' => $origin, 'dedup_salt' => $salt, 'system' => true];
        if ($kind === 'interactive') {
            $msg['buttons'] = (array) ($spec['buttons'] ?? []);
            if (!empty($spec['footer'])) { $msg['footer'] = (string) $spec['footer']; }
        }
        if ($sendAfter > time()) {
            $msg['send_after'] = $sendAfter;
        }
        $r = se_wa_queue_message((int) $conv->id, $msg, 0);
        if (!$r['ok'] && $r['reason'] !== 'duplicate') {
            return $blocked($r['reason']);
        }
        $mode = 'inwindow';
    } else {
        /* Outside the window only an APPROVED template may go. */
        $logical = (string) ($spec['template'] ?? '');
        if ($logical === '') {
            se_journey_task($j, 'window_closed', 'Service window closed and no template defined for "' . $purpose . '" — contact manually', 'normal', null, $purpose);

            return $blocked('window_closed_no_template');
        }
        $tpl = se_journey_template_ready((int) $j->brand_id, $logical);
        if (!$tpl['ready']) {
            se_journey_task($j, 'template_blocked', 'Template "' . $logical . '" is not approved (' . $tpl['reason'] . ') — message not sent', 'normal', null, $logical);
            if ($j->automation_state === 'active') {
                se_journey_set_automation($j, 'error', 'template_unapproved:' . $logical, 'system');
            }

            return $blocked('template_' . $tpl['reason']);
        }
        $msg = ['kind' => 'template', 'template' => $tpl['meta_name'], 'variables' => array_values((array) ($spec['template_vars'] ?? [])),
                'origin' => $origin, 'dedup_salt' => $salt, 'system' => true];
        if (!empty($spec['template_quick_replies'])) {
            // Quick-reply payloads: the tap comes back as interactive_id (the
            // same ids the in-window reply buttons use), not as a label.
            $msg['quick_replies'] = array_values(array_map('strval', (array) $spec['template_quick_replies']));
        }
        if ($sendAfter > time()) {
            $msg['send_after'] = $sendAfter;
        }
        $r = se_wa_queue_message((int) $conv->id, $msg, 0);
        if (!$r['ok'] && $r['reason'] !== 'duplicate') {
            return $blocked($r['reason']);
        }
        $mode = 'template';
    }

    $now = date('Y-m-d H:i:s');
    $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys', ['last_outbound_at' => $now, 'last_send_block' => null, 'last_updated' => $now]);
    se_journey_event($j, 'wa_outbound', $purpose . ' (' . $mode . ($r['reason'] === 'duplicate' ? ', already queued' : '') . ')',
        ['copy_version' => se_journey_copy_version((int) $j->brand_id), 'deferred_to' => $sendAfter > time() ? date('Y-m-d H:i', $sendAfter) : null],
        'system', null, 'wa_outbound', (string) ($r['id'] ?? 0), $corr);

    return ['ok' => true, 'mode' => $mode, 'reason' => $r['reason'] === 'duplicate' ? 'duplicate' : '', 'outbound_id' => (int) ($r['id'] ?? 0)];
}

/** Convenience: send a registry copy key with the standard placeholders. */
function se_journey_send_copy($j, $key, array $vars = [], array $opts = [])
{
    $vars += ['name' => se_journey_first_name($j)];
    $spec = $opts + ['purpose' => $key, 'kind' => 'text'];
    $spec['body'] = se_journey_copy((int) $j->brand_id, $key, $vars, (string) $j->language);

    return se_journey_send($j, $spec);
}

/* ===========================================================================
 * Step senders
 * ======================================================================== */

function se_journey_buttons($brand_id, $lang = 'tr')
{
    return [
        ['id' => 'jr_start',   'title' => se_journey_copy($brand_id, 'btn_start', [], $lang)],
        ['id' => 'jr_handoff', 'title' => se_journey_copy($brand_id, 'btn_handoff', [], $lang)],
        ['id' => 'jr_stop',    'title' => se_journey_copy($brand_id, 'btn_stop', [], $lang)],
    ];
}

/** Interactive messages can be switched off (text fallback) per brand. */
function se_journey_interactive_enabled($brand_id)
{
    $v = get_option('se_journey_interactive_' . (int) $brand_id);

    return $v === '' ? true : (int) $v === 1;
}

/**
 * Welcome: one interactive message when the body fits Meta's 1024-char
 * interactive body limit, otherwise the full text followed by a short
 * button prompt. Text-only fallback when interactive is disabled.
 */
function se_journey_send_welcome($j, $correlation = '')
{
    $brand = (int) $j->brand_id;
    $lang  = (string) $j->language;
    $name  = se_journey_first_name($j);
    $body  = se_journey_copy($brand, 'welcome', ['name' => $name], $lang);

    // Outside the 24-hour window (an enquiry from days ago, an Instagram
    // hand-off, staff pressing Start later) only the approved start template
    // can go; it asks for a reply, which reopens the window for the buttons.
    $tpl = ['template' => 'eyebrow_journey_start_tr', 'template_vars' => [se_journey_template_name($j)]];

    if (!se_journey_interactive_enabled($brand)) {
        $r = se_journey_send($j, ['purpose' => 'welcome', 'kind' => 'text', 'body' => $body, 'correlation' => $correlation] + $tpl);
    } elseif (mb_strlen($body) <= 1024) {
        $r = se_journey_send($j, ['purpose' => 'welcome', 'kind' => 'interactive', 'body' => $body,
                                  'buttons' => se_journey_buttons($brand, $lang), 'correlation' => $correlation] + $tpl);
    } else {
        $r = se_journey_send($j, ['purpose' => 'welcome', 'kind' => 'text', 'body' => $body, 'correlation' => $correlation] + $tpl);
        if ($r['ok']) {
            se_journey_send($j, ['purpose' => 'welcome_buttons', 'kind' => 'interactive',
                'body' => se_journey_copy($brand, 'welcome_buttons_prompt', [], $lang),
                'buttons' => se_journey_buttons($brand, $lang), 'correlation' => $correlation]);
        }
    }

    if ($r['ok']) {
        $CI = &get_instance();
        $now = date('Y-m-d H:i:s');
        $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys', ['welcome_sent_at' => $now]);
        se_journey_transition($j, 'welcome_sent', 'welcome_' . $r['mode'], 'system', null, $correlation);
    }

    return $r;
}

/**
 * Privacy notice + secure intake link. Refuses (and tells the patient the
 * form is being prepared) until the counsel-approved health-data consent text
 * exists — that is the production gate on health-data collection.
 */
function se_journey_send_privacy_and_link($j, $correlation = '', $actor_type = 'patient', $actor_id = null)
{
    $brand = (int) $j->brand_id;

    if (!function_exists('se_journey_health_collection_allowed') || !se_journey_health_collection_allowed($brand)) {
        se_journey_task($j, 'consent_text_missing', 'Health-data consent text is not configured/approved — intake cannot start (Consent Settings → health_data)', 'normal', null, '');
        if ($j->automation_state === 'active') {
            se_journey_set_automation($j, 'awaiting_approval', 'health_consent_text_unconfigured', 'system');
        }
        $r = se_journey_send_copy($j, 'consent_gate_unavailable', [], ['purpose' => 'consent_gate_unavailable', 'correlation' => $correlation, 'bypass_pause' => true]);
        if ($j->state === 'welcome_sent') {
            // Stay on welcome_sent: the step has not happened.
        }

        return ['ok' => false, 'mode' => 'blocked', 'reason' => 'health_consent_text_unconfigured', 'outbound_id' => 0];
    }

    $token = se_journey_issue_token($j, 'intake', $actor_id ? (int) $actor_id : 0);
    if (!$token['ok']) {
        return ['ok' => false, 'mode' => 'blocked', 'reason' => $token['reason'], 'outbound_id' => 0];
    }
    $link = se_journey_public_url('se_journey/intake/' . $token['token']);
    $r = se_journey_send_copy($j, 'privacy_and_link', ['link' => $link], ['purpose' => 'privacy_and_link', 'correlation' => $correlation,
        'template' => 'eyebrow_intake_resume_tr', 'template_vars' => [se_journey_template_name($j), $link]]);

    if ($r['ok']) {
        if ($j->state === 'welcome_sent') {
            se_journey_transition($j, 'privacy_notice_sent', 'start_' . $actor_type, $actor_type, $actor_id, $correlation);
            se_journey_transition($j, 'consent_pending', 'link_sent', 'system', null, $correlation);
        } elseif (in_array($j->state, ['consent_pending', 'intake_started', 'intake_incomplete', 'consent_declined', 'privacy_notice_sent'], true)) {
            se_journey_transition($j, 'intake_link_sent', 'link_resent_' . $actor_type, $actor_type, $actor_id, $correlation);
            se_journey_transition($j, 'consent_pending', 'link_sent', 'system', null, $correlation);
        }
    }

    return $r;
}

/** Public base URL for patient-facing links (option override for a dedicated host). */
function se_journey_public_url($path)
{
    $base = trim((string) se_journey_config('public_base_url', ''));
    if ($base !== '' && preg_match('#^https://#i', $base)) {
        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }

    return site_url($path);
}

/** Photo request after intake submission (+ secure upload link). */
function se_journey_send_photo_request($j, $correlation = '')
{
    $token = se_journey_issue_token($j, 'upload', 0);
    $link  = $token['ok'] ? se_journey_public_url('se_journey/intake/' . $token['token'] . '/photos') : '';
    $r = se_journey_send_copy($j, 'photos_request', ['link' => $link], ['purpose' => 'photos_request', 'correlation' => $correlation,
        'template' => 'eyebrow_photos_request_tr', 'template_vars' => [se_journey_template_name($j), $link]]);
    if ($r['ok'] && $j->state === 'intake_submitted') {
        se_journey_transition($j, 'photos_requested', 'photos_requested', 'system', null, $correlation);
    }

    return $r;
}

/* ===========================================================================
 * Skipped-at-send fallback (window closed while a free-form row waited).
 * ======================================================================== */

function se_journey_on_outbound_skipped(array $row, $conv, $reason)
{
    if (strpos((string) ($row['origin'] ?? ''), 'journey:') !== 0) {
        return;
    }
    $j = se_journey_find_by_wa((int) $conv->brand_id, (string) $conv->wa_user_id);
    if (!$j) {
        return;
    }
    $purpose = substr((string) $row['origin'], 8);
    se_journey_event($j, 'send_skipped', $purpose . ': ' . $reason, [], 'system', null, 'wa_outbound', (string) $row['id']);
    se_journey_task($j, 'send_skipped', 'Automated message "' . $purpose . '" skipped: ' . $reason . ' — resend as template or contact manually', 'normal', null, (string) $row['id']);
}

/* ===========================================================================
 * Reminders — one after 24h, one final after 72h, then a staff task and stop.
 * ======================================================================== */

function se_journey_reminder_hours()
{
    $out = [];
    foreach (explode(',', (string) se_journey_config('reminder_hours', SE_JOURNEY_REMINDER_HOURS)) as $h) {
        $h = (int) trim($h);
        if ($h > 0) { $out[] = $h; }
    }
    sort($out);

    return $out ?: [24, 72];
}

/** Which journeys are waiting on the PATIENT, and which reminder copy applies. */
function se_journey_reminder_plan($state)
{
    $intake = ['consent_pending', 'intake_link_sent', 'intake_started', 'intake_incomplete'];
    $photos = ['photos_requested', 'photos_incomplete', 'photo_retake_requested'];
    if (in_array($state, $intake, true)) {
        return ['kind' => 'intake', 'copy' => ['intake_reminder_1', 'intake_reminder_2'], 'template' => 'eyebrow_intake_reminder_tr', 'exhausted_state' => 'intake_incomplete'];
    }
    if (in_array($state, $photos, true)) {
        return ['kind' => 'photos', 'copy' => ['photos_reminder_1', 'photos_reminder_2'], 'template' => 'eyebrow_photos_request_tr', 'exhausted_state' => 'photos_incomplete'];
    }

    return null;
}

/**
 * Cron: due reminders. Deduplicated by reminder_count (one row per stage),
 * capped by the configured schedule, quiet-hours and daily-cap aware via
 * se_journey_send(). $now is injectable for tests.
 */
function se_journey_run_reminders($now = null, $limit = 100)
{
    $now = $now ?? time();
    $CI  = &get_instance();
    $hours = se_journey_reminder_hours();
    $queued = 0;

    $CI->db->where('automation_state', 'active')->order_by('id', 'ASC')->limit(max(1, (int) $limit));
    foreach ($CI->db->get(db_prefix() . 'se_journeys')->result_array() as $row) {
        $plan = se_journey_reminder_plan((string) $row['state']);
        if (!$plan) {
            continue;
        }
        $anchor = strtotime((string) ($row['state_changed_at'] ?: $row['last_updated']));
        $lastActivity = max((int) $anchor, (int) strtotime((string) (($row['latest_touch_at'] ?? '') ?: '0')), (int) strtotime((string) (($row['last_reminder_at'] ?? '') ?: '0')));
        $count = (int) $row['reminder_count'];

        if ($count >= count($hours)) {
            continue;   // exhausted (task already created)
        }
        if ($now - $lastActivity < $hours[$count] * 3600) {
            continue;
        }

        $j = se_journey_get_raw((int) $row['id']);
        $copyKey = $plan['copy'][min($count, count($plan['copy']) - 1)];
        $link = '';
        if ($plan['kind'] === 'intake') {
            $t = se_journey_issue_token($j, 'intake', 0, true);
            $link = $t['ok'] ? se_journey_public_url('se_journey/intake/' . $t['token']) : '';
        } else {
            $t = se_journey_issue_token($j, 'upload', 0, true);
            $link = $t['ok'] ? se_journey_public_url('se_journey/intake/' . $t['token'] . '/photos') : '';
        }
        $r = se_journey_send_copy($j, $copyKey, ['link' => $link], ['purpose' => 'reminder_' . ($count + 1), 'schedulable' => true,
            'template' => $plan['template'], 'template_vars' => [se_journey_template_name($j), $link], 'dedup_salt' => 'r' . ($count + 1)]);

        // Count the attempt whether it went out, was sandboxed, or was blocked:
        // a blocked reminder must not be retried every cron tick (loop).
        $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys',
            ['reminder_count' => $count + 1, 'last_reminder_at' => date('Y-m-d H:i:s', $now), 'last_updated' => date('Y-m-d H:i:s', $now)]);
        if ($r['ok']) {
            $queued++;
        }

        if ($count + 1 >= count($hours)) {
            // Final reminder consumed: hand over to staff and stop nudging.
            se_journey_task($j, 'reminders_exhausted', 'No response after the final reminder — decide: close, call, or wait', 'normal', null, '');
            if (se_journey_transition_allowed((string) $j->state, $plan['exhausted_state'])) {
                se_journey_transition($j, $plan['exhausted_state'], 'reminders_exhausted', 'system');
            }
        }
    }

    return $queued;
}

/* ===========================================================================
 * Logical template registry
 * ======================================================================== */

/** The message definitions submitted to Meta (Turkish first; localisation-ready). */
function se_journey_template_definitions()
{
    return [
        'eyebrow_journey_start_tr' => [
            // Welcome for an enquiry whose 24-hour window has closed (an older
            // "details/price" message, an Instagram hand-off, staff pressing Start
            // the next day). Asks the person to reply, which reopens the window;
            // the normal button/privacy/link flow then runs in-window.
            'category' => 'UTILITY', 'language' => 'tr',
            'body' => 'Merhaba {{1}}, kaş ekimi hakkında bilgi ve fiyat talebiniz için teşekkür ederiz. Kişiye özel ön değerlendirmeye başlamak için bu mesaja "Değerlendirme Başlat" yazmanız yeterli; doğrudan danışmanımızla görüşmek isterseniz "Danışmana Bağlan" yazabilirsiniz. İletişim almak istemiyorsanız İPTAL yazabilirsiniz.',
            'samples' => ['Ayşe'],
        ],
        'eyebrow_intake_resume_tr' => [
            'category' => 'UTILITY', 'language' => 'tr',
            'body' => 'Merhaba {{1}}, kaş ekimi ön değerlendirme formunuz henüz tamamlanmadı. Güvenli bağlantı üzerinden devam edebilirsiniz: {{2}}. Yardım isterseniz bu mesaja yanıt verebilirsiniz. İletişim almak istemiyorsanız İPTAL yazabilirsiniz.',
            'samples' => ['Ayşe', 'https://crm.example.com/se_journey/intake/abc'],
        ],
        'eyebrow_intake_reminder_tr' => [
            'category' => 'UTILITY', 'language' => 'tr',
            'body' => 'Merhaba {{1}}, ön değerlendirme formunuz için son hatırlatmamız: {{2}}. Devam etmek istemezseniz herhangi bir işlem yapmanıza gerek yok; İPTAL yazarak iletişimi durdurabilirsiniz.',
            'samples' => ['Ayşe', 'https://crm.example.com/se_journey/intake/abc'],
        ],
        'eyebrow_photos_request_tr' => [
            'category' => 'UTILITY', 'language' => 'tr',
            'body' => 'Merhaba {{1}}, ön değerlendirme için kaş fotoğraflarınızı bekliyoruz: tam karşıdan, sol kaş ve sağ kaş yakın plan (makyajsız, filtresiz, aydınlık ortam). Güvenli yükleme bağlantısı: {{2}}. İletişim almak istemiyorsanız İPTAL yazabilirsiniz.',
            'samples' => ['Ayşe', 'https://crm.example.com/se_journey/intake/abc/photos'],
        ],
        'eyebrow_photos_retake_tr' => [
            // v2: Meta refused v1 ("Invalid parameter") — four variables in a short
            // body, one of them the last token. Now three, with a closing sentence.
            'category' => 'UTILITY', 'language' => 'tr',
            'body' => 'Merhaba {{1}}, ön değerlendirmeyi tamamlayabilmemiz için bir fotoğrafı yeniden göndermenizi rica ediyoruz. İstenen fotoğraf ve not: {{2}}. Fotoğrafı bu mesaja yanıt olarak ya da güvenli yükleme bağlantısından gönderebilirsiniz: {{3}}. Yardım isterseniz bu mesaja yanıt verebilirsiniz; iletişim almak istemiyorsanız İPTAL yazabilirsiniz.',
            'samples' => ['Ayşe', 'sol kaş yakın plan — fotoğraf net değildi, lütfen odaklanmasını bekleyin', 'https://crm.example.com/se_journey/intake/abc/photos'],
            'content_version' => 2,
        ],
        'eyebrow_evaluation_ready_tr' => [
            'category' => 'UTILITY', 'language' => 'tr',
            'body' => "Merhaba {{1}}, ön değerlendirme bilgileriniz ekibimiz tarafından incelendi. Size özel değerlendirme sonucu ve teklifiniz hazır. Ayrıntıları güvenli bağlantıdan görüntüleyebilirsiniz: {{2}}\n\nBu ön değerlendirme, kesin tıbbi uygunluk veya sonuç garantisi değildir. Sorularınız için bu mesaja yanıt verebilir ya da danışmanınızla görüşebilirsiniz.",
            'samples' => ['Ayşe', 'https://crm.example.com/se_journey/quote/abc'],
        ],
        'eyebrow_quote_ready_tr' => [
            // The quote, with the three answers as quick-reply buttons, for a
            // window that has closed by the time the review is done (the
            // usual case). Preferred over eyebrow_evaluation_ready_tr once
            // Meta approves it; a tap reopens the window for the follow-ups.
            'category' => 'UTILITY', 'language' => 'tr',
            'body' => "Merhaba {{1}}, ön değerlendirme bilgileriniz ekibimiz tarafından incelendi. Size özel değerlendirme sonucu ve teklifiniz hazır; ayrıntıları güvenli bağlantıdan görüntüleyebilirsiniz: {{2}}\n\nBu ön değerlendirme, kesin tıbbi uygunluk veya sonuç garantisi değildir. Kararınızı aşağıdaki seçeneklerden biriyle iletebilirsiniz; teklifi kabul ederseniz klinikte yüz yüze ön görüşme için size uygun bir tarihi seçebileceksiniz.",
            'samples' => ['Ayşe', 'https://crm.example.com/se_journey/intake/abc/quote'],
            'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Teklifi Kabul Et', 'payload' => 'jr_quote_accept'],
                ['type' => 'QUICK_REPLY', 'text' => 'Fiyat Revizyonu', 'payload' => 'jr_quote_revise'],
                ['type' => 'QUICK_REPLY', 'text' => 'Danışmana Bağlan', 'payload' => 'jr_handoff'],
            ],
        ],
        'eyebrow_booking_link_tr' => [
            // The calendar link (face-to-face consultation) when the window has
            // closed: a repeat requested days later, or staff sending it by hand.
            'category' => 'UTILITY', 'language' => 'tr',
            'body' => 'Merhaba {{1}}, klinikte yüz yüze ön görüşme için size uygun tarih ve saati güvenli bağlantıdan seçebilirsiniz: {{2}}. Bağlantı yalnızca size özeldir; yardım isterseniz bu mesaja yanıt verebilirsiniz.',
            'samples' => ['Ayşe', 'https://crm.example.com/se_journey/intake/abc/book'],
        ],
        'eyebrow_consultation_confirmation_tr' => [
            'category' => 'UTILITY', 'language' => 'tr',
            'body' => 'Merhaba {{1}}, {{2}} tarihli {{3}} görüşmeniz oluşturuldu. Değişiklik veya iptal için bu mesaja yanıt verebilirsiniz.',
            'samples' => ['Ayşe', '12.09.2026 14:00', 'online'],
        ],
        'eyebrow_consultation_reminder_tr' => [
            'category' => 'UTILITY', 'language' => 'tr',
            'body' => 'Merhaba {{1}}, {{2}} tarihli görüşmenizi hatırlatırız. Katılamayacaksanız lütfen bu mesaja yanıt verin.',
            'samples' => ['Ayşe', '12.09.2026 14:00'],
        ],
        'eyebrow_procedure_confirmation_tr' => [
            'category' => 'UTILITY', 'language' => 'tr',
            'body' => 'Merhaba {{1}}, işleminiz {{2}} tarihine planlandı. Hazırlık bilgilerini ekibimiz sizinle paylaşacaktır. Sorularınız için bu mesaja yanıt verebilirsiniz.',
            'samples' => ['Ayşe', '20.09.2026 10:00'],
        ],
        'eyebrow_preop_information_tr' => [
            'category' => 'UTILITY', 'language' => 'tr',
            'body' => 'Merhaba {{1}}, işlem öncesi bilgilendirmenize güvenli bağlantıdan ulaşabilirsiniz: {{2}}. İlaç kullanımıyla ilgili her değişiklik yalnızca ekibimizin size özel yönlendirmesiyle yapılmalıdır.',
            'samples' => ['Ayşe', 'https://crm.example.com/se_journey/info/abc'],
        ],
        'eyebrow_aftercare_checkin_tr' => [
            'category' => 'UTILITY', 'language' => 'tr',
            'body' => "Merhaba {{1}}, işleminizin {{2}}. günündeyiz. Nasıl hissediyorsunuz? Lütfen kısaca yazın; ağrı, şişlik, kızarıklık veya başka bir durum varsa belirtin. Şiddetli ya da hızla kötüleşen bir şikâyetiniz olursa lütfen beklemeden 112'yi arayın.",
            'samples' => ['Ayşe', '3'],
        ],
        'eyebrow_followup_photo_request_tr' => [
            'category' => 'UTILITY', 'language' => 'tr',
            'body' => 'Merhaba {{1}}, takip için kaşlarınızın güncel fotoğraflarını rica ediyoruz (tam karşıdan, sol ve sağ yakın plan; makyajsız, aydınlık ortam). Güvenli yükleme bağlantısı: {{2}}. Fotoğraflar yalnızca takip amacıyla işlenir.',
            'samples' => ['Ayşe', 'https://crm.example.com/se_journey/intake/abc/photos'],
        ],
    ];
}

/** A short signature of the shipped definitions (logical names + content versions). */
function se_journey_template_registry_signature()
{
    $parts = [];
    foreach (se_journey_template_definitions() as $name => $d) {
        $parts[] = $name . ':' . (int) ($d['content_version'] ?? 1);
    }

    return 'r' . substr(hash('sha256', implode(',', $parts)), 0, 16);
}

/** Seed/refresh the registry rows for a brand (idempotent; never downgrades a Meta status). */
function se_journey_seed_templates($brand_id)
{
    $CI = &get_instance();
    $t  = db_prefix() . 'se_journey_templates';
    $now = date('Y-m-d H:i:s');
    $n = 0;
    foreach (se_journey_template_definitions() as $name => $d) {
        $version = (int) ($d['content_version'] ?? 1);
        $CI->db->where('brand_id', (int) $brand_id)->where('logical_name', $name)->where('language', $d['language']);
        $row = $CI->db->get($t)->row();
        if ($row) {
            // A newer definition replaces the registry copy only while Meta has
            // not accepted the old one (not submitted / refused / rejected):
            // an approved or pending template is what Meta holds — untouched.
            if ((int) $row->content_version < $version
                && in_array((string) $row->approval_status, ['not_submitted', 'submit_failed', 'rejected'], true)) {
                $CI->db->where('id', (int) $row->id)->update($t, [
                    'body' => $d['body'], 'placeholders_json' => json_encode($d['samples']), 'content_version' => $version,
                    'buttons_json' => !empty($d['buttons']) ? json_encode(array_values($d['buttons']), JSON_UNESCAPED_UNICODE) : null,
                    'approval_status' => 'not_submitted', 'rejection_reason' => null, 'meta_template_id' => null,
                    'category_meta' => null, 'submitted_at' => null, 'last_updated' => $now,
                ]);
                $n++;
            }
            continue;
        }
        $CI->db->insert($t, [
            'brand_id' => (int) $brand_id, 'logical_name' => $name, 'language' => $d['language'],
            'category_requested' => $d['category'], 'meta_name' => $name, 'content_version' => $version,
            'body' => $d['body'], 'placeholders_json' => json_encode($d['samples']),
            'buttons_json' => !empty($d['buttons']) ? json_encode(array_values($d['buttons']), JSON_UNESCAPED_UNICODE) : null,
            'approval_status' => 'not_submitted', 'fallback' => 'staff_task', 'date_created' => $now,
        ]);
        $n++;
    }

    return $n;
}

/**
 * Is the logical template usable RIGHT NOW? Both the registry (what we
 * submitted) and the WABA mirror (what Meta actually holds) must say
 * approved. Never assumes.
 */
function se_journey_template_ready($brand_id, $logical)
{
    $CI = &get_instance();
    $CI->db->where('brand_id', (int) $brand_id)->where('logical_name', (string) $logical);
    $row = $CI->db->get(db_prefix() . 'se_journey_templates')->row();
    if (!$row) {
        return ['ready' => false, 'reason' => 'not_registered', 'meta_name' => ''];
    }
    if ((string) $row->approval_status !== 'approved') {
        return ['ready' => false, 'reason' => (string) $row->approval_status, 'meta_name' => (string) $row->meta_name];
    }
    $mirror = false;
    if (function_exists('se_wa_approved_templates')) {
        foreach (se_wa_approved_templates((int) $brand_id) as $t) {
            if ($t['name'] === (string) $row->meta_name) { $mirror = true; break; }
        }
    }
    if (!$mirror) {
        return ['ready' => false, 'reason' => 'not_in_waba_mirror', 'meta_name' => (string) $row->meta_name];
    }

    return ['ready' => true, 'reason' => '', 'meta_name' => (string) $row->meta_name];
}

/** Reconcile registry rows with the WABA mirror (after a template sync or status webhook). */
function se_journey_sync_template_status($brand_id)
{
    $CI = &get_instance();
    $CI->db->where('brand_id', (int) $brand_id);
    $mirror = [];
    foreach ($CI->db->get(db_prefix() . 'se_wa_templates')->result_array() as $m) {
        $mirror[$m['name']] = $m;
    }
    $CI->db->where('brand_id', (int) $brand_id);
    $rows = $CI->db->get(db_prefix() . 'se_journey_templates')->result_array();
    $now = date('Y-m-d H:i:s');
    $updated = 0;
    foreach ($rows as $r) {
        if (!isset($mirror[$r['meta_name']])) {
            continue;
        }
        $m = $mirror[$r['meta_name']];
        $status = strtolower((string) ($m['approval_state'] ?? ''));
        $map = ['approved' => 'approved', 'pending' => 'pending', 'in_review' => 'pending', 'rejected' => 'rejected',
                'paused' => 'paused', 'disabled' => 'disabled'];
        $new = $map[$status] ?? ($status !== '' ? $status : 'pending');
        $CI->db->where('id', (int) $r['id'])->update(db_prefix() . 'se_journey_templates', [
            'approval_status' => $new,
            // The mirror does not always carry a category; keep the one Meta
            // returned at submission rather than blanking it.
            'category_meta' => !empty($m['category']) ? mb_substr((string) $m['category'], 0, 24) : ($r['category_meta'] ?? null),
            'last_sync_at' => $now, 'last_updated' => $now,
        ]);
        $updated++;
    }

    return $updated;
}

$GLOBALS['SE_JOURNEY_TEMPLATE_SUBMITTER'] = $GLOBALS['SE_JOURNEY_TEMPLATE_SUBMITTER'] ?? null;

/** Seam: callable(string $waba_id, array $definition): array{ok,id,status,category,error}. */
function se_journey_register_template_submitter(callable $f)
{
    $GLOBALS['SE_JOURNEY_TEMPLATE_SUBMITTER'] = $f;
}

/**
 * Submit one logical template to Meta. Gated on the Cloud API token and a
 * known WABA; the response status is stored verbatim — "approved" is never
 * written here.
 */
function se_journey_submit_template($brand_id, $logical, $staff_id = 0)
{
    $CI = &get_instance();
    $CI->db->where('brand_id', (int) $brand_id)->where('logical_name', (string) $logical);
    $row = $CI->db->get(db_prefix() . 'se_journey_templates')->row();
    if (!$row) {
        return ['ok' => false, 'reason' => 'not_registered'];
    }
    $waba = function_exists('se_wa_waba_for_brand') ? (string) se_wa_waba_for_brand($brand_id) : '';
    if ($waba === '') {
        return ['ok' => false, 'reason' => 'no_waba'];
    }
    if (!is_callable($GLOBALS['SE_JOURNEY_TEMPLATE_SUBMITTER'] ?? null)) {
        if (function_exists('se_wa_cloud_token') && se_wa_cloud_token() !== '') {
            se_journey_register_template_submitter('se_journey_graph_submit_template');
        } else {
            return ['ok' => false, 'reason' => 'no_token'];
        }
    }
    $definition = se_journey_template_meta_definition($row);
    try {
        $r = call_user_func($GLOBALS['SE_JOURNEY_TEMPLATE_SUBMITTER'], $waba, $definition);
    } catch (Throwable $e) {
        return ['ok' => false, 'reason' => 'submit_error'];
    }
    $now = date('Y-m-d H:i:s');
    if (empty($r['ok'])) {
        $CI->db->where('id', (int) $row->id)->update(db_prefix() . 'se_journey_templates', [
            'approval_status' => 'submit_failed', 'rejection_reason' => mb_substr((string) ($r['error'] ?? 'submit failed'), 0, 500),
            'last_sync_at' => $now, 'last_updated' => $now,
        ]);
        se_journey_audit($brand_id, 0, 'template_submit_failed', 'template', (string) $logical, mb_substr((string) ($r['error'] ?? ''), 0, 120));

        return ['ok' => false, 'reason' => mb_substr((string) ($r['error'] ?? 'submit failed'), 0, 120)];
    }
    $status = strtolower((string) ($r['status'] ?? 'pending'));
    $CI->db->where('id', (int) $row->id)->update(db_prefix() . 'se_journey_templates', [
        'meta_template_id' => mb_substr((string) ($r['id'] ?? ''), 0, 64),
        'category_meta'    => isset($r['category']) ? mb_substr((string) $r['category'], 0, 24) : null,
        'approval_status'  => $status === 'approved' ? 'approved' : ($status ?: 'pending'),
        'submitted_at'     => $now, 'last_sync_at' => $now, 'last_updated' => $now,
    ]);
    se_journey_audit($brand_id, 0, 'template_submitted', 'template', (string) $logical, 'status=' . $status);

    return ['ok' => true, 'reason' => '', 'status' => $status];
}

/**
 * The Meta message-template definition for a registry row: BODY with the
 * sample values, plus a BUTTONS component when the definition carries
 * quick-reply buttons (payloads are a send-time concern, not part of the
 * template).
 */
function se_journey_template_meta_definition($row)
{
    $components = [[
        'type' => 'BODY', 'text' => (string) $row->body,
        'example' => ['body_text' => [array_values(json_decode((string) $row->placeholders_json, true) ?: [])]],
    ]];
    $buttons = json_decode((string) ($row->buttons_json ?? ''), true);
    if (is_array($buttons) && $buttons) {
        $list = [];
        foreach ($buttons as $b) {
            $text = trim((string) ($b['text'] ?? ''));
            if ($text === '') { continue; }
            $list[] = ['type' => 'QUICK_REPLY', 'text' => mb_substr($text, 0, 25)];   // only quick replies are used (URL/phone buttons are not)
        }
        if ($list) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $list];
        }
    }

    return [
        'name'       => (string) $row->meta_name,
        'language'   => (string) $row->language,
        'category'   => (string) $row->category_requested,
        'components' => $components,
    ];
}

/** Live Graph submitter: POST /{waba}/message_templates. Token in a header only. */
function se_journey_graph_submit_template($waba_id, array $definition)
{
    $token = se_wa_cloud_token();
    if ($token === '') {
        return ['ok' => false, 'error' => 'no cloud api token'];
    }
    $version = get_option('se_meta_graph_version') ?: 'v23.0';
    $ch = curl_init('https://graph.facebook.com/' . $version . '/' . rawurlencode($waba_id) . '/message_templates');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($definition), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['ok' => false, 'error' => 'network error'];
    }
    $body = json_decode((string) $raw, true) ?: [];
    if ($code >= 200 && $code < 300 && !empty($body['id'])) {
        return ['ok' => true, 'id' => (string) $body['id'], 'status' => (string) ($body['status'] ?? 'PENDING'), 'category' => (string) ($body['category'] ?? '')];
    }

    $err = (array) ($body['error'] ?? []);
    $detail = trim((string) ($err['error_user_msg'] ?? ''));
    $msg = (string) ($err['message'] ?? ('http ' . $code))
         . (!empty($err['error_subcode']) ? ' [' . (int) $err['error_subcode'] . ']' : '')
         . ($detail !== '' ? ' — ' . $detail : '');

    return ['ok' => false, 'error' => mb_substr($msg, 0, 300)];
}

/* ===========================================================================
 * Staff alerting for urgent cases
 * ======================================================================== */

function se_journey_notify_urgent($j, $correlation = '')
{
    $targets = [];
    if ((int) $j->assigned_staff > 0) {
        $targets[] = (int) $j->assigned_staff;
    }
    foreach (preg_split('/[\s,;]+/', (string) se_journey_config('urgent_staff_ids', '')) as $id) {
        if ((int) $id > 0) { $targets[] = (int) $id; }
    }
    $targets = array_values(array_unique($targets));
    if (!$targets) {
        // Fall back to every admin so an urgent report never lands nowhere.
        $CI = &get_instance();
        $CI->db->select('staffid')->where('admin', 1)->where('active', 1);
        foreach ($CI->db->get(db_prefix() . 'staff')->result_array() as $s) {
            $targets[] = (int) $s['staffid'];
        }
    }
    foreach ($targets as $staff) {
        if (function_exists('add_notification')) {
            add_notification([
                'description'     => 'se_journey_urgent_notification',
                'touserid'        => $staff,
                'fromcompany'     => true,
                'link'            => 'se_journey/se_journey/view/' . (int) $j->id,
                'additional_data' => serialize(['#' . (int) $j->id]),
            ]);
        }
    }
    se_journey_event($j, 'urgent_alerted', 'staff alerted: ' . count($targets), ['staff' => $targets], 'system', null, null, null, $correlation);

    return count($targets);
}

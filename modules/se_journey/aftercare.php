<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * se_journey — aftercare and follow-up.
 *
 * The PLAN is data, not code: a protocol (key, version, approved flag, ordered
 * steps with offsets and kinds) lives in the option
 * `se_journey_aftercare_protocols_<brand>` and is edited by authorised staff.
 * The default protocol ships with the intervals from the brief and NO
 * medical instruction text — instruction steps send nothing until the
 * protocol is marked approved by the clinic; until then they open staff
 * tasks so nobody is forgotten.
 *
 * Check-in replies are health data: sealed like intake answers. Urgent
 * keywords are handled upstream (helpers.php) before any of this runs.
 */

function se_journey_aftercare_default_protocol()
{
    $g = '{{link}}';
    return [
        'key' => 'standard', 'version' => '2', 'approved' => 0, 'name' => 'Standart kaş ekimi bakım takvimi (v2, onay bekliyor)',
        'steps' => [
            ['key' => 'day0',    'label' => 'İlk 48 saat',          'offset_hours' => 6,    'kind' => 'instruction',   'template' => 'eyebrow_aftercare_day0_tr',
             'text' => "Merhaba {{name}}, ilk 48 saat için hatırlatma:\n• Kaş bölgesine dokunmayın, yıkamayın, hiçbir şey sürmeyin.\n• İlk 3–4 gece başınız yüksekte (yaklaşık 45°) ve sırtüstü uyuyun; yüzüstü yatmayın.\n• Şişlik için soğuk kompresi alın ve şakaklara uygulayın, kaşların üzerine değil.\n• Verilen spreyi tarif edilen sıklıkta kullanın.\n• Alkol, sigara, aspirin/ibuprofen türü ağrı kesiciler ve ağır efor yok.\n• Donör bölgede hafif sızıntı olursa temiz bir bezle 15–20 dakika baskı uygulayın.\nTakvimin tamamı: $g"],
            ['key' => 'day1',    'label' => '1. gün',               'offset_hours' => 24,   'kind' => 'instruction',   'template' => 'eyebrow_aftercare_day1_tr',
             'text' => "Merhaba {{name}}, işlemin 1. günü. Hafif şişlik ve kızarıklık normaldir; şişlik göz kapaklarına inebilir. Soğuk kompres ve sprey ile devam edin, kaşlara dokunmayın. Yarın nasıl olduğunuzu soracağız. Artan ağrı, yayılan kızarıklık, akıntı veya ateş olursa beklemeden yazın; bize ulaşamıyorsanız en yakın sağlık kuruluşuna başvurun. Takvim: $g"],
            ['key' => 'day2',    'label' => '2. gün',               'offset_hours' => 48,   'kind' => 'checkin',       'template' => 'eyebrow_aftercare_checkin_tr'],
            ['key' => 'day3',    'label' => '3. gün · ilk yıkama',  'offset_hours' => 72,   'kind' => 'instruction',   'template' => 'eyebrow_aftercare_day3_tr',
             'text' => "Merhaba {{name}}, bugün 3. gün: ilk yıkama günü. İlk yıkamayı klinikte birlikte yapıyoruz ya da size gösterildiği gibi, avuçla ve basınç uygulamadan yapın; kâğıt havluyla kurulayın, ovalamayın. Kabuklar 7–10. gün arasında kendiliğinden kalkar; koparmayın. Makyaj, kaş kalemi ve serum kaş bölgesine henüz sürülmez. Takvim: $g"],
            ['key' => 'day7',    'label' => '7. gün · kabuklar',    'offset_hours' => 168,  'kind' => 'instruction',   'template' => 'eyebrow_aftercare_day7_tr',
             'text' => "Merhaba {{name}}, 7. gün. Kabuklar bu hafta kendiliğinden dökülür; ovalamayın. Hafif yürüyüş serbest; terleten spor, sauna, hamam, havuz ve deniz 14. güne kadar yok. Güneşte 10 dakikadan uzun kalacaksanız şapka kullanın, gözlüğün kaşa değmemesine dikkat edin. Donör bölgede dikiş varsa 10–14. gün arasında klinikte alınır; kendiniz almayın. Takvim: $g"],
            ['key' => 'day10',   'label' => '10. gün · dikiş/kontrol kararı', 'offset_hours' => 240, 'kind' => 'staff_task', 'template' => '',
             'text' => 'Donör bölgede şerit (FUT) yöntemi kullanıldıysa 10–14. gün için dikiş alma randevusu planlayın; FUE ise kontrol gerekip gerekmediğine karar verin.'],
            ['key' => 'day14',   'label' => '14. gün · kontrol fotoğrafı', 'offset_hours' => 336, 'kind' => 'photo_request', 'template' => 'eyebrow_followup_photo_request_tr'],
            ['key' => 'day21',   'label' => '3. hafta · dökülme',   'offset_hours' => 504,  'kind' => 'instruction',   'template' => 'eyebrow_aftercare_day21_tr',
             'text' => "Merhaba {{name}}, 3. hafta. Ekilen kılların dökülmesi bu dönemde beklenir ve 6. haftaya kadar sürebilir; kök kaybolmaz, dinlenme evresine girer. Kaş makyajı ve kaş boyası 4. haftadan sonra serbest; ağda, iplik ve cımbız kaş bölgesine 6 aydan önce uygulanmaz. Ağır ağırlık ve temas sporları 3. haftadan sonra başlar. Takvim: $g"],
            ['key' => 'month1',  'label' => '1. ay · fotoğraf',     'offset_hours' => 720,  'kind' => 'photo_request', 'template' => 'eyebrow_followup_photo_request_tr'],
            ['key' => 'month3',  'label' => '3. ay · yeni çıkışlar','offset_hours' => 2160, 'kind' => 'instruction',   'template' => 'eyebrow_aftercare_month3_tr',
             'text' => "Merhaba {{name}}, 3. ay. Yeni kıllar ince, açık renkli ve düzensiz çıkmaya başlar; bu normaldir. Ekilen kıllar saç kökü olduğu için daha hızlı ve uzun büyür: 2–4 haftada bir küçük bir makasla kaş hattına göre kısaltın. Peeling, cilt bakımı ve solaryum kaş bölgesinden uzak; dışarıda SPF 30 ve üzeri kullanın. Takvim: $g"],
            ['key' => 'month3p', 'label' => '3. ay · fotoğraf',     'offset_hours' => 2184, 'kind' => 'photo_request', 'template' => 'eyebrow_followup_photo_request_tr'],
            ['key' => 'month6t', 'label' => '6. ay · kontrol randevusu', 'offset_hours' => 4296, 'kind' => 'staff_task', 'template' => '',
             'text' => '6. ay kontrolü: hastayı arayın, mümkünse klinikte kontrol ve ilk şekillendirme kesimi için randevu verin.'],
            ['key' => 'month6',  'label' => '6. ay · fotoğraf',     'offset_hours' => 4320, 'kind' => 'photo_request', 'template' => 'eyebrow_followup_photo_request_tr'],
            ['key' => 'month12t','label' => '12. ay · son kontrol randevusu', 'offset_hours' => 8616, 'kind' => 'staff_task', 'template' => '',
             'text' => '12. ay son kontrolü: hastayı arayın, kontrol randevusu verin; ek seans gerekip gerekmediği bu kontrolde değerlendirilir.'],
            ['key' => 'month12', 'label' => '12. ay · son fotoğraf', 'offset_hours' => 8640, 'kind' => 'photo_request', 'template' => 'eyebrow_followup_photo_request_tr'],
        ],
    ];
}

/** The patient-facing bakım takvimi page for this journey's language (option override per brand). */
function se_journey_aftercare_guide_url($j)
{
    $custom = trim((string) get_option('se_journey_aftercare_guide_url_' . (int) $j->brand_id));
    if ($custom !== '' && preg_match('#^https://#i', $custom)) {
        return $custom;
    }
    $lang = strtolower(substr((string) ($j->language ?? 'tr'), 0, 2));
    if (!in_array($lang, ['tr', 'en', 'fa', 'ar'], true)) { $lang = 'tr'; }

    return 'https://azinasgari.com/' . $lang . '/recovery';
}

/** Protocols for a brand (validated); the default is always present. */
function se_journey_aftercare_protocols($brand_id)
{
    $out = ['standard' => se_journey_aftercare_default_protocol()];
    $raw = json_decode((string) get_option('se_journey_aftercare_protocols_' . (int) $brand_id), true);
    if (!is_array($raw)) {
        return $out;
    }
    foreach ($raw as $p) {
        $v = se_journey_aftercare_validate_protocol($p);
        if ($v['ok']) {
            $out[$v['protocol']['key']] = $v['protocol'];
        }
    }

    return $out;
}

function se_journey_aftercare_validate_protocol($p)
{
    if (!is_array($p) || empty($p['key']) || !preg_match('/^[a-z0-9_]{1,64}$/', (string) $p['key']) || empty($p['steps']) || !is_array($p['steps'])) {
        return ['ok' => false, 'reason' => 'invalid_protocol', 'protocol' => null];
    }
    $steps = [];
    $seen = [];
    foreach ($p['steps'] as $s) {
        if (!is_array($s) || empty($s['key']) || !preg_match('/^[a-z0-9_]{1,32}$/', (string) $s['key']) || isset($seen[$s['key']])) {
            return ['ok' => false, 'reason' => 'invalid_step', 'protocol' => null];
        }
        $kind = (string) ($s['kind'] ?? 'checkin');
        if (!in_array($kind, ['instruction', 'checkin', 'photo_request', 'staff_task'], true)) {
            return ['ok' => false, 'reason' => 'invalid_kind', 'protocol' => null];
        }
        $offset = (int) ($s['offset_hours'] ?? -1);
        if ($offset < 0 || $offset > 24 * 400) {
            return ['ok' => false, 'reason' => 'invalid_offset', 'protocol' => null];
        }
        $seen[$s['key']] = true;
        $steps[] = ['key' => (string) $s['key'], 'label' => mb_substr((string) ($s['label'] ?? $s['key']), 0, 64), 'offset_hours' => $offset, 'kind' => $kind,
                    'template' => mb_substr((string) ($s['template'] ?? ''), 0, 128), 'text' => mb_substr((string) ($s['text'] ?? ''), 0, 2000)];
    }
    usort($steps, function ($a, $b) { return $a['offset_hours'] <=> $b['offset_hours']; });

    return ['ok' => true, 'reason' => '', 'protocol' => [
        'key' => (string) $p['key'], 'version' => mb_substr((string) ($p['version'] ?? '1'), 0, 16), 'approved' => !empty($p['approved']) ? 1 : 0,
        'name' => mb_substr((string) ($p['name'] ?? $p['key']), 0, 120), 'steps' => $steps,
    ]];
}

/** Save the protocol list (authorised staff only — checked by the controller; audited here). */
function se_journey_aftercare_save_protocols($brand_id, array $protocols, $staff_id)
{
    $clean = [];
    foreach ($protocols as $p) {
        $v = se_journey_aftercare_validate_protocol($p);
        if (!$v['ok']) {
            return ['ok' => false, 'reason' => $v['reason']];
        }
        $clean[] = $v['protocol'];
    }
    update_option('se_journey_aftercare_protocols_' . (int) $brand_id, json_encode($clean, JSON_UNESCAPED_UNICODE));
    se_journey_audit((int) $brand_id, 0, 'aftercare_protocols_saved', null, null, count($clean) . ' protocol(s)');

    return ['ok' => true, 'reason' => ''];
}

/** Create the plan and its scheduled events; aftercare_active. */
function se_journey_aftercare_start($j, $protocol_key, $staff_id, $anchor_at = null)
{
    if (!in_array((string) $j->state, ['procedure_completed', 'aftercare_active', 'completed'], true)) {
        return ['ok' => false, 'reason' => 'transition_not_allowed', 'plan_id' => 0];
    }
    $protocols = se_journey_aftercare_protocols((int) $j->brand_id);
    if (!isset($protocols[$protocol_key])) {
        return ['ok' => false, 'reason' => 'unknown_protocol', 'plan_id' => 0];
    }
    $p = $protocols[$protocol_key];
    $anchor = $anchor_at && strtotime((string) $anchor_at) !== false ? strtotime((string) $anchor_at)
        : (!empty($j->procedure_at) ? strtotime((string) $j->procedure_at) : time());

    $CI  = &get_instance();
    $now = date('Y-m-d H:i:s');
    // One active plan at a time.
    $CI->db->where('journey_id', (int) $j->id)->where('state', 'active')->update(db_prefix() . 'se_journey_aftercare_plans', ['state' => 'replaced']);
    $CI->db->where('journey_id', (int) $j->id)->where('state', 'scheduled')->update(db_prefix() . 'se_journey_aftercare_events', ['state' => 'cancelled']);

    $CI->db->insert(db_prefix() . 'se_journey_aftercare_plans', [
        'journey_id' => (int) $j->id, 'brand_id' => (int) $j->brand_id, 'protocol_key' => $p['key'], 'protocol_version' => $p['version'],
        'anchor_at' => date('Y-m-d H:i:s', $anchor), 'state' => 'active', 'created_by' => (int) $staff_id, 'created_at' => $now,
    ]);
    $plan_id = (int) $CI->db->insert_id();
    foreach ($p['steps'] as $s) {
        $CI->db->insert(db_prefix() . 'se_journey_aftercare_events', [
            'plan_id' => $plan_id, 'journey_id' => (int) $j->id, 'brand_id' => (int) $j->brand_id, 'step_key' => $s['key'], 'label' => $s['label'],
            'kind' => $s['kind'], 'due_at' => date('Y-m-d H:i:s', $anchor + $s['offset_hours'] * 3600), 'template_ref' => $s['template'] ?: null,
            'state' => 'scheduled', 'created_at' => $now,
        ]);
    }
    $CI->db->where('id', (int) $j->id)->update(db_prefix() . 'se_journeys', ['aftercare_plan_id' => $plan_id, 'last_updated' => $now]);
    $j->aftercare_plan_id = $plan_id;
    if ((string) $j->state !== 'aftercare_active') {
        se_journey_transition($j, 'aftercare_active', 'aftercare_started', 'staff', $staff_id, 'plan:' . $plan_id, $p['key'] . ' v' . $p['version']);
    }
    se_journey_audit((int) $j->brand_id, (int) $j->id, 'aftercare_start', 'plan', (string) $plan_id, $p['key'] . ' v' . $p['version'] . ($p['approved'] ? '' : ' (UNAPPROVED protocol: instructions become staff tasks)'));
    if (!$p['approved']) {
        se_journey_task($j, 'protocol_unapproved', 'Aftercare protocol "' . $p['key'] . '" is not approved — instruction steps will only create tasks', 'normal', null, $p['key']);
    }

    return ['ok' => true, 'reason' => '', 'plan_id' => $plan_id];
}

function se_journey_aftercare_events($j)
{
    $CI = &get_instance();
    $CI->db->where('journey_id', (int) $j->id)->where('brand_id', (int) $j->brand_id)->order_by('due_at', 'ASC');

    return $CI->db->get(db_prefix() . 'se_journey_aftercare_events')->result_array();
}

/**
 * Cron: fire due events. Instruction steps require an APPROVED protocol;
 * everything else goes through the central policy (quiet hours, caps,
 * window/template). A check-in unanswered for 48h marks followup_due.
 */
function se_journey_run_aftercare($now = null, $limit = 100)
{
    $now = $now ?? time();
    $CI  = &get_instance();
    $fired = 0;

    $CI->db->where('state', 'scheduled')->where('due_at <=', date('Y-m-d H:i:s', $now))->order_by('due_at', 'ASC')->limit(max(1, (int) $limit));
    foreach ($CI->db->get(db_prefix() . 'se_journey_aftercare_events')->result_array() as $e) {
        $j = se_journey_get_raw((int) $e['journey_id']);
        if (!$j || !in_array((string) $j->state, ['aftercare_active', 'followup_due'], true) || (int) $j->aftercare_plan_id !== (int) $e['plan_id']) {
            $CI->db->where('id', (int) $e['id'])->update(db_prefix() . 'se_journey_aftercare_events', ['state' => 'cancelled']);
            continue;
        }
        $protocols = se_journey_aftercare_protocols((int) $j->brand_id);
        $CI->db->where('id', (int) $e['plan_id']);
        $plan = $CI->db->get(db_prefix() . 'se_journey_aftercare_plans')->row();
        $protocol = $plan && isset($protocols[$plan->protocol_key]) ? $protocols[$plan->protocol_key] : null;
        $step = null;
        if ($protocol) {
            foreach ($protocol['steps'] as $s) { if ($s['key'] === $e['step_key']) { $step = $s; break; } }
        }
        $day = max(1, (int) round((strtotime((string) $e['due_at']) - strtotime((string) $plan->anchor_at)) / 86400));
        $name = se_journey_template_name($j);
        $update = ['state' => 'sent', 'sent_at' => date('Y-m-d H:i:s', $now)];

        switch ((string) $e['kind']) {
            case 'staff_task':
                se_journey_task($j, 'aftercare_step', 'Aftercare step "' . $e['label'] . '" due' . ($step && trim((string) $step['text']) !== '' ? ' — ' . $step['text'] : ''), 'normal', $e['due_at'], $e['step_key']);
                $update['state'] = 'answered';
                break;
            case 'instruction':
                if (!$protocol || empty($protocol['approved']) || !$step || trim((string) $step['text']) === '') {
                    se_journey_task($j, 'aftercare_instruction', 'Aftercare instruction "' . $e['label'] . '" due — protocol text not approved; send manually', 'normal', $e['due_at'], $e['step_key']);
                    $update['state'] = 'skipped';
                    break;
                }
                $guide = se_journey_aftercare_guide_url($j);
                $body  = str_replace(['{{name}}', '{{link}}'], [$name, $guide], (string) $step['text']);
                $tplRef = (string) ($e['template_ref'] ?: 'eyebrow_aftercare_checkin_tr');
                // Stage templates carry (name, guide link); the generic check-in carries (name, day).
                $vars = strpos($tplRef, 'eyebrow_aftercare_checkin') === 0 ? [$name, (string) $day] : [$name, $guide];
                $r = se_journey_send($j, ['purpose' => 'aftercare_' . $e['step_key'], 'kind' => 'text', 'body' => $body, 'schedulable' => true,
                                          'template' => $tplRef, 'template_vars' => $vars, 'dedup_salt' => 'ac' . (int) $e['id']]);
                if (!$r['ok']) { $update['state'] = 'blocked'; }
                break;
            case 'photo_request':
                $t = se_journey_issue_token($j, 'upload', 0, true);
                $link = $t['ok'] ? se_journey_public_url('se_journey/intake/' . $t['token'] . '/photos') : '';
                $r = se_journey_send_copy($j, 'followup_photo_request', ['link' => $link], ['purpose' => 'aftercare_photo_' . $e['step_key'], 'schedulable' => true,
                    'template' => 'eyebrow_followup_photo_request_tr', 'template_vars' => [$name, $link], 'dedup_salt' => 'ac' . (int) $e['id']]);
                if (!$r['ok']) { $update['state'] = 'blocked'; }
                break;
            default: // checkin
                $r = se_journey_send_copy($j, 'aftercare_checkin', ['day' => (string) $day], ['purpose' => 'aftercare_checkin_' . $e['step_key'], 'schedulable' => true,
                    'template' => 'eyebrow_aftercare_checkin_tr', 'template_vars' => [$name, (string) $day], 'dedup_salt' => 'ac' . (int) $e['id']]);
                if (!$r['ok']) { $update['state'] = 'blocked'; }
                break;
        }
        if ($update['state'] === 'blocked') {
            se_journey_task($j, 'aftercare_blocked', 'Aftercare step "' . $e['label'] . '" could not be sent (' . ($r['reason'] ?? 'blocked') . ')', 'normal', null, $e['step_key']);
        }
        $CI->db->where('id', (int) $e['id'])->update(db_prefix() . 'se_journey_aftercare_events', $update);
        $fired++;
    }

    // Unanswered check-ins → followup_due (+ task), once.
    $CI->db->where('state', 'sent')->where('sent_at <=', date('Y-m-d H:i:s', $now - 48 * 3600))->order_by('id', 'ASC')->limit(100);
    foreach ($CI->db->get(db_prefix() . 'se_journey_aftercare_events')->result_array() as $e) {
        if (!in_array((string) $e['kind'], ['checkin', 'photo_request'], true)) {
            continue;
        }
        $j = se_journey_get_raw((int) $e['journey_id']);
        if (!$j) { continue; }
        $CI->db->where('id', (int) $e['id'])->update(db_prefix() . 'se_journey_aftercare_events', ['state' => 'unanswered']);
        se_journey_task($j, 'followup_unanswered', 'No reply to "' . $e['label'] . '" — call or message the patient', 'normal', null, $e['step_key']);
        if ((string) $j->state === 'aftercare_active') {
            se_journey_transition($j, 'followup_due', 'checkin_unanswered', 'system', null, 'event:' . (int) $e['id']);
        }
    }

    return $fired;
}

/** A text reply while a check-in is open: seal it, mark answered, thank once. */
function se_journey_on_aftercare_reply($j, array $ctx)
{
    $body = trim((string) ($ctx['body'] ?? ''));
    if ($body === '') {
        return ['handled' => false];
    }
    $CI = &get_instance();
    $CI->db->where('journey_id', (int) $j->id)->where_in('state', ['sent', 'unanswered'])->order_by('id', 'DESC')->limit(1);
    $e = $CI->db->get(db_prefix() . 'se_journey_aftercare_events')->row();
    if (!$e || (string) $e->kind !== 'checkin') {
        return ['handled' => false];
    }
    $sealed = se_journey_encrypt($body);
    $CI->db->where('id', (int) $e->id)->update(db_prefix() . 'se_journey_aftercare_events', [
        'state' => 'answered', 'answered_at' => date('Y-m-d H:i:s'),
        'reply_enc' => $sealed !== '' ? $sealed : null, 'reply_key_version' => $sealed !== '' ? se_journey_key_version() : null,
    ]);
    se_journey_event($j, 'aftercare_reply', (string) $e->label, [], 'patient', null, 'aftercare_event', (string) $e->id, (string) ($ctx['wamid'] ?? ''));
    se_journey_task($j, 'aftercare_reply', 'Check-in reply received for "' . $e->label . '" — review', 'normal', null, (string) $e->id);
    if ((string) $j->state === 'followup_due') {
        se_journey_transition($j, 'aftercare_active', 'checkin_answered', 'patient', null, (string) ($ctx['wamid'] ?? ''));
    }
    if (function_exists('se_journey_send_copy')) {
        se_journey_send_copy($j, 'aftercare_thanks', [], ['purpose' => 'aftercare_thanks', 'correlation' => (string) ($ctx['wamid'] ?? ''), 'dedup_salt' => 'e' . (int) $e->id]);
    }

    return ['handled' => true, 'reason' => 'aftercare_reply', 'journey_id' => (int) $j->id];
}

/** A follow-up photo satisfies the latest open photo request. */
function se_journey_on_aftercare_photo($j, $corr = '')
{
    $CI = &get_instance();
    $CI->db->where('journey_id', (int) $j->id)->where('kind', 'photo_request')->where_in('state', ['sent', 'unanswered'])->order_by('id', 'DESC')->limit(1);
    $e = $CI->db->get(db_prefix() . 'se_journey_aftercare_events')->row();
    if ($e) {
        $CI->db->where('id', (int) $e->id)->update(db_prefix() . 'se_journey_aftercare_events', ['state' => 'answered', 'answered_at' => date('Y-m-d H:i:s')]);
        if ((string) $j->state === 'followup_due') {
            se_journey_transition($j, 'aftercare_active', 'photo_answered', 'patient', null, $corr);
        }
    }
}

/** Staff closes the journey. */
function se_journey_complete($j, $staff_id, $note = '')
{
    if (!in_array((string) $j->state, ['aftercare_active', 'followup_due'], true)) {
        return ['ok' => false, 'reason' => 'transition_not_allowed'];
    }
    $CI = &get_instance();
    $CI->db->where('journey_id', (int) $j->id)->where('state', 'scheduled')->update(db_prefix() . 'se_journey_aftercare_events', ['state' => 'cancelled']);

    return se_journey_transition($j, 'completed', 'completed', 'staff', $staff_id, null, $note !== '' ? mb_substr($note, 0, 500) : null);
}

/** Decrypted check-in reply (callers must hold view_health and audit). */
function se_journey_aftercare_reply_text(array $e)
{
    if (empty($e['reply_enc'])) {
        return '';
    }
    $t = se_journey_decrypt($e['reply_enc']);

    return $t === null ? '' : $t;
}

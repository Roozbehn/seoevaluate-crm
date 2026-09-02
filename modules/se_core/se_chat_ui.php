<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Shared chat UI for the WhatsApp and Instagram conversation screens.
 *
 * One thread renderer and one composer, so the two inboxes look and behave
 * the same: bubbles aligned by direction, inline media (images, voice notes,
 * video, documents) from the media store, a composer that grows with the
 * text, sends on Enter (Shift+Enter = newline), shows a character counter and
 * the send policy in one line, and — for WhatsApp outside the 24-hour window —
 * a template picker with the template body and one input per placeholder.
 *
 * Everything policy-related (may this thread be replied to, free-form or
 * template) is decided by the SERVER and passed in; the page only renders it.
 */

/** CSS, once per page. */
function se_ui_chat_styles()
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    echo '<style>
.se-thread{max-height:560px;overflow-y:auto;padding:6px 4px 2px;scroll-behavior:smooth}
.se-msg{display:flex;margin:0 0 10px}
.se-msg.out{justify-content:flex-end}
.se-bubble{max-width:78%;padding:9px 12px;border-radius:14px;border:1px solid rgba(128,128,128,.28);background:rgba(128,128,128,.07);word-wrap:break-word;overflow-wrap:anywhere}
.se-msg.out .se-bubble{background:rgba(59,130,246,.14);border-color:rgba(59,130,246,.35);border-bottom-right-radius:4px}
.se-msg.in .se-bubble{border-bottom-left-radius:4px}
.se-bubble .se-meta{font-size:11px;opacity:.7;margin-top:4px;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.se-msg.out .se-meta{justify-content:flex-end}
.se-bubble .label{font-size:10px;padding:2px 6px}
.se-bubble img,.se-bubble video{max-width:100%}
.se-daysep{text-align:center;font-size:11px;opacity:.6;margin:8px 0 12px}
.se-composer{border-top:1px solid rgba(128,128,128,.25);padding-top:12px;margin-top:8px}
.se-composer .se-policy{font-size:12px;margin-bottom:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.se-composer textarea{resize:none;min-height:44px;max-height:220px;overflow-y:auto;line-height:1.4}
.se-composer .se-row{display:flex;gap:8px;align-items:flex-end}
.se-composer .se-row textarea{flex:1}
.se-composer .se-hint{font-size:11px;opacity:.65;margin-top:6px;display:flex;justify-content:space-between}
.se-composer .se-tpl pre{white-space:pre-wrap;font-size:12px;margin:8px 0}
.se-composer .se-tpl .form-group{margin-bottom:8px}
.se-composer .btn-send{min-width:96px}
.se-composer .se-tools{display:flex;gap:4px}
.se-composer .se-tools .btn{padding:6px 9px;font-size:15px;line-height:1.4}
.se-composer .se-attach{font-size:12px;margin-bottom:6px;padding:6px 10px;border:1px dashed rgba(128,128,128,.4);border-radius:6px}
.se-composer .se-attach a{margin-left:6px;font-weight:700;text-decoration:none}
.se-emoji{margin-top:8px;border:1px solid rgba(128,128,128,.3);border-radius:8px;padding:8px;background:rgba(128,128,128,.06)}
.se-emoji .se-emoji-tabs{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:6px}
.se-emoji .se-emoji-tabs button{border:0;background:transparent;font-size:16px;padding:2px 6px;border-radius:4px;cursor:pointer;opacity:.6}
.se-emoji .se-emoji-tabs button.on{opacity:1;background:rgba(59,130,246,.2)}
.se-emoji .se-emoji-grid{display:flex;flex-wrap:wrap;gap:2px;max-height:160px;overflow-y:auto}
.se-emoji .se-emoji-grid button{border:0;background:transparent;font-size:20px;line-height:1;padding:4px;border-radius:4px;cursor:pointer}
.se-emoji .se-emoji-grid button:hover{background:rgba(59,130,246,.2)}
.se-rec{margin-top:8px;font-size:12px;padding:8px 10px;border:1px solid rgba(239,68,68,.45);border-radius:8px;background:rgba(239,68,68,.08);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.se-rec .se-rec-dot{width:10px;height:10px;border-radius:50%;background:#ef4444;display:inline-block;animation:se-blink 1s infinite}
.se-rec.done .se-rec-dot{animation:none;background:#10b981}
.se-rec.done{border-color:rgba(16,185,129,.45);background:rgba(16,185,129,.08)}
#se-rec-btn.on{background:#ef4444;color:#fff;border-color:#ef4444}
@keyframes se-blink{50%{opacity:.2}}
</style>';
}

/**
 * The message thread.
 *
 * @param array $messages rows from the channel's messages table (id, direction, source, type, body,
 *                        template_name, delivery_state, received_at, sent_at, date_created, media_ref)
 * @param array $media    message_id => tblse_media row (from se_media_for_messages)
 * @param array $opts     channel: 'wa'|'ig'; redacted: bool; empty: string
 */
function se_ui_chat_thread(array $messages, array $media, array $opts)
{
    se_ui_chat_styles();
    $ch  = $opts['channel'] ?? 'wa';
    $red = !empty($opts['redacted']);

    if (empty($messages)) {
        se_ui_empty($opts['empty'] ?? _l('se_' . $ch . '_no_messages'));
        return;
    }

    echo '<div class="se-thread" id="se-thread">';
    $lastDay = '';
    foreach ($messages as $m) {
        $out = ($m['direction'] ?? 'in') === 'out';
        $ts  = (string) (($m['received_at'] ?? '') ?: (($m['sent_at'] ?? '') ?: ($m['date_created'] ?? '')));
        $day = substr($ts, 0, 10);
        if ($day !== '' && $day !== $lastDay) {
            echo '<div class="se-daysep">' . html_escape($day) . '</div>';
            $lastDay = $day;
        }

        echo '<div class="se-msg ' . ($out ? 'out' : 'in') . '"><div class="se-bubble">';

        $hasMedia = !empty($m['media_ref']);
        $mediaRow = $hasMedia && isset($media[(int) $m['id']]) ? $media[(int) $m['id']] : null;

        if ($hasMedia) {
            echo '<div>' . se_ui_media($mediaRow, $red) . '</div>';
            // The caption is stored on the media row; the message body (if
            // any) may duplicate it, so print the body only when it differs.
            if (!$red && !empty($m['body']) && (!$mediaRow || (string) $mediaRow['caption'] !== (string) $m['body'])) {
                echo '<div class="mtop5">' . nl2br(html_escape($m['body'])) . '</div>';
            }
        } elseif (!empty($m['body'])) {
            echo $red ? '<span class="text-muted">[message redacted for evidence]</span>' : nl2br(html_escape($m['body']));
        } elseif (!empty($m['template_name'])) {
            echo '<em><i class="fa fa-file-text-o"></i> ' . html_escape(_l('se_wa_template')) . ': ' . html_escape($m['template_name']) . '</em>';
        } else {
            echo '<span class="text-muted">&mdash;</span>';
        }

        echo '<div class="se-meta">';
        if ($out && !empty($m['source'])) {
            echo '<span>' . html_escape(_l('se_' . $ch . '_source_' . $m['source'])) . '</span>';
        }
        if (($m['type'] ?? 'text') !== 'text') {
            echo '<span>' . html_escape($m['type']) . '</span>';
        }
        echo '<span>' . html_escape(substr($ts, 11, 5) ?: $ts) . '</span>';
        if ($out && !empty($m['delivery_state'])) {
            echo se_ui_badge($m['delivery_state']);
            // Meta's reason for a drop (e.g. "131047 Re-engagement message"):
            // an error code + title from the status webhook, never content.
            if ($m['delivery_state'] === 'failed' && !empty($m['status_error'])) {
                echo '<span class="text-danger">' . html_escape($m['status_error']) . '</span>';
            }
        }
        echo '</div></div></div>';
    }
    echo '</div>';
    echo '<script>(function(){var t=document.getElementById("se-thread");if(t){t.scrollTop=t.scrollHeight;}})();</script>';
}

/**
 * The composer.
 *
 * $cfg:
 *   mode        'gated' | 'freeform' | 'template'
 *   action      POST url for freeform/template replies
 *   reason      gated: the translated reason text
 *   window_text freeform: e.g. "open until 2026-09-03 14:35"
 *   maxlength   freeform: int
 *   placeholder freeform: string
 *   templates   template: rows from se_wa_approved_templates (name, language, body, variables)
 *   sync        template (optional): ['action'=>url,'brand'=>id,'back'=>url] to render the sync button when empty
 *   label_send  string
 */
function se_ui_chat_composer(array $cfg)
{
    se_ui_chat_styles();
    $mode = $cfg['mode'] ?? 'gated';
    echo '<div class="se-composer">';

    if ($mode === 'gated') {
        echo '<div class="alert alert-warning no-margin"><i class="fa fa-lock"></i> <strong>'
           . html_escape($cfg['title'] ?? _l('se_wa_sending_gated')) . '</strong><br />'
           . html_escape($cfg['reason'] ?? '') . '</div>';
        echo '</div>';
        return;
    }

    if ($mode === 'freeform') {
        $accept = $cfg['accept'] ?? 'image/*,audio/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.txt';
        $maxMb  = (int) ($cfg['max_upload_mb'] ?? 25);
        echo form_open_multipart($cfg['action'], ['id' => 'se-compose', 'autocomplete' => 'off']);
        echo '<input type="hidden" name="kind" value="text" />';
        echo '<div class="se-policy">' . se_ui_badge('open', $cfg['window_label'] ?? _l('se_wa_window_open'))
           . '<span class="text-muted">' . html_escape($cfg['window_text'] ?? '') . '</span></div>';
        echo '<div class="se-attach" id="se-attach" style="display:none"><i class="fa fa-paperclip"></i> <span id="se-attach-name"></span>'
           . ' <a href="#" id="se-attach-clear" title="' . html_escape(_l('se_chat_remove_attachment')) . '">&times;</a>'
           . ' <small class="text-muted" id="se-attach-note">' . html_escape($cfg['attach_note'] ?? '') . '</small></div>';
        echo '<div class="se-row">'
           . '<div class="se-tools">'
           . '<button type="button" class="btn btn-default" id="se-emoji-btn" title="' . html_escape(_l('se_chat_emoji')) . '">&#128578;</button>'
           . '<label class="btn btn-default" for="se-file" title="' . html_escape(_l('se_chat_attach')) . '"><i class="fa fa-paperclip"></i></label>'
           . '<input type="file" id="se-file" name="attachment" accept="' . html_escape($accept) . '" style="display:none" />'
           . '<button type="button" class="btn btn-default" id="se-rec-btn" title="' . html_escape(_l('se_chat_record')) . '"><i class="fa fa-microphone"></i></button>'
           . '</div>'
           . '<textarea class="form-control" id="se-body" name="body" rows="1" required maxlength="'
           . (int) ($cfg['maxlength'] ?? 4096) . '" placeholder="' . html_escape($cfg['placeholder'] ?? _l('se_chat_placeholder')) . '"></textarea>'
           . '<button type="submit" class="btn btn-primary btn-send" id="se-send"><i class="fa fa-paper-plane"></i> '
           . html_escape($cfg['label_send'] ?? _l('se_chat_send')) . '</button></div>';
        echo '<div class="se-emoji" id="se-emoji" style="display:none"></div>';
        echo '<div class="se-rec" id="se-rec" style="display:none"><span class="se-rec-dot"></span> '
           . '<span id="se-rec-state">' . html_escape(_l('se_chat_recording')) . '</span> <strong id="se-rec-time">0:00</strong> '
           . '<button type="button" class="btn btn-default btn-xs" id="se-rec-cancel">' . html_escape(_l('se_chat_record_cancel')) . '</button>'
           . '<audio id="se-rec-preview" controls style="display:none;vertical-align:middle;margin-left:8px;max-width:260px"></audio></div>';
        echo '<div class="se-hint"><span>' . html_escape(_l('se_chat_enter_hint')) . ' · '
           . html_escape(_l($cfg['attach_hint_key'] ?? 'se_chat_attach_hint', $maxMb)) . '</span><span id="se-count">0 / '
           . (int) ($cfg['maxlength'] ?? 4096) . '</span></div>';
        echo form_close();
        se_ui_chat_scripts($maxMb, !empty($cfg['voice_ogg_ok']));
        // A template may be sent at any time, window open or not (Meta only
        // restricts free-form text). Offer the approved ones behind a toggle so
        // the everyday reply box stays uncluttered.
        if (!empty($cfg['templates'])) {
            echo '<div class="se-tpl-toggle" style="margin-top:8px"><a href="#" id="se-tpl-toggle" onclick="var p=document.getElementById(\'se-tpl-panel\');p.style.display=p.style.display===\'none\'?\'\':\'none\';return false;">'
               . '<i class="fa fa-file-text-o"></i> ' . html_escape(_l('se_chat_send_template_toggle')) . '</a></div>';
            echo '<div id="se-tpl-panel" style="display:none;margin-top:8px">';
            se_ui_chat_template_form($cfg, $cfg['templates']);
            echo '</div>';
        }
        echo '</div>';
        return;
    }

    // template mode (WhatsApp outside the window)
    echo '<div class="se-policy"><span class="label label-warning"><i class="fa fa-clock-o"></i> '
       . html_escape($cfg['window_label'] ?? _l('se_wa_window_closed')) . '</span>'
       . '<span class="text-muted">' . html_escape($cfg['window_text'] ?? _l('se_wa_reply_template_required')) . '</span></div>';

    $templates = $cfg['templates'] ?? [];
    if (empty($templates)) {
        se_ui_empty(_l('se_wa_no_templates'));
        if (!empty($cfg['sync'])) {
            echo form_open($cfg['sync']['action'], ['class' => 'text-center']);
            echo '<input type="hidden" name="brand" value="' . (int) $cfg['sync']['brand'] . '" />';
            echo '<input type="hidden" name="back" value="' . html_escape($cfg['sync']['back']) . '" />';
            echo '<button type="submit" class="btn btn-default btn-sm"><i class="fa fa-refresh"></i> '
               . html_escape(_l('se_wa_sync_templates')) . '</button> <small class="text-muted">'
               . html_escape(_l('se_wa_sync_templates_hint')) . '</small>';
            echo form_close();
        }
        echo '</div>';
        return;
    }

    se_ui_chat_template_form($cfg, $templates);
    se_ui_chat_scripts();
    echo '</div>';
}

/**
 * The approved-template form (select, preview, one input per placeholder,
 * send). Used alone outside the window and behind a toggle inside it.
 */
function se_ui_chat_template_form(array $cfg, array $templates)
{
    echo form_open($cfg['action'], ['id' => 'se-compose-tpl', 'autocomplete' => 'off']);
    echo '<input type="hidden" name="kind" value="template" />';
    echo '<div class="form-group"><select class="form-control" id="se-template" name="template" required onchange="seTplPick(this.value)">';
    foreach ($templates as $t) {
        echo '<option value="' . html_escape($t['name']) . '">' . html_escape($t['name'] . ' (' . $t['language'] . ')'
           . (!empty($t['category']) ? ' · ' . strtolower($t['category']) : '')) . '</option>';
    }
    echo '</select></div>';

    foreach ($templates as $i => $t) {
        $vars = function_exists('se_wa_template_variables') ? se_wa_template_variables($t) : [];
        echo '<div class="se-tpl" data-template="' . html_escape($t['name']) . '"' . ($i === 0 ? '' : ' style="display:none"') . '>';
        if (!empty($t['body'])) {
            echo '<pre>' . html_escape($t['body']) . '</pre>';
        }
        if ($vars) {
            echo '<p class="text-muted" style="font-size:12px">' . html_escape(_l('se_wa_template_variables_hint')) . '</p>';
            foreach ($vars as $v) {
                echo '<div class="form-group"><label class="control-label">{{' . html_escape($v) . '}}</label>'
                   . '<input type="text" class="form-control" maxlength="1024" name="variables[' . html_escape($t['name']) . '][' . html_escape($v) . ']"'
                   . ($i === 0 ? ' required' : ' disabled') . ' /></div>';
            }
        } else {
            echo '<p class="text-muted" style="font-size:12px">' . html_escape(_l('se_wa_template_no_variables')) . '</p>';
        }
        echo '</div>';
    }
    echo '<button type="submit" class="btn btn-primary btn-send" id="se-send-tpl"><i class="fa fa-paper-plane"></i> '
       . html_escape($cfg['label_send_template'] ?? _l('se_chat_send_template')) . '</button>';
    echo form_close();
    echo '<script>function seTplPick(n){document.querySelectorAll(".se-tpl").forEach(function(el){var on=el.getAttribute("data-template")===n;el.style.display=on?"":"none";el.querySelectorAll("input").forEach(function(i){i.disabled=!on;i.required=on;});});}</script>';
}

/** Composer behaviour: auto-grow, Enter to send, counter, attachment chip, emoji picker, double-submit guard. */
function se_ui_chat_scripts($max_upload_mb = 25, $cfg_ogg_ok = true)
{
    static $done = false;
    if ($done) { return; }
    $done = true;

    // Categories are plain arrays of characters: no library, no CDN, no
    // network. Insertion goes through the textarea's selection so an emoji
    // lands where the caret is, and the last 24 used are remembered locally.
    $sets = [
        ['😀', 'Smileys', '😀 😃 😄 😁 😆 😅 🤣 😂 🙂 🙃 😉 😊 😇 🥰 😍 🤩 😘 😗 😚 😙 🥲 😋 😛 😜 🤪 😝 🤑 🤗 🤭 🤫 🤔 🤐 🤨 😐 😑 😶 😏 😒 🙄 😬 🤥 😌 😔 😪 🤤 😴 😷 🤒 🤕 🤢 🤮 🤧 🥵 🥶 🥴 😵 🤯 🤠 🥳 🥸 😎 🤓 🧐 😕 😟 🙁 ☹️ 😮 😯 😲 😳 🥺 😦 😧 😨 😰 😥 😢 😭 😱 😖 😣 😞 😓 😩 😫 🥱 😤 😡 😠 🤬 😈 👿 💀 💩 🤡 👻 👽 🤖 😺 😸 😹 😻 😼 😽 🙀 😿 😾'],
        ['👋', 'People', '👋 🤚 🖐️ ✋ 🖖 👌 🤌 🤏 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 🖕 👇 ☝️ 👍 👎 ✊ 👊 🤛 🤜 👏 🙌 👐 🤲 🤝 🙏 ✍️ 💅 🤳 💪 🦾 🦵 🦶 👂 🦻 👃 🧠 🫀 🫁 🦷 🦴 👀 👁️ 👅 👄 💋 👶 🧒 👦 👧 🧑 👱 👨 🧔 👩 🧓 👴 👵 🙍 🙎 🙅 🙆 💁 🙋 🧏 🙇 🤦 🤷 👮 🕵️ 💂 👷 🤴 👸 👳 👲 🧕 🤵 👰 🤰 🤱 👼 🎅 🤶 🦸 🦹 🧙 🧚 🧛 🧜 🧝 🧞 🧟 💆 💇 🚶 🧍 🧎 🏃 💃 🕺 👯 🧖 🧗 🏇 ⛷️ 🏂 🏌️ 🏄 🚣 🏊 ⛹️ 🏋️ 🚴 🚵 🤸 🤼 🤽 🤾 🤹 🧘 🛀 🛌 👭 👫 👬 💏 💑 👪'],
        ['❤️', 'Hearts', '❤️ 🧡 💛 💚 💙 💜 🖤 🤍 🤎 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝 💟 ☮️ ✝️ ☪️ 🕉️ ☸️ ✡️ 🔯 🕎 ☯️ ☦️ 🛐 ⛎ ♈ ♉ ♊ ♋ ♌ ♍ ♎ ♏ ♐ ♑ ♒ ♓ 🆔 ⚛️ 🉑 ☢️ ☣️ 📴 📳 🈶 🈚 🈸 🈺 🈷️ ✴️ 🆚 💮 🉐 ㊙️ ㊗️ 🈴 🈵 🈹 🈲 🅰️ 🅱️ 🆎 🆑 🅾️ 🆘 ❌ ⭕ 🛑 ⛔ 📛 🚫 💯 💢 ♨️ 🚷 🚯 🚳 🚱 🔞 📵 🚭 ❗ ❕ ❓ ❔ ‼️ ⁉️ 🔅 🔆 〽️ ⚠️ 🚸 🔱 ⚜️ 🔰 ♻️ ✅ 🈯 💹 ❇️ ✳️ ❎ 🌐 💠 Ⓜ️ 🌀 💤 🏧 🚾 ♿ 🅿️ 🈳 🈂️ 🛂 🛃 🛄 🛅 🚹 🚺 🚼 🚻 🚮 🎦 📶 🈁 🔣 ℹ️ 🔤 🔡 🔠 🆖 🆗 🆙 🆒 🆕 🆓 0️⃣ 1️⃣ 2️⃣ 3️⃣ 4️⃣ 5️⃣ 6️⃣ 7️⃣ 8️⃣ 9️⃣ 🔟'],
        ['🌿', 'Nature', '🐶 🐱 🐭 🐹 🐰 🦊 🐻 🐼 🐨 🐯 🦁 🐮 🐷 🐸 🐵 🙈 🙉 🙊 🐔 🐧 🐦 🐤 🦆 🦅 🦉 🦇 🐺 🐗 🐴 🦄 🐝 🐛 🦋 🐌 🐞 🐜 🦟 🦗 🕷️ 🦂 🐢 🐍 🦎 🦖 🦕 🐙 🦑 🦐 🦞 🦀 🐡 🐠 🐟 🐬 🐳 🐋 🦈 🐊 🐅 🐆 🦓 🦍 🐘 🦛 🦏 🐪 🐫 🦒 🦘 🐃 🐂 🐄 🐎 🐖 🐏 🐑 🦙 🐐 🦌 🐕 🐩 🦮 🐈 🐓 🦃 🦚 🦜 🦢 🦩 🕊️ 🐇 🦝 🦨 🦡 🦦 🦥 🐁 🐀 🐿️ 🦔 🐾 🐉 🌵 🎄 🌲 🌳 🌴 🌱 🌿 ☘️ 🍀 🎍 🎋 🍃 🍂 🍁 🍄 🐚 🌾 💐 🌷 🌹 🥀 🌺 🌸 🌼 🌻 🌞 🌝 🌛 🌜 🌚 🌕 🌖 🌗 🌘 🌑 🌒 🌓 🌔 🌙 🌎 🌍 🌏 🪐 💫 ⭐ 🌟 ✨ ⚡ ☄️ 💥 🔥 🌪️ 🌈 ☀️ 🌤️ ⛅ 🌥️ ☁️ 🌦️ 🌧️ ⛈️ 🌩️ 🌨️ ❄️ ☃️ ⛄ 🌬️ 💨 💧 💦 ☔ ☂️ 🌊 🌫️'],
        ['🍕', 'Food', '🍏 🍎 🍐 🍊 🍋 🍌 🍉 🍇 🍓 🫐 🍈 🍒 🍑 🥭 🍍 🥥 🥝 🍅 🍆 🥑 🥦 🥬 🥒 🌶️ 🫑 🌽 🥕 🫒 🧄 🧅 🥔 🍠 🥐 🥯 🍞 🥖 🥨 🧀 🥚 🍳 🧈 🥞 🧇 🥓 🥩 🍗 🍖 🦴 🌭 🍔 🍟 🍕 🫓 🥪 🥙 🧆 🌮 🌯 🫔 🥗 🥘 🫕 🥫 🍝 🍜 🍲 🍛 🍣 🍱 🥟 🦪 🍤 🍙 🍚 🍘 🍥 🥠 🥮 🍢 🍡 🍧 🍨 🍦 🥧 🧁 🍰 🎂 🍮 🍭 🍬 🍫 🍿 🍩 🍪 🌰 🥜 🍯 🥛 🍼 🫖 ☕ 🍵 🧃 🥤 🧋 🍶 🍺 🍻 🥂 🍷 🥃 🍸 🍹 🧉 🍾 🧊 🥄 🍴 🍽️ 🥣 🥡 🥢 🧂'],
        ['⚽', 'Activity', '⚽ 🏀 🏈 ⚾ 🥎 🎾 🏐 🏉 🥏 🎱 🪀 🏓 🏸 🏒 🏑 🥍 🏏 🪃 🥅 ⛳ 🪁 🏹 🎣 🤿 🥊 🥋 🎽 🛹 🛼 🛷 ⛸️ 🥌 🎿 ⛷️ 🏂 🪂 🏋️ 🤼 🤸 ⛹️ 🤺 🤾 🏌️ 🏇 🧘 🏄 🏊 🤽 🚣 🧗 🚵 🚴 🏆 🥇 🥈 🥉 🏅 🎖️ 🏵️ 🎗️ 🎫 🎟️ 🎪 🤹 🎭 🩰 🎨 🎬 🎤 🎧 🎼 🎹 🥁 🪘 🎷 🎺 🪗 🎸 🪕 🎻 🎲 ♟️ 🎯 🎳 🎮 🎰 🧩'],
        ['🚗', 'Travel', '🚗 🚕 🚙 🚌 🚎 🏎️ 🚓 🚑 🚒 🚐 🛻 🚚 🚛 🚜 🦯 🦽 🦼 🛴 🚲 🛵 🏍️ 🛺 🚨 🚔 🚍 🚘 🚖 🚡 🚠 🚟 🚃 🚋 🚞 🚝 🚄 🚅 🚈 🚂 🚆 🚇 🚊 🚉 ✈️ 🛫 🛬 🛩️ 💺 🛰️ 🚀 🛸 🚁 🛶 ⛵ 🚤 🛥️ 🛳️ ⛴️ 🚢 ⚓ 🪝 ⛽ 🚧 🚦 🚥 🚏 🗺️ 🗿 🗽 🗼 🏰 🏯 🏟️ 🎡 🎢 🎠 ⛲ ⛱️ 🏖️ 🏝️ 🏜️ 🌋 ⛰️ 🏔️ 🗻 🏕️ ⛺ 🛖 🏠 🏡 🏘️ 🏚️ 🏗️ 🏭 🏢 🏬 🏣 🏤 🏥 🏦 🏨 🏪 🏫 🏩 💒 🏛️ ⛪ 🕌 🕍 🛕 🕋 ⛩️ 🛤️ 🛣️ 🗾 🎑 🏞️ 🌅 🌄 🌠 🎇 🎆 🌇 🌆 🏙️ 🌃 🌌 🌉 🌁'],
        ['💡', 'Objects', '⌚ 📱 📲 💻 ⌨️ 🖥️ 🖨️ 🖱️ 🖲️ 🕹️ 🗜️ 💽 💾 💿 📀 📼 📷 📸 📹 🎥 📽️ 🎞️ 📞 ☎️ 📟 📠 📺 📻 🎙️ 🎚️ 🎛️ 🧭 ⏱️ ⏲️ ⏰ 🕰️ ⌛ ⏳ 📡 🔋 🔌 💡 🔦 🕯️ 🪔 🧯 🛢️ 💸 💵 💴 💶 💷 🪙 💰 💳 💎 ⚖️ 🪜 🧰 🪛 🔧 🔨 ⚒️ 🛠️ ⛏️ 🪚 🔩 ⚙️ 🪤 🧱 ⛓️ 🧲 🔫 💣 🧨 🪓 🔪 🗡️ ⚔️ 🛡️ 🚬 ⚰️ 🪦 ⚱️ 🏺 🔮 📿 🧿 💈 ⚗️ 🔭 🔬 🕳️ 🩹 🩺 💊 💉 🩸 🧬 🦠 🧫 🧪 🌡️ 🧹 🪠 🧺 🧻 🚽 🚰 🚿 🛁 🛀 🧼 🪥 🪒 🧽 🪣 🧴 🛎️ 🔑 🗝️ 🚪 🪑 🛋️ 🛏️ 🛌 🧸 🪆 🖼️ 🪞 🪟 🛍️ 🛒 🎁 🎈 🎏 🎀 🪄 🪅 🎊 🎉 🎎 🏮 🎐 🧧 ✉️ 📩 📨 📧 💌 📥 📤 📦 🏷️ 🪧 📪 📫 📬 📭 📮 📯 📜 📃 📄 📑 🧾 📊 📈 📉 🗒️ 🗓️ 📆 📅 🗑️ 📇 🗃️ 🗳️ 🗄️ 📋 📁 📂 🗂️ 🗞️ 📰 📓 📔 📒 📕 📗 📘 📙 📚 📖 🔖 🧷 🔗 📎 🖇️ 📐 📏 🧮 📌 📍 ✂️ 🖊️ 🖋️ ✒️ 🖌️ 🖍️ 📝 ✏️ 🔍 🔎 🔏 🔐 🔒 🔓'],
    ];
    $json = json_encode(array_map(function ($s) { return [$s[0], $s[1], preg_split('/\s+/u', trim($s[2]))]; }, $sets), JSON_UNESCAPED_UNICODE);

    echo '<script>(function(){
var f=document.getElementById("se-compose"),b=document.getElementById("se-body"),c=document.getElementById("se-count"),s=document.getElementById("se-send");
if(!f)return;
var file=document.getElementById("se-file"),chip=document.getElementById("se-attach"),chipName=document.getElementById("se-attach-name"),chipClear=document.getElementById("se-attach-clear");
var MAX=' . (int) $max_upload_mb . '*1024*1024;
function grow(){if(!b)return;b.style.height="auto";b.style.height=Math.min(b.scrollHeight,220)+"px";if(c){c.textContent=b.value.length+" / "+b.getAttribute("maxlength");}}
function hasFile(){return file&&file.files&&file.files.length>0;}
function syncRequired(){if(b){b.required=!hasFile();}}
if(b){b.addEventListener("input",grow);grow();b.focus();
b.addEventListener("keydown",function(e){if(e.key==="Enter"&&!e.shiftKey&&!e.isComposing){e.preventDefault();if(b.value.trim()!==""||hasFile()){f.requestSubmit?f.requestSubmit():f.submit();}}});}
if(file){file.addEventListener("change",function(){if(hasFile()){var x=file.files[0];if(x.size>MAX){alert("' . html_escape(_l('se_chat_attach_too_large')) . ' ("+' . (int) $max_upload_mb . '+" MB)");file.value="";chip.style.display="none";syncRequired();return;}chipName.textContent=x.name+" ("+Math.max(1,Math.round(x.size/1024))+" KB)";chip.style.display="";}else{chip.style.display="none";}syncRequired();});
if(chipClear){chipClear.addEventListener("click",function(e){e.preventDefault();file.value="";chip.style.display="none";syncRequired();b&&b.focus();});}}
f.addEventListener("submit",function(){if(s){s.disabled=true;s.innerHTML="<i class=\"fa fa-spinner fa-spin\"></i> …";}});

/* voice recorder */
var rb=document.getElementById("se-rec-btn"),rp=document.getElementById("se-rec"),rt=document.getElementById("se-rec-time"),rs=document.getElementById("se-rec-state"),rc=document.getElementById("se-rec-cancel"),rv=document.getElementById("se-rec-preview");
if(rb&&rp&&file){
var OGG_OK=' . ($cfg_ogg_ok ? 'true' : 'false') . ';
var rec=null,stream=null,chunks=[],t0=0,tick=null,MAXSEC=300;
function pickType(){var c=["audio/mp4;codecs=mp4a.40.2","audio/mp4"];if(OGG_OK)c.push("audio/ogg;codecs=opus");for(var i=0;i<c.length;i++){if(window.MediaRecorder&&MediaRecorder.isTypeSupported(c[i]))return c[i];}return "";}
function fmt(s){return Math.floor(s/60)+":"+("0"+(s%60)).slice(-2);}
function stopStream(){if(stream){stream.getTracks().forEach(function(t){t.stop();});stream=null;}}
function resetRec(){if(tick){clearInterval(tick);tick=null;}rec=null;chunks=[];rb.classList.remove("on");rb.innerHTML="<i class=\"fa fa-microphone\"></i>";rp.style.display="none";rp.classList.remove("done");rv.style.display="none";rv.removeAttribute("src");rt.textContent="0:00";rs.textContent="' . html_escape(_l('se_chat_recording')) . '";}
function finish(){if(!chunks.length){resetRec();return;}var type=rec&&rec.mimeType?rec.mimeType:pickType();var base=type.split(";")[0];var ext=base==="audio/ogg"?"ogg":"m4a";
var blob=new Blob(chunks,{type:base});var d=new Date(),pad=function(n){return ("0"+n).slice(-2);};
var name="voice-"+d.getFullYear()+pad(d.getMonth()+1)+pad(d.getDate())+"-"+pad(d.getHours())+pad(d.getMinutes())+pad(d.getSeconds())+"."+ext;
try{var dt=new DataTransfer();dt.items.add(new File([blob],name,{type:base}));file.files=dt.files;}catch(e){alert("' . html_escape(_l('se_chat_record_unsupported')) . '");resetRec();return;}
file.dispatchEvent(new Event("change"));rp.classList.add("done");rs.textContent="' . html_escape(_l('se_chat_record_ready')) . '";rv.src=URL.createObjectURL(blob);rv.style.display="";rb.classList.remove("on");rb.innerHTML="<i class=\"fa fa-microphone\"></i>";if(tick){clearInterval(tick);tick=null;}}
rb.addEventListener("click",function(){
if(rec&&rec.state==="recording"){rec.stop();stopStream();return;}
var type=pickType();if(!type||!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia){alert("' . html_escape(_l('se_chat_record_unsupported')) . '");return;}
navigator.mediaDevices.getUserMedia({audio:true}).then(function(st){stream=st;chunks=[];resetRec();
try{rec=new MediaRecorder(st,{mimeType:type,audioBitsPerSecond:64000});}catch(e){rec=new MediaRecorder(st);}
rec.ondataavailable=function(e){if(e.data&&e.data.size>0)chunks.push(e.data);};
rec.onstop=finish;rec.start(1000);t0=Date.now();rb.classList.add("on");rb.innerHTML="<i class=\"fa fa-stop\"></i>";rp.style.display="";
tick=setInterval(function(){var s=Math.floor((Date.now()-t0)/1000);rt.textContent=fmt(s);if(s>=MAXSEC&&rec&&rec.state==="recording"){rec.stop();stopStream();}},500);
}).catch(function(){alert("' . html_escape(_l('se_chat_record_denied')) . '");});
});
if(rc){rc.addEventListener("click",function(){if(rec&&rec.state==="recording"){rec.onstop=null;rec.stop();}stopStream();file.value="";chip.style.display="none";syncRequired();resetRec();});}
if(chipClear){chipClear.addEventListener("click",function(){stopStream();resetRec();});}
}

/* emoji picker */
var eb=document.getElementById("se-emoji-btn"),ep=document.getElementById("se-emoji");
if(eb&&ep&&b){
var SETS=' . $json . ',RK="se_emoji_recent",recent=[];
try{recent=JSON.parse(localStorage.getItem(RK)||"[]");if(!Array.isArray(recent))recent=[];}catch(e){recent=[];}
function insert(ch){var st=b.selectionStart||0,en=b.selectionEnd||0;b.value=b.value.slice(0,st)+ch+b.value.slice(en);b.selectionStart=b.selectionEnd=st+ch.length;b.focus();grow();
recent=[ch].concat(recent.filter(function(x){return x!==ch;})).slice(0,24);try{localStorage.setItem(RK,JSON.stringify(recent));}catch(e){}}
function render(i){ep.innerHTML="";var tabs=document.createElement("div");tabs.className="se-emoji-tabs";
var all=[["🕘","Recent",recent]].concat(SETS);
all.forEach(function(set,j){var t=document.createElement("button");t.type="button";t.textContent=set[0];t.title=set[1];if(j===i)t.className="on";t.addEventListener("click",function(){render(j);});tabs.appendChild(t);});
ep.appendChild(tabs);var g=document.createElement("div");g.className="se-emoji-grid";
var list=all[i][2];if(!list.length){g.innerHTML="<small class=\"text-muted\">' . html_escape(_l('se_chat_emoji_recent_empty')) . '</small>";}
list.forEach(function(ch){var x=document.createElement("button");x.type="button";x.textContent=ch;x.addEventListener("click",function(){insert(ch);});g.appendChild(x);});
ep.appendChild(g);}
eb.addEventListener("click",function(){var open=ep.style.display==="none";ep.style.display=open?"":"none";if(open)render(recent.length?0:1);});
document.addEventListener("keydown",function(e){if(e.key==="Escape"&&ep.style.display!=="none"){ep.style.display="none";}});
}
})();</script>';
}

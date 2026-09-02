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
        echo form_open($cfg['action'], ['id' => 'se-compose', 'autocomplete' => 'off']);
        echo '<input type="hidden" name="kind" value="text" />';
        echo '<div class="se-policy">' . se_ui_badge('open', $cfg['window_label'] ?? _l('se_wa_window_open'))
           . '<span class="text-muted">' . html_escape($cfg['window_text'] ?? '') . '</span></div>';
        echo '<div class="se-row"><textarea class="form-control" id="se-body" name="body" rows="1" required maxlength="'
           . (int) ($cfg['maxlength'] ?? 4096) . '" placeholder="' . html_escape($cfg['placeholder'] ?? _l('se_chat_placeholder')) . '"></textarea>'
           . '<button type="submit" class="btn btn-primary btn-send" id="se-send"><i class="fa fa-paper-plane"></i> '
           . html_escape($cfg['label_send'] ?? _l('se_chat_send')) . '</button></div>';
        echo '<div class="se-hint"><span>' . html_escape(_l('se_chat_enter_hint')) . '</span><span id="se-count">0 / '
           . (int) ($cfg['maxlength'] ?? 4096) . '</span></div>';
        echo form_close();
        se_ui_chat_scripts();
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

    echo form_open($cfg['action'], ['id' => 'se-compose', 'autocomplete' => 'off']);
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
    echo '<button type="submit" class="btn btn-primary btn-send" id="se-send"><i class="fa fa-paper-plane"></i> '
       . html_escape($cfg['label_send'] ?? _l('se_chat_send_template')) . '</button>';
    echo form_close();
    echo '<script>function seTplPick(n){document.querySelectorAll(".se-tpl").forEach(function(el){var on=el.getAttribute("data-template")===n;el.style.display=on?"":"none";el.querySelectorAll("input").forEach(function(i){i.disabled=!on;i.required=on;});});}</script>';
    se_ui_chat_scripts();
    echo '</div>';
}

/** Composer behaviour: auto-grow, Enter to send, counter, double-submit guard. */
function se_ui_chat_scripts()
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    echo '<script>(function(){
var f=document.getElementById("se-compose"),b=document.getElementById("se-body"),c=document.getElementById("se-count"),s=document.getElementById("se-send");
if(!f)return;
function grow(){if(!b)return;b.style.height="auto";b.style.height=Math.min(b.scrollHeight,220)+"px";if(c){c.textContent=b.value.length+" / "+b.getAttribute("maxlength");}}
if(b){b.addEventListener("input",grow);grow();b.focus();
b.addEventListener("keydown",function(e){if(e.key==="Enter"&&!e.shiftKey&&!e.isComposing){e.preventDefault();if(b.value.trim()!==""){f.requestSubmit?f.requestSubmit():f.submit();}}});}
f.addEventListener("submit",function(){if(s){s.disabled=true;s.innerHTML="<i class=\"fa fa-spinner fa-spin\"></i> …";}});
})();</script>';
}

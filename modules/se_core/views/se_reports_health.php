<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<style>
/* Higher-contrast health cards; readable in the dark theme. Status is carried
 * by TEXT as well as colour, so it never depends on colour alone. */
.se-health-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;margin-top:12px}
.se-hc{border:1px solid rgba(148,163,184,.28);border-radius:8px;padding:14px 16px;background:rgba(148,163,184,.06)}
.se-hc h5{margin:0 0 10px;font-weight:600;display:flex;align-items:center;justify-content:space-between;gap:8px}
.se-hc dl{margin:0}
.se-hc .row-kv{display:flex;justify-content:space-between;gap:10px;padding:3px 0;border-bottom:1px solid rgba(148,163,184,.14);font-size:13px}
.se-hc .row-kv:last-child{border-bottom:0}
.se-hc .k{color:#94a3b8}
.se-hc .v{color:#e2e8f0;text-align:right;font-variant-numeric:tabular-nums}
.se-badge{font-size:11px;font-weight:700;padding:2px 8px;border-radius:999px;letter-spacing:.02em}
.se-ready{background:#065f46;color:#d1fae5}
.se-warn{background:#78350f;color:#fde68a}
.se-blocked{background:#7f1d1d;color:#fecaca}
.se-disabled{background:#334155;color:#cbd5e1}
.se-error{background:#7f1d1d;color:#fecaca}
.se-blockers{margin-top:16px}
.se-blocker{border-left:3px solid #f59e0b;background:rgba(245,158,11,.08);padding:10px 12px;border-radius:4px;margin:8px 0}
.se-blocker .why{color:#fde68a;font-weight:600}
.se-blocker .meta{color:#cbd5e1;font-size:12px;margin-top:3px}
.se-blocker a{color:#93c5fd}
.se-checked{color:#94a3b8;font-size:12px;margin-top:6px}
</style>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">
    <h4><?php echo _l('se_reports_health'); ?></h4>
    <a href="<?php echo admin_url('se_core/se_reports/index?brand=' . (int) $brand); ?>">&laquo; <?php echo _l('se_reports'); ?></a>
    <hr />
    <div id="se-health" data-brand="<?php echo (int) $brand; ?>"><p class="text-muted"><?php echo _l('se_reports_loading'); ?></p></div>
  </div></div>
</div></div></div></div>
<script>
(function(){
  var el=document.getElementById('se-health'), b=el.getAttribute('data-brand'),
      evidenceRedacted=new URLSearchParams(window.location.search).get('evidence')==='redacted';
  fetch('<?php echo admin_url('se_core/se_reports/health_data'); ?>?brand='+b,{credentials:'same-origin'})
   .then(function(r){return r.json()}).then(function(d){
     function esc(s){var e=document.createElement('div');e.textContent=s==null?'':String(s);return e.innerHTML;}
     function yn(v){return v?'Yes':'No';}
     function ts(v){return v?esc(v):'—';}
     function badge(state){
       var m={ready:['se-ready','Ready'],warning:['se-warn','Warning'],blocked:['se-blocked','Blocked'],
               disabled:['se-disabled','Disabled'],error:['se-error','Error'],healthy:['se-ready','Healthy'],
               failed:['se-error','Failed'],unknown:['se-disabled','Unknown']};
       var x=m[state]||m.unknown; return '<span class="se-badge '+x[0]+'">'+x[1]+'</span>';
     }
     function kv(k,v){return '<div class="row-kv"><span class="k">'+esc(k)+'</span><span class="v">'+v+'</span></div>';}
     // Six-state webhook evidence chain. Each state carries a fact, not a guess:
     // a readable secret, a route self-check, or a timestamp from a real event.
     function ynts(flag, at){ return flag ? ('<span class="se-badge se-ready">Yes</span> <small>'+ts(at)+'</small>') : '<span class="se-badge se-disabled">No</span>'; }
     function yntssrc(flag, at, src){ return flag ? ('<span class="se-badge se-ready">Yes</span> <small>'+ts(at)+(src?(' · '+esc(src)):'')+'</small>') : '<span class="se-badge se-disabled">No</span>'; }
     function wstateRows(ws){
       if(!ws){return [];}
       // Provider (Meta) evidence turns states green; self-tests are shown as
       // separate, clearly-labelled footnotes and never as provider traffic.
       function provRow(flag, at, selftestAt){
         var h = flag ? ('<span class="se-badge se-ready">Yes</span> <small>'+ts(at)+' · meta</small>')
                      : '<span class="se-badge se-disabled">No</span>';
         if(selftestAt){ h += ' <small class="text-muted">(self-test: '+ts(selftestAt)+')</small>'; }
         return h;
       }
       return [
         ['1 · verify_token_installed', yn(ws.verify_token_installed)],
         ['2 · verification_ready', yn(ws.verification_ready)],
         ['3 · challenge_verified', provRow(ws.challenge_verified, ws.challenge_verified_at, ws.challenge_selftest_at)],
         ['4 · app_secret_installed', yn(ws.app_secret_installed)+(ws.app_secret_inherited?' <small>(inherited)</small>':'')],
         ['5 · signed_post_received', provRow(ws.signed_post_received, ws.signed_post_at, ws.signed_post_selftest_at)],
         ['6 · live_test_passed', ynts(ws.live_test_passed, ws.live_test_at)]
       ];
     }
     function card(title,state,rows){
       var h='<div class="se-hc"><h5>'+esc(title)+' '+badge(state)+'</h5>';
       rows.forEach(function(r){h+=kv(r[0],r[1]);}); return h+'</div>';
     }

     var m=d.meta||{}, g=d.google||{}, w=d.whatsapp||{}, o=d.outbox||{};

     // System / cron
     var cronState=d.cron_state||'unknown';
     var sys=card('System / Cron',cronState,[
       ['Status', cronState],
       ['Last run', (d.cron_age_seconds==null?'never':esc(d.cron_age_seconds)+'s ago')],
       ['Expected interval', esc(d.cron_expected_interval_seconds)+'s'],
       ['Warn / fail after', esc(d.cron_warn_seconds)+'s / '+esc(d.cron_fail_seconds)+'s'],
       ['Outbox pending / failed', esc(o.pending)+' / '+esc(o.failed)],
       ['Outbox sent / dead', esc(o.sent)+' / '+esc(o.dead)]
     ]);

     // Meta CAPI (independent of Lead Ads). A dataset conflict is the most
     // severe CAPI state: configured, but pointed at the wrong dataset.
     var capiState=m.dataset_conflict?'blocked':(m.capi_ready?(m.capi_enabled?'ready':'disabled'):(m.capi_token?'warning':'blocked'));
     var capiRows=[
       ['Credential', yn(m.capi_token)],
       ['Dataset id', esc(m.dataset_id||'—')]
     ];
     if(m.dataset_conflict){
       capiRows.push(['Dataset conflict','<span class="se-badge se-blocked">Wrong dataset</span>']);
       capiRows.push(['Authoritative id', esc(m.dataset_conflict)]);
     } else if(m.dataset_authoritative){
       capiRows.push(['Dataset verified','<span class="se-badge se-ready">Matches authoritative</span>']);
     }
     capiRows.push(['Ready', yn(m.capi_ready)]);
     capiRows.push(['Transmission', m.capi_enabled?'Enabled':'Disabled']);
     capiRows.push(['Last accepted event', m.last_capi_at
       ? (ts(m.last_capi_at)+(m.last_capi_event?(' · '+esc(m.last_capi_event)):'')+(m.last_capi_event_id?(' <small>id '+esc(m.last_capi_event_id)+'</small>'):''))
       : '—']);
     if(m.last_capi_error){ capiRows.push(['Last error', esc(m.last_capi_error)]); }
     var capi=card('Meta Conversions API',capiState,capiRows);

     // Meta Lead Ads (independent of CAPI)
     var laState=!m.webhook_ready?'blocked':(m.leadgen_gated?'warning':(m.leadgen_test_ready?'ready':'warning'));
     var laRows=[
       ['Page token', yn(m.page_token)],
       ['Page/form mapping', esc(m.active_form_count||0)],
       ['Last webhook', ts(m.last_webhook_at)],
       ['Last successful fetch', ts(m.last_fetch_ok_at)],
       ['App Review / retrieval',
         (m.leadgen_access_level==='standard_operational')
           ? ('Operational — standard access (business-owned assets)'+(m.leadgen_review_gated?'; advanced access pending':''))
           : (m.leadgen_review_gated?('Pending — '+esc(m.leadgen_review_item||'leads_retrieval')):(m.leadgen_gated?'Pending':'Granted'))]
     ].concat(wstateRows(m.webhook_state));
     var la=card('Meta Lead Ads',laState,laRows);

     // WhatsApp
     var waState=!w.identifiers_configured?'blocked':((w.app_secret&&w.webhook_verified)?'ready':'warning');
     var waRows=[
       ['Identifiers configured', yn(w.identifiers_configured)],
       ['Last inbound', ts(w.last_inbound_at)],
       ['Last status event', ts(w.last_status_at)]
     ].concat(wstateRows(w.webhook_state));
     (w.numbers||[]).forEach(function(n){
       waRows.push(['Number '+(evidenceRedacted?'[redacted]':esc(n.number||n.phone_number_id)), esc(n.state)+(n.quality?(' · '+esc(n.quality)):'')]);
     });
     var wa=card('WhatsApp',waState,waRows);

     // Instagram Direct (se_instagram) — same six-state evidence chain.
     var ig=d.instagram||{};
     var igState=!ig.implemented?'disabled':(!ig.identifiers_configured?'blocked':((ig.send_blocked_reason==='')?'ready':'warning'));
     var igRows=[
       ['Account configured', yn(ig.identifiers_configured)],
       ['Token', ig.token?('Yes'+(ig.token_inherited?' <small>(inherited from meta_page)</small>':'')):'No'],
       ['Messaging scopes verified', ig.scopes_verified?('Yes <small>'+ts(ig.scopes_verified_at)+'</small>'):'No'],
       ['Send capability', ig.send_blocked_reason===''?'<span class="se-badge se-ready">Ready</span>':('<span class="se-badge se-warn">'+esc(ig.send_blocked_reason)+'</span>')],
       ['Last inbound', ts(ig.last_inbound_at)],
       ['Last read receipt', ts(ig.last_status_at)]
     ].concat(wstateRows(ig.webhook_state));
     (ig.accounts||[]).forEach(function(a){ igRows.push(['Account '+esc(a.username||a.ig_account_id), esc(a.state)]); });
     var igc=card('Instagram Direct',igState,igRows);

     // Google Data Manager (optional)
     var gState=g.externally_gated?'disabled':(g.credential_failing?'error':(g.status_polling?'ready':'warning'));
     var goog=card('Google Data Manager',gState,[
       ['Customer id', esc(g.customer_id||'—')],
       ['Credential', g.credential_present?(g.credential_failing?'Present (failing)':'Present'):'Not configured'],
       ['Authentication', g.sa_token_configured?'OK':(g.credential_failing?'Failing':'—')],
       ['Request-status polling', g.status_polling
         ? (g.credential_present
             ? 'Implemented, not live-tested — awaiting a live status poll'
             : 'Implemented, not live-tested — google_sa_22 missing')
         : 'Off'],
       ['Last request', ts(g.last_request_id)],
       ['Last status', esc(g.last_request_status||'—')]
     ]);

     var h='<div class="se-health-grid">'+sys+capi+la+wa+igc+goog+'</div>';

     // Optional Google properties freshness (deliberately-disabled != unhealthy)
     h+='<div class="se-checked">Optional data freshness — GA4: '+ts((d.data_freshness||{}).ga4)
       +' · Search Console: '+ts((d.data_freshness||{}).search_console)
       +' · Google Ads: '+ts((d.data_freshness||{}).google_ads)+'</div>';

     // Precise, per-provider blockers with remediation
     h+='<div class="se-blockers"><h5>Blockers</h5>';
     if((d.blockers||[]).length){
       d.blockers.forEach(function(x){
         h+='<div class="se-blocker"><div class="why">'+esc(x.reason)+'</div>'
           +'<div class="meta">Impact: '+esc(x.impact)+'</div>'
           +'<div class="meta">Next: '+esc(x.action)+(x.link?(' — <a href="'+esc(x.link)+'">Open</a>'):'')+'</div>'
           +'<div class="meta">Checked: '+ts(x.checked_at)+'</div></div>';
       });
     } else { h+='<p class="text-success">No blockers — all configured integrations are ready.</p>'; }
     h+='</div>';
     h+='<div class="se-checked">Snapshot taken: '+ts(d.checked_at)+'</div>';

     el.innerHTML=h;
   }).catch(function(){el.innerHTML='<p class="text-danger">Failed to load health snapshot.</p>';});
})();
</script>
<?php init_tail(); ?>
</body></html>

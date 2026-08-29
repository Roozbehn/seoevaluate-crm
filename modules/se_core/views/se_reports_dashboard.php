<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">
    <h4 class="no-margin"><?php echo _l('se_reports'); ?></h4>
    <a href="<?php echo admin_url('se_core/se_reports/health?brand=' . (int) $brand); ?>" class="pull-right"><?php echo _l('se_reports_health'); ?></a>
    <hr />
    <div id="se-report" data-brand="<?php echo (int) $brand; ?>"><p class="text-muted"><?php echo _l('se_reports_loading'); ?></p></div>
  </div></div>
</div></div></div></div>
<script>
(function(){
  var L=<?php echo json_encode([
      'leads' => _l('leads'), 'converted' => _l('se_rep_converted'), 'held' => _l('se_rep_consultations_held'),
      'no_show' => _l('se_rep_no_show_rate'), 'by_stage' => _l('se_rep_by_stage'), 'by_source' => _l('se_rep_by_source'),
      'source' => _l('se_rep_source'), 'rate' => _l('se_rep_rate'), 'in' => _l('se_rep_whatsapp_in'), 'out' => _l('se_rep_whatsapp_out'),
      'est_cost' => _l('se_rep_est_cost'), 'spend_vs_outcome' => _l('se_rep_spend_vs_outcome'), 'spend' => _l('se_rep_spend'),
      'cost_per_lead' => _l('se_rep_cost_per_lead'), 'cost_per_treatment' => _l('se_rep_cost_per_treatment'),
      'no_spend' => _l('se_rep_no_imported_spend'), 'failed' => _l('se_rep_failed_to_load'),
  ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  var el=document.getElementById('se-report'), b=el.getAttribute('data-brand');
  fetch('<?php echo admin_url('se_core/se_reports/data'); ?>?brand='+b,{credentials:'same-origin'})
   .then(function(r){return r.json()}).then(function(d){
     function esc(s){var e=document.createElement('div');e.textContent=s==null?'':String(s);return e.innerHTML;}
     var t=d.totals||{}, a=d.appointments||{}, w=d.whatsapp||{}, so=d.spend_outcome||{};
     var h='<div class="row">';
     h+='<div class="col-md-3"><div class="panel_s"><div class="panel-body"><h3>'+esc(t.leads)+'</h3><span>'+esc(L.leads)+'</span></div></div></div>';
     h+='<div class="col-md-3"><div class="panel_s"><div class="panel-body"><h3>'+esc(t.converted)+'</h3><span>'+esc(L.converted)+' ('+esc((t.conv_rate*100).toFixed(1))+'%)</span></div></div></div>';
     h+='<div class="col-md-3"><div class="panel_s"><div class="panel-body"><h3>'+esc(a.held)+'</h3><span>'+esc(L.held)+'</span></div></div></div>';
     h+='<div class="col-md-3"><div class="panel_s"><div class="panel-body"><h3>'+esc((a.no_show_rate*100).toFixed(1))+'%</h3><span>'+esc(L.no_show)+'</span></div></div></div>';
     h+='</div>';
     h+='<h5>'+esc(L.by_stage)+'</h5><table class="table table-striped"><tbody>';
     Object.keys(d.by_stage||{}).forEach(function(k){h+='<tr><td>'+esc(k)+'</td><td>'+esc(d.by_stage[k])+'</td></tr>';});
     h+='</tbody></table>';
     h+='<h5>'+esc(L.by_source)+'</h5><table class="table"><thead><tr><th>'+esc(L.source)+'</th><th>'+esc(L.leads)+'</th><th>'+esc(L.converted)+'</th><th>'+esc(L.rate)+'</th></tr></thead><tbody>';
     Object.keys(d.by_source||{}).forEach(function(k){var s=d.by_source[k];h+='<tr><td>'+esc(k)+'</td><td>'+esc(s.leads)+'</td><td>'+esc(s.converted)+'</td><td>'+esc((s.conv_rate*100).toFixed(1))+'%</td></tr>';});
     h+='</tbody></table>';
     h+='<h5>WhatsApp</h5><p>'+esc(L.in)+': '+esc(w.messages_in)+' · '+esc(L.out)+': '+esc(w.messages_out)+' · '+esc(L.est_cost)+': '+esc(w.estimated_cost)+'</p>';
     h+='<h5>'+esc(L.spend_vs_outcome)+'</h5><p>'+esc(L.spend)+': '+esc(so.spend)+' · '+esc(L.cost_per_lead)+': '+esc(so.cost_per_lead)+' · '+esc(L.cost_per_treatment)+': '+esc(so.cost_per_treatment)+(so.gated?' <span class="label label-warning">'+esc(L.no_spend)+'</span>':'')+'</p>';
     el.innerHTML=h;
   }).catch(function(){el.innerHTML='<p class="text-danger">'+esc(L.failed)+'</p>';});
})();
</script>
<?php init_tail(); ?>
</body></html>

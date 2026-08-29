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
  var el=document.getElementById('se-report'), b=el.getAttribute('data-brand');
  fetch('<?php echo admin_url('se_core/se_reports/data'); ?>?brand='+b,{credentials:'same-origin'})
   .then(function(r){return r.json()}).then(function(d){
     function esc(s){var e=document.createElement('div');e.textContent=s==null?'':String(s);return e.innerHTML;}
     var t=d.totals||{}, a=d.appointments||{}, w=d.whatsapp||{}, so=d.spend_outcome||{};
     var h='<div class="row">';
     h+='<div class="col-md-3"><div class="panel_s"><div class="panel-body"><h3>'+esc(t.leads)+'</h3><span>Leads</span></div></div></div>';
     h+='<div class="col-md-3"><div class="panel_s"><div class="panel-body"><h3>'+esc(t.converted)+'</h3><span>Converted ('+esc((t.conv_rate*100).toFixed(1))+'%)</span></div></div></div>';
     h+='<div class="col-md-3"><div class="panel_s"><div class="panel-body"><h3>'+esc(a.held)+'</h3><span>Consultations held</span></div></div></div>';
     h+='<div class="col-md-3"><div class="panel_s"><div class="panel-body"><h3>'+esc((a.no_show_rate*100).toFixed(1))+'%</h3><span>No-show rate</span></div></div></div>';
     h+='</div>';
     h+='<h5>Leads by stage</h5><table class="table table-striped"><tbody>';
     Object.keys(d.by_stage||{}).forEach(function(k){h+='<tr><td>'+esc(k)+'</td><td>'+esc(d.by_stage[k])+'</td></tr>';});
     h+='</tbody></table>';
     h+='<h5>By source</h5><table class="table"><thead><tr><th>Source</th><th>Leads</th><th>Converted</th><th>Rate</th></tr></thead><tbody>';
     Object.keys(d.by_source||{}).forEach(function(k){var s=d.by_source[k];h+='<tr><td>'+esc(k)+'</td><td>'+esc(s.leads)+'</td><td>'+esc(s.converted)+'</td><td>'+esc((s.conv_rate*100).toFixed(1))+'%</td></tr>';});
     h+='</tbody></table>';
     h+='<h5>WhatsApp</h5><p>In: '+esc(w.messages_in)+' · Out: '+esc(w.messages_out)+' · Est. cost: '+esc(w.estimated_cost)+'</p>';
     h+='<h5>Spend vs outcome</h5><p>Spend: '+esc(so.spend)+' · Cost/lead: '+esc(so.cost_per_lead)+' · Cost/treatment: '+esc(so.cost_per_treatment)+(so.gated?' <span class="label label-warning">no imported spend</span>':'')+'</p>';
     el.innerHTML=h;
   }).catch(function(){el.innerHTML='<p class="text-danger">Failed to load</p>';});
})();
</script>
<?php init_tail(); ?>
</body></html>

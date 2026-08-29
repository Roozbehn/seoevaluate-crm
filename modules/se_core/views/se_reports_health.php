<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-10 col-md-offset-1">
  <div class="panel_s"><div class="panel-body">
    <h4><?php echo _l('se_reports_health'); ?></h4>
    <a href="<?php echo admin_url('se_core/se_reports/index?brand=' . (int) $brand); ?>">&laquo; <?php echo _l('se_reports'); ?></a>
    <hr />
    <div id="se-health" data-brand="<?php echo (int) $brand; ?>"><p class="text-muted"><?php echo _l('se_reports_loading'); ?></p></div>
  </div></div>
</div></div></div></div>
<script>
(function(){
  var el=document.getElementById('se-health'), b=el.getAttribute('data-brand');
  fetch('<?php echo admin_url('se_core/se_reports/health_data'); ?>?brand='+b,{credentials:'same-origin'})
   .then(function(r){return r.json()}).then(function(d){
     function esc(s){var e=document.createElement('div');e.textContent=s==null?'':String(s);return e.innerHTML;}
     var h='';
     h+='<p>Cron: '+(d.cron_healthy?'<span class="label label-success">healthy</span>':'<span class="label label-danger">stale</span>')+' ('+esc(d.cron_age_seconds)+'s ago)</p>';
     h+='<p>Outbox — pending: '+esc(d.outbox.pending)+' · failed: '+esc(d.outbox.failed)+' · sent: '+esc(d.outbox.sent)+'</p>';
     h+='<p>Meta token: '+((d.meta&&d.meta.token_configured)?'yes':'no')+' · Google SA: '+((d.google&&d.google.sa_token_configured)?'yes':'no')+'</p>';
     h+='<h5>WhatsApp numbers</h5>';
     if((d.whatsapp_numbers||[]).length){ d.whatsapp_numbers.forEach(function(n){h+='<p>'+esc(n.number)+' — quality '+esc(n.quality)+' — '+esc(n.state)+'</p>';}); } else { h+='<p class="text-muted">none</p>'; }
     h+='<h5>Data freshness</h5><ul>';
     Object.keys(d.data_freshness||{}).forEach(function(k){h+='<li>'+esc(k)+': '+esc(d.data_freshness[k]||'never')+'</li>';});
     h+='</ul>';
     h+='<h5>Blockers</h5>';
     if((d.blockers||[]).length){h+='<ul>';d.blockers.forEach(function(x){h+='<li class="text-warning">'+esc(x)+'</li>';});h+='</ul>';}else{h+='<p class="text-success">none</p>';}
     el.innerHTML=h;
   }).catch(function(){el.innerHTML='<p class="text-danger">Failed to load</p>';});
})();
</script>
<?php init_tail(); ?>
</body></html>

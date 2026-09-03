import fs from 'fs'; import path from 'path';
import { chromium } from 'playwright';
const src='/root/uiux/mockups', dist='/root/uiux/dist', png='/root/uiux/dist/png';
fs.mkdirSync(png,{recursive:true});
const css=fs.readFileSync(path.join(src,'azin-ds.css'),'utf8');
const shellT=fs.readFileSync(path.join(src,'_shell.html'),'utf8');
const files=fs.readdirSync(src).filter(f=>/^\d\d-.*\.html$/.test(f));
for(const f of files){
  let html=fs.readFileSync(path.join(src,f),'utf8');
  html=html.replace(/\{\{SHELL (\w+)\}\}/,(m,active)=>shellT.replace(/\{\{(\w+)\}\}/g,(m2,k)=>k===active?'active':''));
  html=html.replace('<link rel="stylesheet" href="azin-ds.css">','<style>'+css+'</style>');
  fs.writeFileSync(path.join(dist,f),html);
}
const browser=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
const shots=[];
for(const f of files){
  for(const [w,h,tag] of [[1440,900,'desktop'],[768,1024,'tablet'],[390,844,'phone']]){
    const page=await browser.newPage({viewport:{width:w,height:h},deviceScaleFactor:2});
    await page.goto('file://'+path.join(dist,f)); await page.waitForTimeout(150);
    const out=path.join(png,f.replace('.html','')+'-'+tag+'.png');
    await page.screenshot({path:out,fullPage:w!==390});
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth);
    const smallBtns=await page.evaluate(()=>[...document.querySelectorAll('button,a.se-btn')].filter(b=>b.offsetParent&&b.getBoundingClientRect().height<32).length);
    shots.push({f,tag,overflow,smallBtns});
    await page.close();
  }
}
await browser.close();
console.log(JSON.stringify(shots,null,0));

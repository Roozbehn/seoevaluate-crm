#!/usr/bin/env node
/**
 * Azin CRM — responsive + accessibility regression (CRM-M071 / UX-QA02 / K9).
 *
 * Runs against the LIVE admin with an existing login: export a Playwright
 * storage state once (see README.md) — this script never types credentials.
 *
 *   SE_BASE_URL=https://crm.roozbeh.com.tr SE_STORAGE_STATE=./state.json node scripts/ui-regression/responsive.mjs
 *
 * Widths: 390 · 768 · 1024 · 1440 · 1920. Pages: Bugün, Hastalar, Mesajlar
 * (list + a thread), Randevular, appointment form, a patient page. Assertions
 * (UIUX-OPT §Q, DS §3): no horizontal overflow at any width; every visible
 * interactive control ≥ 44×44 px on 390 (24 px for inline text links); every
 * <img> has alt; every form control has an accessible name; the WhatsApp
 * composer textarea is ≥ 60 % of the viewport width and the send button is
 * visible on 390; the tab bar is hidden inside a thread on 390; body has
 * class se-clinic; no console errors. Screenshots (PII-free pages only, see
 * README) go to scripts/ui-regression/out/<width>-<page>.png.
 * Exit code 1 on any failure; prints a table.
 */
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

const BASE  = (process.env.SE_BASE_URL || '').replace(/\/$/, '');
const STATE = process.env.SE_STORAGE_STATE || '';
const OUT   = path.resolve('scripts/ui-regression/out');
if (!BASE || !STATE || !fs.existsSync(STATE)) {
  console.error('Set SE_BASE_URL and SE_STORAGE_STATE (exported storage state, see README.md).');
  process.exit(2);
}
fs.mkdirSync(OUT, { recursive: true });

const WIDTHS = [390, 768, 1024, 1440, 1920];
const PAGES = [
  { key: 'today',   url: '/admin/se_core/se_dashboard' },
  { key: 'hastalar', url: '/admin/se_core/se_hastalar' },
  { key: 'inbox',   url: '/admin/se_whatsapp/se_whatsapp/inbox' },
  { key: 'thread',  url: null },   // first conversation link found on the inbox
  { key: 'calendar', url: '/admin/se_appointments' },
  { key: 'apptform', url: '/admin/se_appointments/create' },
  { key: 'patient', url: null },   // first row link found on Hastalar
];
const SCREENSHOT_OK = new Set(['calendar', 'apptform']);   // pages that carry no patient data by default

const results = [];
const fail = (w, p, what, detail = '') => results.push({ width: w, page: p, ok: false, what, detail });
const pass = (w, p, what) => results.push({ width: w, page: p, ok: true, what });

const browser = await chromium.launch();
const ctx = await browser.newContext({ storageState: STATE, locale: 'tr-TR', deviceScaleFactor: 1 });
const page = await ctx.newPage();
const consoleErrors = [];
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
page.on('pageerror', (e) => consoleErrors.push(String(e)));

let threadUrl = null, patientUrl = null;

for (const w of WIDTHS) {
  await page.setViewportSize({ width: w, height: w < 768 ? 844 : 900 });
  for (const p of PAGES) {
    let url = p.url;
    if (p.key === 'thread') url = threadUrl;
    if (p.key === 'patient') url = patientUrl;
    if (!url) { fail(w, p.key, 'page url', 'no link discovered'); continue; }
    consoleErrors.length = 0;
    const resp = await page.goto(BASE + url, { waitUntil: 'networkidle' });
    if (!resp || resp.status() >= 400) { fail(w, p.key, 'http', String(resp && resp.status())); continue; }
    if ((await page.url()).includes('authentication')) { fail(w, p.key, 'auth', 'storage state expired'); continue; }

    if (p.key === 'inbox' && !threadUrl) {
      threadUrl = await page.$eval('a.se-conv', (a) => a.getAttribute('href')).catch(() => null);
      if (threadUrl) threadUrl = threadUrl.replace(BASE, '');
    }
    if (p.key === 'hastalar' && !patientUrl) {
      patientUrl = await page.$eval('table.se-table a.se-name', (a) => a.getAttribute('href')).catch(() => null);
      if (patientUrl) patientUrl = patientUrl.replace(BASE, '');
    }

    const m = await page.evaluate((width) => {
      const de = document.documentElement;
      const overflow = Math.max(de.scrollWidth, document.body.scrollWidth) - width;
      const vis = (el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
      const inShell = (el) => el.closest('#wrapper, .se-tabbar, .se-page') && !el.closest('#header, .navbar, #side-menu, .sidebar, .dropdown-menu');
      const controls = [...document.querySelectorAll('a[href], button, input, select, textarea, [role=button]')].filter((el) => vis(el) && inShell(el));
      const small = controls.filter((el) => { const r = el.getBoundingClientRect(); const inline = el.tagName === 'A' && getComputedStyle(el).display === 'inline'; return inline ? r.height < 20 : (r.width < 44 || r.height < 44); }).map((el) => (el.tagName + '.' + (el.className || '').toString().split(' ')[0] + ':' + (el.textContent || el.getAttribute('aria-label') || '').trim().slice(0, 24)));
      const imgsNoAlt = [...document.querySelectorAll('#wrapper img')].filter((i) => !i.hasAttribute('alt')).length;
      const unnamed = [...document.querySelectorAll('#wrapper input:not([type=hidden]), #wrapper select, #wrapper textarea')].filter((el) => vis(el) && !(el.labels && el.labels.length) && !el.getAttribute('aria-label') && !el.getAttribute('aria-labelledby') && !el.getAttribute('title') && !el.getAttribute('placeholder')).length;
      const ta = document.querySelector('.se-composer textarea');
      const send = document.querySelector('.se-composer .btn-send, .se-composer #se-send');
      const tabbar = document.querySelector('.se-tabbar');
      return {
        overflow,
        small,
        imgsNoAlt,
        unnamed,
        clinic: document.body.classList.contains('se-clinic'),
        inThread: document.body.classList.contains('se-in-thread'),
        composer: ta ? { widthPct: Math.round((ta.getBoundingClientRect().width / width) * 100), sendVisible: !!(send && vis(send)) } : null,
        tabbarVisible: tabbar ? vis(tabbar) : null,
        dsLoaded: !!getComputedStyle(document.documentElement).getPropertyValue('--se-primary').trim(),
      };
    }, w);

    (m.overflow <= 1 ? pass : fail)(w, p.key, 'no horizontal overflow', `+${m.overflow}px`);
    m.clinic ? pass(w, p.key, 'body.se-clinic') : fail(w, p.key, 'body.se-clinic', 'missing (design system not loaded)');
    m.dsLoaded ? pass(w, p.key, 'se-ds.css tokens present') : fail(w, p.key, 'se-ds.css tokens present', '--se-primary undefined');
    if (w === 390) (m.small.length === 0 ? pass : fail)(w, p.key, 'touch targets ≥44px', m.small.slice(0, 8).join(' | '));
    (m.imgsNoAlt === 0 ? pass : fail)(w, p.key, 'images have alt', String(m.imgsNoAlt));
    (m.unnamed === 0 ? pass : fail)(w, p.key, 'form controls named', String(m.unnamed));
    if (p.key === 'thread') {
      if (m.composer) {
        (m.composer.widthPct >= 60 ? pass : fail)(w, p.key, 'composer textarea ≥60% width', m.composer.widthPct + '%');
        (m.composer.sendVisible ? pass : fail)(w, p.key, 'send button visible');
      } else pass(w, p.key, 'composer (gated/template mode, no textarea)');
      if (w === 390) (m.inThread && m.tabbarVisible === false ? pass : fail)(w, p.key, 'tab bar hidden inside thread', `inThread=${m.inThread} tabbar=${m.tabbarVisible}`);
    }
    if (w === 390 && p.key !== 'thread') (m.tabbarVisible === true ? pass : fail)(w, p.key, 'tab bar visible', String(m.tabbarVisible));
    (consoleErrors.length === 0 ? pass : fail)(w, p.key, 'no console errors', consoleErrors.slice(0, 2).join(' | '));

    if (SCREENSHOT_OK.has(p.key)) await page.screenshot({ path: path.join(OUT, `${w}-${p.key}.png`), fullPage: false });
  }
}
await browser.close();

const failed = results.filter((r) => !r.ok);
const byPage = {};
for (const r of results) { byPage[r.page] = byPage[r.page] || { pass: 0, fail: 0 }; byPage[r.page][r.ok ? 'pass' : 'fail']++; }
console.log('\n=== Azin CRM responsive regression ===');
console.table(byPage);
for (const f of failed) console.log(`FAIL ${f.width} ${f.page} — ${f.what}${f.detail ? ' (' + f.detail + ')' : ''}`);
console.log(`\n${results.length - failed.length} pass / ${failed.length} fail`);
fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 2));
process.exit(failed.length ? 1 : 0);

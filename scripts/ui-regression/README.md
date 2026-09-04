# UI regression (responsive + a11y) — CRM-M071

`responsive.mjs` drives Playwright against the **live** admin at five widths and
asserts the UIUX-OPT §Q rules (no horizontal overflow, 44 px touch targets on
phones, named controls, alt text, composer ≥ 60 % width, tab bar hidden inside a
thread, design system loaded, no console errors).

It never types credentials. Export a storage state once from a browser you
already signed in with:

```bash
cd ~/Developer/seoevaluate-crm
npm i -D playwright                       # once
npx playwright codegen --save-storage=scripts/ui-regression/state.json https://crm.roozbeh.com.tr/admin
#   → sign in in the window that opens, then close it; state.json holds the session cookie.
SE_BASE_URL=https://crm.roozbeh.com.tr SE_STORAGE_STATE=scripts/ui-regression/state.json node scripts/ui-regression/responsive.mjs
```

`state.json` and `out/` are git-ignored (session cookie; screenshots may show
patient names). Only the calendar and the empty appointment form are
screenshotted by default.


## Closure note (2026-09-04)

The suite was **not** run by the automation program: the session cookie is HttpOnly + Secure and the program never types credentials, so it cannot mint a storage state itself. Export one yourself (DevTools → Application → Cookies → save as `scripts/ui-regression/state.json`, which is git-ignored) and run `node scripts/ui-regression/responsive.mjs`. The same assertions were executed live through the owner's authenticated Chrome (same-origin iframes at 390/768/1024/1440/1920 on Bugün, Hastalar, patient workspace, Mesajlar list + thread, Instagram inbox + thread, Randevular, appointment form, Integration Health) — see `docs/verification/CRM-PRODUCTION-SIGNOFF-2026-09-04.md` §4. Delete `state.json` after a run.

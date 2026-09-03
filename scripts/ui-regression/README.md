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

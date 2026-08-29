# Google Authentication — Dependency Decision Record

**Decision: COMPATIBLE — adopt the official `google/auth` library. Implemented.**

This records the evaluation the earlier phase deferred. Google authentication is
now a real, renewable, library-backed provider — not merely an injection seam.

## Package
| | |
|---|---|
| Package | `google/auth` (official: googleapis/google-auth-library-php) |
| Version resolved | **v1.53.0** (released 2026-07-22), constraint `^1.53` |
| License | **Apache-2.0** |
| PHP constraint | `^8.1` — matches the pinned production platform 8.1.34 |
| Direct/transitive additions | `firebase/php-jwt` v7.1.0 (BSD-3), `psr/cache` 3.0.0 (MIT) — **only these three packages added** |
| Changes to the existing 70 locked packages | **none** (verified by lock diff) |
| composer audit | **no advisory** against google/auth, firebase/php-jwt or psr/cache. The 20 pre-existing advisories are all in packages Perfex already ships and are untouched. |
| Installed footprint | ~660 KB / 79 files under `application/vendor` |
| Runtime extensions | ext-openssl + ext-json only — both present on the server |
| Deployment | `composer.json` pins `platform.php 8.1.34`; the vendor tree was produced with `composer install` (not `require`) for a byte-exact reproduction of the evaluated lock |
| Rollback | single-commit revert of `composer.json` + `composer.lock` + the vendor delta |

## Implementation
`modules/se_core/se_google_auth.php` backs the default credential provider with
`Google\Auth\Credentials\ServiceAccountCredentials`. The service-account JSON is
read through the **file** secret provider (`se_secret_read('google_sa', brand)`)
and never touches the database or any log. Only a short-lived access token and
its expiry are cached in-process and refreshed before expiry. The HTTP handler
is injectable, so the flow is testable with no network. **JWT signing and the
OAuth exchange are the library's — no bespoke cryptography was written.**

With no key file installed every call is gated and nothing is sent.

## Offline test evidence
A synthetic RSA keypair is generated per test run and a synthetic
service-account document installed in the test secret store. The suite asserts: a
token is minted through the injected handler carrying a JWT assertion that
**verifies against the synthetic public key via firebase/php-jwt**; renewal after
expiry; no renewal before expiry; handler failure → sanitized error + gated
status; an unparseable key → configuration class; and the
submitted/confirmed/partial/failed lifecycle. Proven offline with dead HTTP(S)
proxies and a call-counted handler; a garbage key makes **zero** exchange
attempts. google suite 110/0; combined server fake-DB suite 1310/0.

## What remains externally gated (owner, not code)
Install a real service-account key file (mode 600, outside the document root, at
`SE_SECRET_DIR/google_sa_<brand>`), create the Google Cloud project / Data
Manager conversion actions, and make a real request. None of these is a code
change; activation is dropping a key file.

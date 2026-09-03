#!/usr/bin/env bash
# Staff-facing copy gate (CRM-M018 / UX-QA02 / UX-COPY §0).
#  1. Azin Asgari is Kaş Ekimi Uzmanı — never hekim/doktor/Dr./cerrah/klinisyen in Turkish staff strings.
#  2. Retired vocabulary must not reappear in Turkish staff strings: yolculuk, fırsat, potansiyel müşteri, aday, konsültasyon
#     ("Meta Lead Ads" is a product name and is allowed).
set -u
cd "$(dirname "$0")/.."
fail=0
TR="modules/se_core/language/turkish/se_core_lang.php modules/se_journey/language/turkish/se_journey_lang.php modules/se_whatsapp/language/turkish/se_whatsapp_lang.php modules/se_appointments/language/turkish/se_appointments_lang.php modules/se_instagram/language/turkish/se_instagram_lang.php"
if grep -niE "hekim|doktor|\bDr\.|cerrah|klinisyen" $TR 2>/dev/null; then echo "FAIL: forbidden professional title in Turkish staff strings"; fail=1; fi
if grep -niE "yolculu|fırsat|potansiyel müşteri|\baday\b|konsültasyon" $TR 2>/dev/null | grep -viE "Meta Lead Ads"; then echo "FAIL: retired vocabulary in Turkish staff strings"; fail=1; fi
# Staff views only. Patient-facing public views (modules/se_journey/views/public) carry approved patient copy and are
# reported separately (see docs/verification) — they are an owner decision, not a staff-UI rule.
if grep -rniE "hekim|doktor|\bDr\.|cerrah|klinisyen" modules/se_*/views modules/se_*/ui.php modules/se_core/se_chat_ui.php 2>/dev/null | grep -v "views/public/"; then echo "FAIL: forbidden title in a staff view"; fail=1; fi
[ $fail -eq 0 ] && echo "copy gate: OK"
exit $fail

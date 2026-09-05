# Aftercare protocol v2 — for approval (DEC-005, 2026-09-05)

**Status: awaiting the Kaş Ekimi Uzmanı's approval.** Until the box "Standart bakım takvimi (v2) onaylandı" is ticked in Ayarlar → Süreç ayarları → Klinik, no instruction below is sent to any patient; every step opens a staff task instead. The same calendar is published on the website recovery page (`/tr|en|fa|ar/recovery`, section "Bakım takvimi") as a `reviewRequired` section, i.e. kept out of search metadata until this approval.

Sources consulted (clinic post-operative sheets and recovery guides): [Ziering Medical post-op instructions](https://zieringmedical.com/eyebrows-or-facial-hair-transplantation-post-2/), [Kopelman Hair — eyebrow post-op timeline](https://kopelmanhair.com/blog/eyebrow-transplants-post-op/), [Transplant Eyebrow — aftercare guide (FUT sutures 10–14 d)](https://transplanteyebrow.com/eyebrow-transplant-aftercare-guide/), [Your Hair Center — day-by-day timeline](https://yourhaircenter.com/en/blog/eyebrow-transplant-recovery-day-by-day/), [Medlook — kaş ekimi sonrası bakım (TR)](https://medlook.com.tr/kas-ekimi-sonrasi-iyilesme-sureci-ve-bakim/), [Wimpole Clinic aftercare tips](https://wimpole.com/blog/eyebrow-transplant-aftercare-tips/). Where sources differ the more conservative figure was taken. These are general industry figures; **the specialist's own numbers prevail** — edit any figure before approving.

## 1. The calendar (day 0 = procedure day)

| Step key | When | What the patient receives | What staff sees |
|---|---|---|---|
| day0 | +6 h | **First 48 h instruction**: no touching/washing, sleep on the back with the head at ~45° for 3–4 nights, cold compress on forehead/temples (never on the brows), spray as instructed, no alcohol/smoking/aspirin/ibuprofen/strenuous effort, donor oozing → 15–20 min pressure with a clean cloth. Link to the calendar page | — |
| day1 | +24 h | **Day-1 instruction**: swelling/redness normal, may descend to eyelids; continue compress + spray; warning signs (increasing pain, spreading redness, discharge, fever) → write immediately; if unreachable → nearest health facility | — |
| day2 | +48 h | **Check-in** ("işleminizin 2. günündeyiz, nasıl hissediyorsunuz?" with the 112 line) — a reply is sealed as health data | unanswered after 48 h → task + `followup_due` |
| day3 | +72 h | **First-wash instruction**: first wash together at the clinic or exactly as shown (palm, no pressure, pat dry), crusts fall days 7–10, no make-up/pencil/serum yet | — |
| day7 | +7 d | **Crust-phase instruction**: crusts shed this week, don't rub; light walking OK; sweaty sport, sauna, hammam, pool, sea not before day 14; hat in sun > 10 min, glasses off the brows; **if donor sutures exist they are removed at the clinic on days 10–14, never at home** | — |
| day10 | +10 d | *(nothing to the patient)* | **Staff task**: "if the strip (FUT) method was used, book suture removal for days 10–14; if FUE, decide whether a control visit is needed" |
| day14 | +14 d | **Photo request** (existing approved template) — control photograph once the crusts are gone | unanswered → task |
| day21 | +21 d | **Shedding instruction**: expected shedding weeks 2–6, the root is not lost; brow make-up/tint after week 4; waxing/threading/tweezing not before 6 months; heavy weights/contact sports after week 3 | — |
| month1 | +30 d | **Photo request** | unanswered → task |
| month3 | +90 d | **Growth instruction**: fine light hairs from month 3; transplanted hairs are scalp hairs → trim every 2–4 weeks with small scissors to the brow line; peels/skin treatments/sunbeds away from the brows; SPF 30+ outdoors; the clinic will contact for the 6-month control | — |
| month3p | +91 d | **Photo request** | unanswered → task |
| month6t | +179 d | *(nothing)* | **Staff task**: call the patient, book the 6-month control + first shaping trim |
| month6 | +180 d | **Photo request** | unanswered → task |
| month12t | +359 d | *(nothing)* | **Staff task**: call the patient, book the final control; additional session is judged here |
| month12 | +360 d | **Photo request** (final) | unanswered → task |

All patient messages go through the normal queue: quiet hours and the daily cap apply; inside the 24 h window the fuller Turkish text goes as free text, outside it the matching Meta-approved UTILITY template goes (name + calendar link). Urgent keywords in any reply are handled before the protocol (existing behaviour).

## 2. Patient-facing texts (Turkish, as they will be sent)

**day0 (template `eyebrow_aftercare_day0_tr`)**
> Merhaba {{ad}}, ilk 48 saat için hatırlatma: kaş bölgesine dokunmayın ve yıkamayın, ilk 3-4 gece başınız yüksekte sırtüstü uyuyun, soğuk kompresi alın ve şakaklara uygulayın (kaşlara değil), spreyi tarif edilen sıklıkta kullanın. Alkol, sigara, aspirin/ibuprofen ve ağır efor yok. Takvimin tamamı: {{bağlantı}} — Azin Asgari, Kaş Ekimi Uzmanı.

**day1 (`eyebrow_aftercare_day1_tr`)**
> Merhaba {{ad}}, işlemin 1. günü. Hafif şişlik ve kızarıklık normaldir; şişlik göz kapaklarına inebilir. Soğuk kompres ve sprey ile devam edin, kaşlara dokunmayın. Yarın nasıl olduğunuzu soracağız; artan ağrı, yayılan kızarıklık, akıntı veya ateş olursa beklemeden yazın. Takvim: {{bağlantı}} — Azin Asgari, Kaş Ekimi Uzmanı.

**day2 (existing `eyebrow_aftercare_checkin_tr`)**
> Merhaba {{ad}}, işleminizin 2. günündeyiz. Nasıl hissediyorsunuz? Lütfen kısaca yazın; ağrı, şişlik, kızarıklık veya başka bir durum varsa belirtin. Şiddetli ya da hızla kötüleşen bir şikâyetiniz olursa lütfen beklemeden 112'yi arayın.

**day3 (`eyebrow_aftercare_day3_tr`)**
> Merhaba {{ad}}, bugün 3. gün: ilk yıkama günü. İlk yıkamayı klinikte birlikte yapıyoruz veya size gösterildiği gibi avuçla, basınç uygulamadan yapın. Kabuklar 7-10. günde kendiliğinden kalkar; koparmayın. Makyaj, kaş kalemi ve serum henüz yok. Takvim: {{bağlantı}} — Azin Asgari, Kaş Ekimi Uzmanı.

**day7 (`eyebrow_aftercare_day7_tr`)**
> Merhaba {{ad}}, 7. gün. Kabuklar bu hafta dökülür; ovalamayın. Hafif yürüyüş serbest, terleten spor, sauna, hamam ve havuz 14. güne kadar yok. Güneşte 10 dakikadan uzun kalacaksanız şapka kullanın. Donör bölgede dikiş varsa 10-14. gün arasında klinikte alınır. Takvim: {{bağlantı}} — Azin Asgari, Kaş Ekimi Uzmanı.

**day14 / month1 / month3p / month6 / month12 (existing `eyebrow_followup_photo_request_tr`)**
> Merhaba {{ad}}, takip için kaşlarınızın güncel fotoğraflarını rica ediyoruz (tam karşıdan, sol ve sağ yakın plan; makyajsız, aydınlık ortam). Güvenli yükleme bağlantısı: {{bağlantı}}. Fotoğraflar yalnızca takip amacıyla işlenir.

**day21 (`eyebrow_aftercare_day21_tr`)**
> Merhaba {{ad}}, 3. hafta. Ekilen kılların dökülmesi bu dönemde beklenir ve 6. haftaya kadar sürebilir; kök kaybolmaz, dinlenme evresine girer. Kaş makyajı ve boya 4. haftadan sonra serbest; ağda, iplik ve cımbız 6 aydan önce yok. Ağır spor 3. haftadan sonra başlar. Takvim: {{bağlantı}} — Azin Asgari, Kaş Ekimi Uzmanı.

**month3 (`eyebrow_aftercare_month3_tr`)**
> Merhaba {{ad}}, 3. ay. Yeni kıllar ince ve açık renkli çıkmaya başlar. Ekilen kıllar saç kökü olduğu için hızlı uzar: 2-4 haftada bir küçük makasla kaş hattına göre kısaltın. Peeling ve solaryum kaş bölgesinden uzak, dışarıda SPF 30+ kullanın. 6. ayda kontrol için haber vereceğiz. Takvim: {{bağlantı}} — Azin Asgari, Kaş Ekimi Uzmanı.

The in-window (free-text) versions carry the same content with a little more detail (e.g. the 45° angle, "kâğıt havluyla kurulayın", "bize ulaşamıyorsanız en yakın sağlık kuruluşuna başvurun"); they are in `modules/se_journey/aftercare.php`.

## 3. What is deliberately NOT automated

- No medication or cream decision is ever sent automatically; the texts only refer the patient to their written instructions.
- The suture question is a staff task because it depends on the donor technique (FUE has no sutures); the patient is only told sutures — if any — are removed at the clinic.
- Control visits at 6 and 12 months are staff tasks (a call + appointment), not self-booking links.
- Nothing is sent to a patient who has opted out, outside quiet hours, or beyond the daily cap; every unanswered check-in/photo request becomes a task after 48 h.

## 4. How to approve or decline

- **Approve:** Ayarlar → Süreç ayarları → Klinik → tick "Standart bakım takvimi (v2) onaylandı" → Kaydet. From then on a completed procedure starts the plan automatically. The six new WhatsApp templates must also be approved by Meta (they are submitted from Ayarlar → Şablonlar; UTILITY templates are usually approved within minutes to a day). Until Meta approves a template, that step is queued and shown as blocked on Health — never lost.
- **Decline / edit:** reply with the changed figures or texts; every number above is a parameter, and the website section is flipped to visible only after this approval.

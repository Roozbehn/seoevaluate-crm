# Azin CRM — Türkçe UX Copy & Terminology System

**Rule 0:** one concept → one word, everywhere (sidebar, badges, buttons, task titles, timeline, tracker, WhatsApp templates, reports). English never appears in a staff-facing string. Azin Asgari is **Kaş Ekimi Uzmanı** — never *hekim, doktor, Dr., cerrah, klinisyen*. Patient-facing WhatsApp copy already lives in `se_journey/messaging.php` and is approved; this document governs the **staff UI**.

## 1. Canonical glossary

| Concept | Canonical TR | Never use | Notes |
|---|---|---|---|
| the person in the system (any stage) | **Hasta** | aday, potansiyel müşteri, fırsat, lead, müşteri, kişi | Stage 1 is called *Yeni talep*, but the record is always a Hasta. The Perfex Lead/Customer split is invisible to staff. |
| first stage | **Yeni talep** | yeni sorgu, inquiry | |
| WhatsApp / Instagram thread | **Sohbet** | görüşme, konuşma, mesajlaşma | plural *Sohbetler*; menu *Mesajlar* |
| pre-procedure appointment with Azin | **Ön görüşme** | konsültasyon, görüşme (alone), muayene | |
| the procedure | **Kaş ekimi** | işlem (alone), operasyon, ameliyat, cerrahi | *İşlem* only as the tab label "İşlem & Bakım" |
| post-op period | **Bakım** | aftercare, iyileşme | |
| scheduled post-op contact | **Takip** | follow-up, kontrol mesajı | *Kontrol* = the in-clinic check appointment |
| in-clinic check appointment | **Kontrol** | takip randevusu | |
| KVKK consent | **Rıza** | onay, izin (except *Fotoğraf yayın izni* — legal term stays) | *Aydınlatma metni* for the notice |
| staff sign-off (quote, review) | **Onay** | rıza, approve | verb *onayla* |
| the evaluation questionnaire | **Değerlendirme formu** | intake, anket | stage *Değerlendirme* |
| Azin's internal review | **İnceleme** | review, değerlendirme (reserved for the form) | verb *incele* |
| suitability decision | **Uygunluk kararı** | suitability | values: *Ön uygun · Ön görüşme gerekli · Ek bilgi gerekli · Uygun değil* |
| price proposal | **Teklif** | fiyat, estimate, proposal | statuses in §3 |
| automatic bot messages | **Otomatik mesajlar** | otomasyon, bot, yolculuk otomasyonu | states *Açık · Duraklatıldı · Kapalı* |
| the patient's process record | **Hasta süreci** (UI) | yolculuk (legacy code name stays internal) | Menu item removed; the process lives on the patient page |
| next thing staff must do | **Sonraki adım** | next action, görev | |
| staff to-do created by the system | **Bekleyen iş** | task, görev | "Bekleyen işler" list |
| assigned staff | **Sorumlu** | atanan, assignee | |
| 24 h WhatsApp service window | **Yanıt penceresi** | hizmet penceresi, window | *açık / kapalı · … kaldı* |
| approved template | **Şablon** | template | |
| lead source | **Kaynak** | source | values: *Instagram reklamı · Meta Lead Ads · Web sitesi · WhatsApp · Instagram DM · Tavsiye* |
| the queue that sends messages | **Gönderici** | dispatcher | "Gönderici 40 sn önce çalıştı" |
| photo set | **Fotoğraflar** | medya, görseller | *tam karşıdan · sol kaş · sağ kaş · donör* |
| price range | **Fiyat aralığı** | range | |

## 2. Navigation labels

| Item | Label | Who |
|---|---|---|
| Action dashboard | **Bugün** | all |
| Unified people list | **Hastalar** | all |
| WhatsApp + Instagram inbox | **Mesajlar** (tabs *WhatsApp · Instagram*) | all |
| Appointments | **Randevular** | all |
| Reports | **Raporlar** | owner, admin |
| Integrations group | **Entegrasyonlar** (Meta Lead Ads · Dönüşüm kuyruğu · Google · Sistem sağlığı · Kimlik bilgileri) | admin |
| Settings | **Ayarlar** (Rıza metinleri · Süreç ayarları · Şablonlar · Perfex kurulumu) | admin |
| Bottom tab bar (phone) | Bugün · Hastalar · Mesajlar · Randevu · Diğer | all |

## 3. Status vocabulary (badge text)

### 3.1 Macro-stages (stage bar, list badges)
Talep · Değerlendirme · İnceleme · Teklif · Ön görüşme · Kaş ekimi · Bakım (+ terminal: Tamamlandı · Kapatıldı · Uygun değil · Vazgeçti)

### 3.2 Journey state → badge label → macro-stage

| `state` | Badge (TR) | Stage | Colour |
|---|---|---|---|
| new_whatsapp_enquiry | Yeni talep | Talep | info |
| welcome_sent | Karşılama gönderildi | Talep | info |
| privacy_notice_sent / consent_pending / intake_link_sent | Rıza bekleniyor | Değerlendirme | warning after 24 h |
| consent_declined | Rıza verilmedi | Değerlendirme | inactive |
| intake_started | Form dolduruluyor | Değerlendirme | info |
| intake_submitted | Form geldi | Değerlendirme | positive |
| intake_incomplete | Form yarım kaldı | Değerlendirme | warning |
| photos_requested | Fotoğraf bekleniyor | Değerlendirme | info / warning after 72 h |
| photos_incomplete | Fotoğraflar eksik | Değerlendirme | warning |
| photo_retake_requested | Yeniden çekim istendi | Değerlendirme | info |
| ready_for_review | **Fotoğraflar geldi** | İnceleme | **action** |
| under_review | İnceleniyor | İnceleme | action |
| more_information_required | Ek bilgi istendi | İnceleme | warning |
| not_suitable | Uygun değil | — | inactive |
| consultation_recommended | Ön görüşme planlanacak | Ön görüşme | action |
| quote_pending_staff_approval | **Teklif · onay bekliyor** | Teklif | action |
| quote_sent | Teklif · yanıt bekleniyor | Teklif | info → warning after 3 d |
| quote_accepted | Teklif kabul edildi | Teklif | positive |
| quote_revision_requested | Revizyon istendi | Teklif | action |
| (new) quote_expired | Teklif süresi doldu | Teklif | warning |
| consultation_booked | Ön görüşme planlandı | Ön görüşme | info |
| consultation_completed | Ön görüşme yapıldı | Ön görüşme | positive (action if outcome missing) |
| procedure_booked | Kaş ekimi planlandı | Kaş ekimi | info |
| preop_pending | İşlem öncesi hazırlık | Kaş ekimi | info |
| procedure_completed | Kaş ekimi yapıldı | Kaş ekimi | positive |
| aftercare_active | Bakım · N. gün | Bakım | positive |
| followup_due | Takip yanıtı bekleniyor | Bakım | warning |
| completed | Tamamlandı | — | positive |
| opted_out | Vazgeçti (İPTAL) | — | inactive |
| closed_lost | Kapatıldı | — | inactive |

Automation: `active` → **Açık**, `paused_staff` → **Duraklatıldı (personel)**, `paused_urgent` → **Duraklatıldı (acil)**, `disabled` → **Kapalı**, `error` → **Hata**.

### 3.3 Quote statuses
Taslak · Onay bekliyor · Onaylandı · Gönderildi · Kabul edildi · Revizyon istendi · Süresi doldu · Geri çekildi

### 3.4 Appointment types and statuses
Types: **Ön görüşme · Kaş ekimi · Kontrol · Takip**. Statuses: Planlandı · Onaylandı · Yapıldı · Tamamlandı · Gelmedi · İptal edildi. Format: Klinikte · Çevrimiçi.

### 3.5 Consent badges
Sağlık verisi ✓/✗ · Pazarlama ✓/✗ · Fotoğraf yayını ✓/✗ (never `health / marketing / publication`).

## 4. Next-action sentences (`se_journey_next_action`)

Pattern: **verb-first imperative + object**, ≤ 45 characters; the reason line gives the age and the evidence.

| Situation | Sonraki adım | Reason line | Button |
|---|---|---|---|
| ready_for_review | Gönderilen kaş fotoğraflarını inceleyin | 3 fotoğraf 42 dk önce geldi · form tamam | Fotoğrafları incele |
| under_review, no decision | Uygunluk kararını kaydedin | inceleme 1 sa önce açıldı | Kararı kaydet |
| quote_pending_staff_approval | Teklif v2'yi onaylayın ve gönderin | Roozbeh hazırladı · 3 sa önce | Teklifi onayla |
| quote_sent > 3 d | Hastaya teklifi hatırlatın | 3 gündür yanıt yok · teklif 26 gün geçerli | Hatırlat |
| quote_revision_requested | Teklifi revize edin | hasta fiyat revizyonu istedi · dün | Yeni sürüm |
| consultation_recommended | Ön görüşme planlayın | hasta takvim bağlantısını kullanmadı · 2 g | Randevu oluştur |
| consultation_completed, no outcome | Ön görüşme sonucunu kaydedin | bugün 12:30 · Klinik | Sonucu kaydet |
| procedure_completed, no plan | Bakım planını başlatın | kaş ekimi bugün 14:00'te tamamlandı | Planı başlat |
| followup_due | Takip mesajına yanıt vermeyen hastayı arayın | 48 sa yanıt yok · 7. gün | Ara / Yaz |
| paused_staff | Otomatik mesajları devam ettirin | son personel yanıtı 2 g önce | Devam ettir |
| urgent flag | Hastanın acil mesajını yanıtlayın | "ağrı" · 18 dk önce | Yanıtla |
| wa_delivery_failed | Mesaj iletilemedi — hastayı arayın | Meta hatası 131026 · numara WhatsApp'ta değil | Ara |
| patient-owned wait (intake, photos) | Hasta bekleniyor — hatırlatma otomatik | 24 sa hatırlatma 3 sa sonra | (none, ghost "Şimdi hatırlat") |

## 5. Message patterns

**Buttons** — verb first, specific: *Fotoğrafları incele · Teklifi onayla · Hastaya gönder · Randevu oluştur · Bugün işlem planla · Görüşme yapıldı · Planı başlat · Devam ettir · Kaybedildi olarak kapat*. Never *Kaydet/Gönder/Tamam* alone when the object is ambiguous; never *Convert*.

**Confirm dialogs** — title = the action + object, body = consequence, buttons = action / keep:
- "Hasta kaydını kapat?" — "Elif K. için otomatik mesajlar durur ve kayıt Kapatıldı olarak işaretlenir. Daha sonra yeniden açabilirsiniz." — [Kapat] [Vazgeç]
- "Teklif v2'yi hastaya gönder?" — "WhatsApp mesajı ve güvenli teklif sayfası gönderilir. Gönderilen teklif dondurulur; değişiklik yeni sürüm oluşturur." — [Gönder] [Vazgeç]

**Errors** — what + why + how:
- Conflict: "Bu saatte Azin Asgari için başka bir randevu var (14:00–18:00 Kaş ekimi). İlk uygun saat 18:30."
- Window closed: "Yanıt penceresi kapalı — hasta 24 saattir yazmadı. Onaylı bir şablon gönderebilirsiniz."
- Template variable missing: "Şablonun {{1}} alanı boş. Hastanın adını girin."
- Send blocked: "Mesaj gönderilmedi: otomatik mesajlar duraklatılmış. Devam ettirin veya elle yazın."

**Empty states**
- Bugün: "Bugün için bekleyen iş yok. Yeni talepler geldiğinde burada görünür."
- Mesajlar (filter): "Bu filtrede sohbet yok. Filtreyi kaldırın veya arama yapın."
- Fotoğraflar: "Henüz fotoğraf gelmedi. Fotoğraf isteği 2 sa önce gönderildi; 24 saat sonra otomatik hatırlatılır."
- Randevular: "Bu hafta randevu yok. [Randevu oluştur]"

**Toasts** — past tense, object, undo where safe: "Teklif v2 hastaya gönderildi." · "Otomatik mesajlar duraklatıldı — [Geri al]". Duration 5 s, `role="status"`.

**Loading** — "Sohbet yükleniyor…", never spinners without text on full screens.

## 6. Timeline event labels (`se_journey_event_label`)

| kind / transition | Label |
|---|---|
| inbound text | Hasta yazdı |
| inbound image | Hasta fotoğraf gönderdi |
| wa_outbound welcome | Karşılama mesajı gönderildi |
| privacy_and_flow | Aydınlatma metni ve form gönderildi |
| consent_recorded health=yes | Sağlık verisi rızası verildi |
| intake_saved | Form kaydedildi (bölümler: kimlik, şikâyet) |
| intake_submitted | Değerlendirme formu tamamlandı |
| photos_request | Fotoğraf isteği gönderildi |
| photos_partial_ack | Eksik fotoğraf bildirimi gönderildi (2/3) |
| photos_received / → ready_for_review | 3 fotoğraf alındı — inceleme için hazır |
| → under_review | İnceleme başladı (Azin) |
| review decision X | Uygunluk kararı: Ön uygun |
| quote_prepared | Teklif v1 hazırlandı (Roozbeh) |
| quote_approved | Teklif v1 onaylandı (Azin) |
| quote_sent | Teklif v1 hastaya gönderildi |
| quote_viewed | Hasta teklifi görüntüledi |
| jr_quote_accept | Hasta teklifi kabul etti |
| jr_quote_revise | Hasta fiyat revizyonu istedi |
| booking_flow | Takvim bağlantısı gönderildi |
| appointment scheduled | Ön görüşme oluşturuldu — 8 Eyl 13:30 |
| appointment held | Ön görüşme yapıldı |
| consultation_information | Bilgilendirme bağlantıları gönderildi |
| preop_information | İşlem öncesi bilgilendirme gönderildi |
| procedure_completed | Kaş ekimi tamamlandı |
| aftercare step | Bakım mesajı gönderildi — 2. gün |
| followup_due | Takip mesajına 48 sa yanıt yok |
| optout | Hasta vazgeçti (İPTAL) |
| handoff | Hasta bir kişiyle görüşmek istedi |
| urgent | Acil belirti bildirimi — otomatik mesajlar duraklatıldı |
| wa_delivery_failed | Mesaj iletilemedi (Meta 131026) |
| staff reply | Personel yanıtladı (Roozbeh) |
| paused_staff | Otomatik mesajlar duraklatıldı (personel yanıtı) |
| resume | Otomatik mesajlar devam ettirildi |
| lead_sync | Kayıt güncellendi (Perfex) — *hidden by default* |

Transitions never render as `a → b`; the stage change is implied by the event.

## 7. Outbound tracker (per thread)

"Yanıtlar kuyruğa alınır ve her dakika çalışan gönderici tarafından iletilir — sonraki çalışma 23:00:40. İletim bilgileri bir sonraki çalışmada güncellenir."
Rows: **Kuyrukta — bir dakika içinde gönderilir · Gönderiliyor · Gönderildi · İletildi · Okundu · Bekletiliyor — yanıt penceresi kapalı · Bekletiliyor — şablon onaysız · Gönderilemedi — <Meta reason> · Atlandı — <reason>**. Headers: Mesaj · Durum · Kuyruğa alındı.

## 8. Rewrites of existing strings (lang keys)

| Key | Now | New |
|---|---|---|
| se_wa_window_until | şu ana kadar açık %s | Şu saate kadar açık: %s |
| se_appt_lead | Potansiyel müşteri | Hasta |
| se_journey_lead_sync | Fırsata eşitle | Kaydı güncelle |
| se_journey_reason_no_lead | lead yok | Hasta kaydı yok |
| se_wa_conversation(s) | Görüşme(ler) | Sohbet(ler) |
| se_rep_consultations_held | Yapılan konsültasyonlar | Gerçekleşen ön görüşmeler |
| se_consent_* "Onay …" | Onay ayarları / geçmişi | Rıza metinleri / Rıza geçmişi |
| se_journey_preop_approved | …hukuk ve hekim onaylı | …hukuk ve Kaş Ekimi Uzmanı onaylı |
| se_journey_procedure_notes | Klinisyen / personel notları | Uzman / personel notları |
| se_wa_sending_gated | Gönderim yapılandırılmadı | Mesaj gönderimi kapalı |
| se_journey_close | Kapat (kayıp) | Kaybedildi olarak kapat |
| se_journey_state_followup_due | Takip gecikti | Takip yanıtı bekleniyor |
| se_journey_counter_consultation_due | Görüşme planlanacak | (split) Teklif yanıtı bekleniyor / Ön görüşme planlanacak |
| se_journey_classify | Ayarla | Kaydet |
| se_journey_start | Değerlendirmeyi başlat | Değerlendirmeyi başlat ✓ (keep) |
| se_journey_welcome_send | Karşılama gönder (başlat) | Karşılama mesajı gönder |
| se_journey_resend_link | Güvenli bağlantıyı yeniden gönder | Form bağlantısını yeniden gönder |
| se_journey_pause / resume | Otomasyonu duraklat / sürdür | Otomatik mesajları duraklat / devam ettir |
| se_journey_reactivate | Yeniden etkinleştir (kanıt) | Yeniden aç (hasta yazdı) |
| task: Intake submitted — review answers and photos | (EN) | Form geldi — yanıtları ve fotoğrafları inceleyin |
| task: Patient asked for a human… | (EN) | Hasta bir kişiyle görüşmek istiyor — WhatsApp'tan yanıtlayın |
| task: No response after the final reminder… | (EN) | Son hatırlatmaya yanıt yok — arayın, bekleyin ya da kapatın |
| task: Quote vN awaits approval | (EN) | Teklif vN onay bekliyor |
| task: Consultation held — record outcome… | (EN) | Ön görüşme yapıldı — sonucu ve sonraki adımı kaydedin |
| Dashboard cards Leads / Patients / No-show | (EN, all-time) | removed |
| Perfex "Convert to customer" | | hidden for clinic roles |
| Perfex pipeline stages | 14 EN stages | Yeni talep · Değerlendirme · İnceleme · Teklif · Ön görüşme · Kaş ekimi · Bakım · Tamamlandı · Kapatıldı |

## 9. Phone and number formatting

`se_ui_phone()`: E.164 in storage, display `+90 5xx xxx xx xx`; masked by default for Sales role and in lists (`+90 5•• ••• 27 41`), full on the patient page for roles with `view_contact`. Ages: `18 dk · 3 sa · 2 g · 3 hf`; absolute dates `4 Eyl 14:00`, with year only if not current. Money: `45.000 – 50.000 TL`.

## 10. Language attribute

`<html lang>` follows the staff member's Perfex language (tr/en/fa/ar); patient-content blocks (chat bubbles, quoted timeline bodies) carry `lang` of the patient's preferred language when it differs.

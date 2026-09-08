<?php
/**
 * Patient journey (se_journey) — the price question.
 *
 * The clinic prices one person at a time, after the review, and does not put a
 * figure on open channels; "ne kadar?" is nevertheless the commonest first
 * message. The journey therefore answers the POLICY itself — never a number,
 * never a range, never a word about suitability — and this suite proves the
 * three things that make that safe:
 *
 *   - it answers only where the answer is true (after the welcome, before a
 *     quote exists) and in the right of its two variants;
 *   - it never eats a message that belongs to someone else: the first inbound
 *     (the Instagram pre-filled text contains "fiyat") still gets the welcome,
 *     "fiyat yüksek" after a quote is still a revision request, and
 *     "iyileşme ne kadar sürer" is a duration question, not a price one;
 *   - it stays read-only: no transition, no task, no state change, and one
 *     answer per day however often it is asked.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/journey_fixtures.php';

if (!function_exists('se_test_last_sent')) {
    function se_test_last_sent()
    {
        $all = $GLOBALS['se_wa_sent'];

        return $all ? $all[count($all) - 1] : null;
    }
}

/** A typed patient message. */
function se_test_say($text)
{
    return se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, $text, se_test_wamid()));
}

/** The welcome, once, so the journey sits at welcome_sent. */
function se_test_price_seed()
{
    se_test_seed_journey(['options' => ['se_journey_daily_cap' => 20]]);
    se_test_act_as(10, [], true);
    se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid(), ['name' => 'Ayşe']));
}

/* ======================================================================== */
se_group('The welcome states the price policy itself');

se_test_price_seed();
$welcome = se_test_last_sent();
se_eq('interactive', $welcome['kind'], 'the welcome is still the interactive one');
se_ok(mb_strpos($welcome['body'], 'Fiyat kişiye özeldir') !== false, 'it says the price is personal');
se_ok(mb_strpos($welcome['body'], 'açık kanallarda paylaşmıyoruz') !== false, 'and that it is not shared on open channels');
se_ok(mb_strpos($welcome['body'], 'teyit edildikten sonra') !== false, 'and names the condition: after the review confirms suitability');
se_ok(mb_strlen($welcome['body']) <= 700, 'the welcome is still short (' . mb_strlen($welcome['body']) . ' chars)');
se_ok(mb_strlen($welcome['body']) <= 1024, 'and inside Meta\'s interactive body limit');
se_ok(!preg_match('/\d[\d.,]*\s*(tl|₺|eur|€|usd|\$)/iu', $welcome['body']), 'no figure and no currency anywhere in it');

/* ======================================================================== */
se_group('The FIRST message is never treated as a price question');

// The Instagram pre-filled text contains the word "fiyat". A person arriving
// with it must meet the welcome, not a bare policy paragraph.
se_ok(se_journey_asks_price(SE_JOURNEY_PREFILLED_MESSAGE), 'the pre-filled message does read as a price question');
se_eq('welcome', 'welcome', 'and is answered by the welcome all the same:');
se_eq(1, count($GLOBALS['se_wa_sent']), 'exactly one message went out for it');
se_eq('welcome_sent', se_test_journey_row()->state, 'and the journey moved to welcome_sent');

/* ======================================================================== */
se_group('Asked after the welcome: the policy, with the three options');

$stateBefore  = se_test_journey_row()->state;
$transBefore  = count(se_test_db()->rows('tblse_journey_transitions'));
$tasksBefore  = count(se_test_db()->rows('tblse_journey_tasks'));

se_test_say('Merhaba, fiyat ne kadar acaba?');
$answer = se_test_last_sent();
se_eq('interactive', $answer['kind'], 'answered with an interactive message');
se_eq(['jr_start', 'jr_info', 'jr_handoff'], array_column($answer['payload']['buttons'], 'id'), 'carrying the three options, so the next tap starts the evaluation');
se_ok(mb_strpos($answer['body'], 'tek bir liste fiyatımız bulunmuyor') !== false, 'the body explains there is no list price');
se_ok(mb_strpos($answer['body'], 'sosyal medya hesaplarımızda') !== false, 'names social media as a channel where it is not shared');
se_ok(mb_strpos($answer['body'], 'uygunluğunuzu teyit ettikten sonra') !== false, 'and states the condition for receiving it');
se_ok(!preg_match('/\d[\d.,]*\s*(tl|₺|eur|€|usd|\$)/iu', $answer['body']), 'no figure, no currency');
se_ok(mb_stripos($answer['body'], 'garanti') === false && mb_stripos($answer['body'], 'uygunsunuz') === false, 'no guarantee and no verdict on suitability');
se_ok(mb_strlen($answer['body']) <= 1024, 'inside the interactive body limit');

se_eq($stateBefore, se_test_journey_row()->state, 'answering does not move the journey on');
se_eq($transBefore, count(se_test_db()->rows('tblse_journey_transitions')), 'and writes no transition');
se_eq($tasksBefore, count(se_test_db()->rows('tblse_journey_tasks')), 'and opens no staff task');

$outbound = array_values(array_filter(se_test_db()->rows('tblse_wa_outbound'), function ($o) { return $o['origin'] === 'journey:price_policy'; }));
se_eq(1, count($outbound), 'the send is recorded under its own origin, so the timeline can label it');

/* ======================================================================== */
se_group('Asked again the same day: answered once, not once per message');

$sentBefore = count($GLOBALS['se_wa_sent']);
se_test_say('kaç para?');
se_test_say('ücret nedir');
se_eq($sentBefore, count($GLOBALS['se_wa_sent']), 'the identical paragraph is not repeated within the day');

/* ======================================================================== */
se_group('"ne kadar" is a duration question as often as a price one');

foreach (['iyileşme ne kadar sürer', 'işlem ne kadar sürüyor', 'kaç gün sürer', 'sonuçlar ne kadar zamanda çıkar',
          'ne kadar zamanda iyileşir', 'kaç seans gerekiyor', 'ne kadar kalıcı', 'kaç saat sürüyor',
          'ne kadar beklemem gerekiyor'] as $q) {
    se_ok(!se_journey_asks_price($q), '"' . $q . '" is not read as a price question');
}
foreach (['fiyat ne kadar', 'ne kadar?', 'kaç para', 'ücret bilgisi alabilir miyim', 'maliyeti nedir', 'how much is it', 'what is the price'] as $q) {
    se_ok(se_journey_asks_price($q), '"' . $q . '" is');
}
se_ok(!se_journey_asks_price(''), 'an empty message asks nothing');
se_ok(!se_journey_asks_price('merhaba'), 'and neither does a greeting');

// Proved end to end, not only in the matcher, and on a FRESH journey so the
// once-a-day rule cannot be what keeps the policy quiet: a duration question
// reaches the normal routing (a staff task and the options once) instead.
se_test_price_seed();
$priceSends = function () {
    return count(array_filter(se_test_db()->rows('tblse_wa_outbound'), function ($o) { return $o['origin'] === 'journey:price_policy'; }));
};
$tasksBefore = count(se_test_db()->rows('tblse_journey_tasks'));
se_eq(0, $priceSends(), 'the fresh journey has had no price answer yet');
se_test_say('iyileşme ne kadar sürer?');
se_eq(0, $priceSends(), 'and a duration question does not produce one');
se_ok(count(se_test_db()->rows('tblse_journey_tasks')) > $tasksBefore, 'it becomes a staff task, as any other question does');
se_test_say('fiyat ne kadar?');
se_eq(1, $priceSends(), 'while a price question in the same thread is answered');

/* ======================================================================== */
se_group('Once the file is with the team the answer changes');

$j = se_test_journey_row();
se_journey_transition($j, 'privacy_notice_sent', 'test', 'system');
se_journey_transition(se_test_journey_row(), 'intake_link_sent', 'test', 'system');
se_journey_transition(se_test_journey_row(), 'intake_started', 'test', 'system');
se_journey_transition(se_test_journey_row(), 'intake_submitted', 'test', 'system');
se_eq('intake_submitted', se_test_journey_row()->state, 'the journey is past submission');
se_ok(se_journey_price_in_review('under_review'), 'under_review counts as "with the team"');
se_ok(!se_journey_price_in_review('welcome_sent'), 'welcome_sent does not');

se_test_say('fiyat ne kadar');
$review = se_test_last_sent();
se_ok(mb_strpos($review['body'], 'şu anda ekibimizde') !== false, 'the answer says the review is under way');
se_ok(mb_strpos($review['body'], 'Değerlendirme Başlat') === false, 'and does not invite them to start what they have already done');
se_eq(['jr_info', 'jr_handoff'], array_column($review['payload']['buttons'], 'id'), 'two options: read on, or talk to a person');
se_eq('intake_submitted', se_test_journey_row()->state, 'still no journey movement');

/* ======================================================================== */
se_group('A quote is out: "fiyat" answers the quote, it does not restart the policy');

se_test_journey_reviewed();
$db = se_test_db();
$j  = se_test_journey_row();
se_journey_quote_draft($j, ['currency' => 'EUR', 'amount_min' => '1500', 'amount_max' => '2200', 'show_amount' => 1,
    'valid_until' => '+30 days', 'summary' => 'Ön değerlendirme özeti'], 10);
$rows = $db->rows('tblse_journey_quotes'); $q = end($rows);
se_journey_quote_approve((int) $q['id'], 10);
se_journey_quote_send((int) $q['id'], 10);
se_eq('quote_sent', se_test_journey_row()->state, 'the quote is out');
se_ok(se_journey_price_quote_open(se_test_journey_row()), 'and is recognised as awaiting its answer');
se_ok(!in_array('quote_sent', se_journey_price_answerable_states(), true), 'quote_sent is not a state the policy answers in');

se_test_say('fiyat yüksek');
se_eq('quote_revision_requested', se_test_journey_row()->state, 'it is read as a revision request, exactly as before');
se_ok(mb_strpos(se_test_last_sent()['body'], 'Talebinizi aldık') !== false, 'and acknowledged as one');

/* ======================================================================== */
se_group('A thread a staff member has taken over is not answered by the bot');

se_test_price_seed();
se_journey_set_automation(se_test_journey_row(), 'paused_staff', 'test', 'staff');
$sentBefore = count($GLOBALS['se_wa_sent']);
se_test_say('fiyat ne kadar');
se_eq($sentBefore, count($GLOBALS['se_wa_sent']), 'no automatic reply while automation is paused');
se_ok(count(array_filter(se_test_db()->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'inbound_while_paused'; })) > 0,
    'the question becomes a staff task instead');

/* ======================================================================== */
se_group('Interactive off: the same policy goes as plain text');

se_test_price_seed();
update_option('se_journey_interactive_1', 0);
se_test_say('fiyat ne kadar');
$plain = se_test_last_sent();
se_eq('text', $plain['kind'], 'a brand with interactive messages off gets text');
se_ok(mb_strpos($plain['body'], 'sosyal medya hesaplarımızda') !== false, 'with the whole policy in it');
update_option('se_journey_interactive_1', 1);

/* ======================================================================== */
se_group('The copy version records that the policy is in it');

se_eq(5, SE_JOURNEY_COPY_VERSION, 'copy v5');
se_eq('default-v5', se_journey_copy_version(1), 'and every send stamps it');

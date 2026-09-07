<?php
/**
 * Patient journey (se_journey) — the information menu.
 *
 * A WhatsApp SESSION message cannot carry URL buttons (Meta allows them on
 * templates only), so the seven published azinasgari.com pages are offered as
 * an interactive LIST: one "Bilgi Al" button opens it, each row comes back as
 * a list_reply id, and the id is answered with that page's link. This suite
 * proves the shape Meta accepts, the routing, and that reading a page never
 * moves the journey on.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/journey_fixtures.php';

/** The last message the fixture transport saw. */
function se_test_last_sent()
{
    $all = $GLOBALS['se_wa_sent'];

    return $all ? $all[count($all) - 1] : null;
}

/** Deliver a tapped reply button / list row. */
function se_test_tap($id, $title, $type = 'button_reply')
{
    return se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, '', se_test_wamid(),
        ['interactive' => ['type' => $type, $type => ['id' => $id, 'title' => $title]]]));
}

/* ======================================================================== */
se_group('Welcome: three buttons — start, information, human — and the opt-out in the copy');

se_test_seed_journey();
se_test_act_as(10, [], true);
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, SE_JOURNEY_PREFILLED_MESSAGE, se_test_wamid()));

$welcome = array_values(array_filter($GLOBALS['se_wa_sent'], function ($m) { return $m['kind'] === 'interactive'; }));
se_eq(1, count($welcome), 'the welcome is the one interactive message so far');
se_eq(['jr_start', 'jr_info', 'jr_handoff'], array_column($welcome[0]['payload']['buttons'], 'id'), 'the three button ids');
se_eq('Bilgi Al', $welcome[0]['payload']['buttons'][1]['title'], 'the second button opens the information menu');
foreach ($welcome[0]['payload']['buttons'] as $b) { se_ok(mb_strlen($b['title']) <= 20, 'button "' . $b['title'] . '" respects the 20-char limit'); }
se_ok(mb_strpos($welcome[0]['body'], 'İPTAL') !== false, 'opt-out is still stated in the copy, though it no longer holds a button');
se_ok(mb_strpos($welcome[0]['body'], 'Bilgi Al') !== false, 'the copy names the information option');
se_ok(mb_strlen($welcome[0]['body']) <= 700, 'the welcome is short (' . mb_strlen($welcome[0]['body']) . ' chars, was 731)');
se_ok(mb_strpos($welcome[0]['body'], 'otomatik danışmanlık asistanıyım') !== false, 'and still discloses that it is automated');
se_ok(mb_stripos($welcome[0]['body'], 'garanti') === false && mb_stripos($welcome[0]['body'], 'Dr.') === false, 'no guarantee wording, no title claim');

/* ======================================================================== */
se_group('Bilgi Al: an interactive list of the seven published pages');

$stateBefore       = se_test_journey_row()->state;
$transitionsBefore = count(se_test_db()->rows('tblse_journey_transitions'));

se_test_tap('jr_info', 'Bilgi Al');
$menu = se_test_last_sent();
se_eq('interactive', $menu['kind'], 'the menu is an interactive message');
se_ok(isset($menu['payload']['list']), 'of type list, not reply buttons');
se_ok(mb_strlen($menu['payload']['list']['button']) <= 20, 'the list button respects the 20-char limit');
se_eq(1, count($menu['payload']['list']['sections']), 'one section');
$rows = $menu['payload']['list']['sections'][0]['rows'];
se_eq(7, count($rows), 'seven rows — one per published page');
se_eq(['jr_info_procedure', 'jr_info_candidates', 'jr_info_results', 'jr_info_preparation', 'jr_info_recovery', 'jr_info_aftercare', 'jr_info_questions'],
    array_column($rows, 'id'), 'the row ids, in the order a patient needs them');
foreach ($rows as $r) {
    se_ok(mb_strlen($r['title']) <= 24, 'row "' . $r['title'] . '" respects the 24-char title limit');
    se_ok(mb_strlen($r['description']) <= 72, 'its description respects the 72-char limit');
}
se_ok(mb_strlen($menu['payload']['list']['sections'][0]['title']) <= 24, 'the section title respects the 24-char limit');
se_ok(mb_strlen($menu['body']) <= 1024, 'the body respects the 1024-char limit');

$api = se_wa_interactive_payload($menu['body'], $menu['payload']);
se_eq('list', $api['type'], 'the Cloud API body is interactive type "list"');
se_eq(['button', 'sections'], array_keys($api['action']), 'action carries exactly button + sections');
se_eq(['title', 'rows'], array_keys($api['action']['sections'][0]), 'the section carries its title and rows');
se_eq(['id', 'title', 'description'], array_keys($api['action']['sections'][0]['rows'][0]), 'each row carries id, title and description');

se_eq($stateBefore, se_test_journey_row()->state, 'reading does not move the journey on');
se_eq($transitionsBefore, count(se_test_db()->rows('tblse_journey_transitions')), 'and writes no transition');

/* ======================================================================== */
se_group('A row tap answers with that page, and offers the three options again');

se_test_tap('jr_info_recovery', 'İyileşme Süreci', 'list_reply');
$answer = se_test_last_sent();
se_ok(strpos($answer['body'], 'https://azinasgari.com/tr/recovery') !== false, 'the reply carries the recovery page link');
se_eq('interactive', $answer['kind'], 'and comes back with buttons');
se_eq(['jr_start', 'jr_info', 'jr_handoff'], array_column($answer['payload']['buttons'], 'id'), 'so the next tap can start the evaluation or open the menu again');
se_eq($stateBefore, se_test_journey_row()->state, 'still no journey movement');

$sentBefore = count($GLOBALS['se_wa_sent']);
se_test_tap('jr_info_recovery', 'İyileşme Süreci', 'list_reply');
se_eq($sentBefore + 1, count($GLOBALS['se_wa_sent']), 'asking for the same page twice answers twice (per-tap dedup salt)');

se_test_tap('jr_info_questions', 'Sık Sorulan Sorular', 'list_reply');
se_ok(strpos(se_test_last_sent()['body'], 'https://azinasgari.com/tr/questions') !== false, 'another row, another page');

/* An id that is not one of the seven falls back to the menu rather than a broken link. */
se_test_tap('jr_info_nonsense', 'x', 'list_reply');
se_ok(isset(se_test_last_sent()['payload']['list']), 'an unknown row id re-opens the menu');

/* ======================================================================== */
se_group('Typed "bilgi" opens the same menu');

se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'bilgi', se_test_wamid()));
se_ok(isset(se_test_last_sent()['payload']['list']), 'the keyword reaches the menu too');

/* ======================================================================== */
se_group('Interactive off: the same seven links go as plain text');

update_option('se_journey_interactive_1', 0);
se_test_wa_deliver(se_test_wa_body(SE_TEST_PATIENT, 'bilgi', se_test_wamid()));
$plain = se_test_last_sent();
se_eq('text', $plain['kind'], 'a brand with interactive messages off gets text');
foreach (['procedure', 'candidates', 'results', 'preparation', 'recovery', 'aftercare', 'questions'] as $page) {
    se_ok(strpos($plain['body'], 'https://azinasgari.com/tr/' . $page) !== false, 'the text fallback carries /tr/' . $page);
}
update_option('se_journey_interactive_1', 1);

/* ======================================================================== */
se_group('A thread a staff member has taken over is not answered by the bot');

$j = se_test_journey_row();
se_journey_set_automation($j, 'paused_staff', 'test', 'staff');
$sentBefore = count($GLOBALS['se_wa_sent']);
se_test_tap('jr_info', 'Bilgi Al');
se_eq($sentBefore, count($GLOBALS['se_wa_sent']), 'no automatic reply while automation is paused');
se_ok(count(array_filter(se_test_db()->rows('tblse_journey_tasks'), function ($t) { return $t['kind'] === 'inbound_while_paused'; })) > 0,
    'the tap becomes a staff task instead');
se_journey_set_automation(se_test_journey_row(), 'active', 'test', 'staff');

/* ======================================================================== */
se_group('Meta list limits are enforced at queue time, not discovered in production');

$row = ['id' => 'jr_info_procedure', 'title' => 'Bir', 'description' => 'iki'];
$ok  = se_wa_shape_interactive(['body' => 'x', 'interactive_type' => 'list',
    'list' => ['button' => 'Konu Seçin', 'sections' => [['title' => 'Sayfalar', 'rows' => [$row]]]]]);
se_ok($ok['ok'], 'a well-formed list is accepted');

$mk = function ($list) { return se_wa_shape_interactive(['body' => 'x', 'interactive_type' => 'list', 'list' => $list])['reason']; };
se_eq('list_button_invalid', $mk(['button' => '', 'sections' => [['title' => 'S', 'rows' => [$row]]]]), 'an empty CTA is refused');
se_eq('list_button_invalid', $mk(['button' => str_repeat('a', 21), 'sections' => [['title' => 'S', 'rows' => [$row]]]]), 'a 21-char CTA is refused');
se_eq('list_section_invalid', $mk(['button' => 'B', 'sections' => [['title' => str_repeat('a', 25), 'rows' => [$row]]]]), 'a 25-char section title is refused');
se_eq('list_row_invalid', $mk(['button' => 'B', 'sections' => [['title' => 'S', 'rows' => [['id' => 'a', 'title' => str_repeat('a', 25)]]]]]), 'a 25-char row title is refused');
se_eq('list_row_invalid', $mk(['button' => 'B', 'sections' => [['title' => 'S', 'rows' => [['id' => 'a', 'title' => 'T', 'description' => str_repeat('a', 73)]]]]]), 'a 73-char description is refused');
se_eq('list_row_duplicate', $mk(['button' => 'B', 'sections' => [['title' => 'S', 'rows' => [$row, $row]]]]), 'two rows with one id are refused');
se_eq('list_section_empty', $mk(['button' => 'B', 'sections' => [['title' => 'S', 'rows' => []]]]), 'an empty section is refused');
$eleven = [];
for ($i = 0; $i < 11; $i++) { $eleven[] = ['id' => 'r' . $i, 'title' => 'T' . $i]; }
se_eq('list_row_count', $mk(['button' => 'B', 'sections' => [['title' => 'S', 'rows' => $eleven]]]), 'an eleventh row is refused (Meta allows ten)');
se_eq('list_section_invalid', $mk(['button' => 'B', 'sections' => [['title' => '', 'rows' => [$row]], ['title' => 'S2', 'rows' => [['id' => 'b', 'title' => 'T']]]]]),
    'with more than one section a title is mandatory');

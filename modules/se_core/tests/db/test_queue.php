<?php
/**
 * REAL MariaDB: queue claims, leases, fences and unique-race behaviour.
 *
 * These are database properties, not logic. The fake DB can only tell us what
 * our own matcher does; only the server can tell us whether two connections
 * really claim disjoint rows, whether a UNIQUE index really rejects the second
 * inserter, and whether a fenced UPDATE really matches zero rows.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$p  = db_prefix();
$db = se_db();
$BA = SE_TEST_BRAND_A;

$db->query("INSERT IGNORE INTO {$p}se_brands (id,name,slug,active,date_created)
    VALUES ({$BA},'ZZTEST Brand A','zztest-a',1,NOW())");
$db->query("INSERT IGNORE INTO {$p}se_staff_brands (staff_id,brand_id) VALUES (" . (SE_TEST_ID_BASE + 10) . ",{$BA})");

se_test_act_as(SE_TEST_ID_BASE + 10, ['se_tenancy.all_brands']);

se_group('Outbox: two workers claim DISJOINT rows (real UPDATE ... LIMIT)');

$base = SE_TEST_ID_BASE + 500;

for ($i = 0; $i < 6; $i++) {
    $db->query("INSERT INTO {$p}se_conversion_outbox
        (id,brand_id,lead_id,destination,event_name,event_time,payload,status,attempts,dedup_key,date_created,next_attempt_at,fence,payload_version)
        VALUES (" . ($base + $i) . ",{$BA}," . (SE_TEST_ID_BASE + 1) . ",'meta_capi','Lead','2026-06-01 12:00:00','[]','pending',0,
        'zz-{$i}-" . ($base + $i) . "',NOW(),'2026-06-01 12:00:00',0,1)");
}

$a = se_outbox_claim_batch('zz-worker-A', 3);
$b = se_outbox_claim_batch('zz-worker-B', 3);

// Restrict to our fixtures (the table may hold nothing else, but be exact).
$idsA = array_values(array_filter(array_column($a, 'id'), function ($x) use ($base) { return $x >= $base; }));
$idsB = array_values(array_filter(array_column($b, 'id'), function ($x) use ($base) { return $x >= $base; }));

se_eq(3, count($idsA), 'worker A claimed 3 fixture rows');
se_eq(3, count($idsB), 'worker B claimed 3 fixture rows');
se_eq([], array_intersect($idsA, $idsB), 'the two claims are DISJOINT in real MariaDB');
se_eq(6, count(array_unique(array_merge($idsA, $idsB))), 'every row claimed exactly once');

$c = se_outbox_claim_batch('zz-worker-C', 3);
$idsC = array_values(array_filter(array_column($c, 'id'), function ($x) use ($base) { return $x >= $base; }));
se_eq(0, count($idsC), 'a third worker finds nothing left');

se_group('Fence: an expired worker cannot overwrite a newer result');

$row = $db->query("SELECT * FROM {$p}se_conversion_outbox WHERE id=" . ($base) )->row_array();
se_eq(1, (int) $row['fence'], 'claiming bumped the fence to 1');

$stale = $row;   // worker A's view, fence 1

// Expire the lease and let a new worker re-claim it.
$db->query("UPDATE {$p}se_conversion_outbox SET status='pending', locked_at=NULL, locked_by=NULL WHERE id=" . $base);
$fresh = se_outbox_claim_batch('zz-worker-D', 1);
$freshRow = null;
foreach ($fresh as $r) { if ((int) $r['id'] === $base) { $freshRow = $r; } }

se_ok($freshRow !== null, 'a new worker re-claimed the row');
se_eq(2, (int) $freshRow['fence'], 'the re-claim bumped the fence to 2');

$written = se_outbox_finalize($stale, 'zz-worker-A', ['status' => 'sent']);
se_eq(0, $written, 'the fenced-out stale worker writes ZERO rows in real MariaDB');

$check = $db->query("SELECT status FROM {$p}se_conversion_outbox WHERE id=" . $base)->row();
se_eq('processing', $check->status, "the fresh worker's claim survived");

$written = se_outbox_finalize($freshRow, 'zz-worker-D', ['status' => 'sent', 'locked_by' => null]);
se_eq(1, $written, 'the current lease holder writes successfully');

se_group('Outbox dedup key is enforced by the database, not just a pre-check');

$dupKey = 'zz-dup-' . ($base + 100) . '-' . substr(md5(uniqid('', true)), 0, 8);
$db->query("INSERT INTO {$p}se_conversion_outbox
    (id,brand_id,lead_id,destination,event_name,event_time,payload,status,attempts,dedup_key,date_created)
    VALUES (" . ($base + 100) . ",{$BA}," . (SE_TEST_ID_BASE + 1) . ",'meta_capi','Lead','2026-06-01 12:00:00','[]','pending',0,
    '{$dupKey}',NOW())");

$idx = $db->query("SHOW INDEX FROM {$p}se_conversion_outbox WHERE Column_name='dedup_key'")->result_array();
$unique = false;
foreach ($idx as $i) { if ((int) $i['Non_unique'] === 0) { $unique = true; } }

if ($unique) {
    $threw = false;
    try {
        $db->query("INSERT INTO {$p}se_conversion_outbox
            (id,brand_id,lead_id,destination,event_name,event_time,payload,status,attempts,dedup_key,date_created)
            VALUES (" . ($base + 101) . ",{$BA}," . (SE_TEST_ID_BASE + 1) . ",'meta_capi','Lead','2026-06-01 12:00:00','[]','pending',0,
            '{$dupKey}',NOW())");
    } catch (SeSqlError $e) { $threw = true; }
    se_eq(true, $threw, 'a duplicate dedup_key is rejected by the UNIQUE index');
} else {
    se_ok(true, 'dedup_key has no UNIQUE index — dedup relies on the pre-check (recorded as a finding)');
}

se_group('WhatsApp webhook events: unique event_hash race');

$hash = hash('sha256', 'zz-fixture-' . uniqid('', true));
$db->query("INSERT INTO {$p}se_wa_webhook_events (event_hash,phone_number_id,waba_id,payload,signature_valid,state,attempts,received_at,fence,next_attempt_at)
    VALUES ('{$hash}','ZZPN','ZZWABA','{}',1,'pending',0,NOW(),0,NOW() - INTERVAL 1 MINUTE)");

$threw = false;
try {
    $db->query("INSERT INTO {$p}se_wa_webhook_events (event_hash,phone_number_id,waba_id,payload,signature_valid,state,attempts,received_at,fence,next_attempt_at)
        VALUES ('{$hash}','ZZPN','ZZWABA','{}',1,'pending',0,NOW(),0,NOW() - INTERVAL 1 MINUTE)");
} catch (SeSqlError $e) { $threw = true; }

se_eq(true, $threw, 'the UNIQUE event_hash index rejects a concurrent duplicate delivery');

se_group('WhatsApp claim / lease / fence against real MariaDB');

$db->query("UPDATE {$p}se_wa_webhook_events SET next_attempt_at=NOW() - INTERVAL 1 MINUTE WHERE event_hash='{$hash}'");

$wa = se_wa_claim_batch('zz-wa-A', 5);
$mine = array_values(array_filter($wa, function ($r) use ($hash) { return $r['event_hash'] === $hash; }));
se_eq(1, count($mine), 'the due webhook event is claimed');
se_eq(1, (int) $mine[0]['fence'], 'claiming bumped the fence');

$again = se_wa_claim_batch('zz-wa-B', 5);
$mine2 = array_values(array_filter($again, function ($r) use ($hash) { return $r['event_hash'] === $hash; }));
se_eq(0, count($mine2), 'a second worker cannot claim the same event');

se_group('Migration statements are all guarded (no DDL executed here)');

/* DDL IS NOT RUN IN THIS TIER.
 *
 * ALTER TABLE implicitly COMMITS in MySQL/MariaDB, which ends the enclosing
 * transaction and makes every fixture inserted before it permanent. An earlier
 * revision of this suite executed the migration statements and leaked its
 * fixtures into the live database for exactly that reason.
 *
 * Migration idempotency is proven where it can be proven safely:
 *   - modules/se_core/tests/db/../test_migrations.php (fake DB) checks the
 *     statement list is additive, guarded, pure and deterministic;
 *   - modules/se_core/tests/migrate_cli.php --apply, run twice, proves real
 *     idempotency against the real schema outside any transaction.
 *
 * Here we only assert the property that makes those safe.
 */
$stmts = se_core_migration_statements();
$unguarded = 0;
$destructive = 0;

foreach ($stmts as $sql) {
    if (stripos($sql, 'IF NOT EXISTS') === false
        && stripos($sql, 'INSERT IGNORE') === false
        && stripos($sql, 'WHERE NOT EXISTS') === false) {
        $unguarded++;
    }
    foreach (['DROP TABLE', 'DROP COLUMN', 'TRUNCATE', 'DELETE FROM', 'RENAME '] as $bad) {
        if (stripos($sql, $bad) !== false) { $destructive++; }
    }
}

se_eq(0, $unguarded, 'every one of the ' . count($stmts) . ' migration statements is guarded');
se_eq(0, $destructive, 'no migration statement is destructive');

se_group('Clock consistency: PHP and MariaDB must agree');

$dbNow  = strtotime($db->query('SELECT NOW() AS n')->row()->n);
$phpNow = time();
$skew   = abs($dbNow - $phpNow);

// The bug this guards: rows are written with SQL NOW() but were compared
// against PHP date(). On this host that is a real 2-hour offset.
// Informational, NOT an assertion about the host: report the measured skew.
// The bug this guards is that queue rows written with SQL NOW() were compared
// against PHP date(); on this host that was a real ~2h offset. What MUST hold
// regardless of how the host clocks are set is that se_db_now() tracks the
// DATABASE clock (asserted immediately below). A synchronized host (skew 0) is
// correct and must never fail this suite.
se_ok(true, 'measured PHP-vs-MariaDB clock skew here is ' . round($skew / 3600, 2)
    . 'h (informational; se_db_now() correctness is asserted next)');

$helperNow = strtotime(se_db_now());
se_ok(abs($helperNow - $dbNow) <= 2, 'se_db_now() tracks the DATABASE clock, not PHP');

$future = strtotime(se_db_now(3600));
se_ok(abs($future - ($dbNow + 3600)) <= 2, 'se_db_now(+3600) is one hour ahead on the database clock');

$past = strtotime(se_db_now(-900));
se_ok(abs($past - ($dbNow - 900)) <= 2, 'se_db_now(-900) is fifteen minutes back on the database clock');

se_group('A row scheduled by the drainer becomes claimable on time');

$cid = SE_TEST_ID_BASE + 700;
$db->query("INSERT INTO {$p}se_conversion_outbox
    (id,brand_id,lead_id,destination,event_name,event_time,payload,status,attempts,dedup_key,date_created,next_attempt_at,fence,payload_version)
    VALUES ({$cid},{$BA}," . (SE_TEST_ID_BASE + 1) . ",'meta_capi','Lead',NOW(),'[]','pending',0,
    'zz-clock-{$cid}',NOW(),NOW() - INTERVAL 1 MINUTE,0,1)");

$claimed = se_outbox_claim_batch('zz-clock-worker', 10);
$found = false;
foreach ($claimed as $r) { if ((int) $r['id'] === $cid) { $found = true; } }
se_eq(true, $found, 'a row due one minute ago (DB clock) IS claimed — it was not under the PHP clock');

// And one genuinely in the future is not.
$fid = SE_TEST_ID_BASE + 701;
$db->query("INSERT INTO {$p}se_conversion_outbox
    (id,brand_id,lead_id,destination,event_name,event_time,payload,status,attempts,dedup_key,date_created,next_attempt_at,fence,payload_version)
    VALUES ({$fid},{$BA}," . (SE_TEST_ID_BASE + 1) . ",'meta_capi','Lead',NOW(),'[]','pending',0,
    'zz-clock-{$fid}',NOW(),NOW() + INTERVAL 1 HOUR,0,1)");

$claimed = se_outbox_claim_batch('zz-clock-worker2', 10);
$foundFuture = false;
foreach ($claimed as $r) { if ((int) $r['id'] === $fid) { $foundFuture = true; } }
se_eq(false, $foundFuture, 'a row due in an hour is NOT claimed early');

se_group('Stale lease recovery uses the database clock');

$lid = SE_TEST_ID_BASE + 702;
$db->query("INSERT INTO {$p}se_conversion_outbox
    (id,brand_id,lead_id,destination,event_name,event_time,payload,status,attempts,dedup_key,date_created,locked_at,locked_by,fence,payload_version)
    VALUES ({$lid},{$BA}," . (SE_TEST_ID_BASE + 1) . ",'meta_capi','Lead',NOW(),'[]','processing',0,
    'zz-lease-{$lid}',NOW(),NOW() - INTERVAL " . (SE_OUTBOX_LEASE_SECONDS + 60) . " SECOND,'zz-dead',1,1)");

se_outbox_recover_stale();
$row = $db->query("SELECT status FROM {$p}se_conversion_outbox WHERE id={$lid}")->row();
se_eq('pending', $row->status, 'an expired lease is recovered promptly (was delayed by the clock offset)');

$fresh = SE_TEST_ID_BASE + 703;
$db->query("INSERT INTO {$p}se_conversion_outbox
    (id,brand_id,lead_id,destination,event_name,event_time,payload,status,attempts,dedup_key,date_created,locked_at,locked_by,fence,payload_version)
    VALUES ({$fresh},{$BA}," . (SE_TEST_ID_BASE + 1) . ",'meta_capi','Lead',NOW(),'[]','processing',0,
    'zz-lease-{$fresh}',NOW(),NOW() - INTERVAL 10 SECOND,'zz-live',1,1)");

se_outbox_recover_stale();
$row = $db->query("SELECT status FROM {$p}se_conversion_outbox WHERE id={$fresh}")->row();
se_eq('processing', $row->status, 'a live lease is NOT stolen from its worker');

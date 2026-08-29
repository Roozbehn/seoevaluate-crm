<?php
/**
 * Network-kill fixture.
 *
 * A test that quietly contacts Meta, WhatsApp or Google is worse than no test:
 * it reports success while performing exactly the external action the whole
 * programme forbids. Every outbound transport in these modules goes through
 * curl or a registered sender, so we neutralise both and COUNT attempts.
 *
 * The runner fails if the counter is anything but zero.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$GLOBALS['se_net_attempts'] = [];

function se_net_kill_count() { return count($GLOBALS['se_net_attempts']); }
function se_net_kill_attempts() { return $GLOBALS['se_net_attempts']; }

/**
 * Install fixture transports for every registered sender seam, so the payload
 * path is exercised end to end with no socket.
 */
function se_net_install_fixtures()
{
    if (function_exists('se_gdm_register_sender')) {
        se_gdm_register_sender(function ($url, $payload) {
            $GLOBALS['se_net_attempts'][] = 'gdm:' . $url;   // counted => test fails
            return ['ok' => false, 'code' => 0, 'body' => 'network disabled in tests'];
        });
    }

    if (function_exists('se_leadgen_register_fetcher')) {
        se_leadgen_register_fetcher(function ($leadgen_id, $brand_id) {
            $GLOBALS['se_net_attempts'][] = 'leadgen_fetch';
            return null;
        });
    }
}

/**
 * Replace a sender with one that records the payload WITHOUT counting it as a
 * forbidden outbound call. Used where a suite deliberately drives the payload
 * path.
 */
function se_net_expect_fixture(callable $handler)
{
    if (function_exists('se_gdm_register_sender')) {
        se_gdm_register_sender($handler);
    }
}

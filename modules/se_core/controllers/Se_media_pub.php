<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Public, signed, time-limited delivery of OUTBOUND attachments
 * (route: /se_core/se_media_pub/index/<id>/<exp>/<sig>).
 *
 * Exists for one caller: Meta's fetcher, because the Instagram Send API only
 * accepts an attachment URL. The signature is an HMAC over (id, expiry) with
 * a server-side key, TTL one hour, outbound rows only; anything else is 404.
 */
class Se_media_pub extends App_Controller
{
    public function index($id = 0, $exp = 0, $sig = '')
    {
        $row = function_exists('se_media_pub_verify') ? se_media_pub_verify((int) $id, (int) $exp, (string) $sig) : null;
        $abs = $row ? se_media_abs_path($row) : '';
        if ($abs === '') {
            show_404();
        }

        header('Content-Type: ' . ($row['mime'] ?: 'application/octet-stream'));
        header('Content-Length: ' . filesize($abs));
        header('Content-Disposition: inline');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Content-Security-Policy: sandbox');
        readfile($abs);
        exit;
    }
}

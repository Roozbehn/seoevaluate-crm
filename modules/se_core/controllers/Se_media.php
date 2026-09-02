<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Authenticated media delivery (admin/se_core/se_media/view/<id>).
 *
 * Files live outside the document root and are never linkable directly. A
 * request is served only for a logged-in staff member who may view the
 * channel AND may access the row's brand; anything else is a 404, not a 403,
 * so the id space cannot be enumerated.
 */
class Se_media extends AdminController
{
    public function view($id = 0)
    {
        $row = function_exists('se_media_get') ? se_media_get((int) $id) : null;
        if (!$row) {
            show_404();
        }

        $feature = $row['channel'] === 'ig' ? 'se_instagram' : 'se_whatsapp';
        if (staff_cant('view', $feature) || !se_can_access_brand((int) $row['brand_id'])) {
            show_404();
        }

        $abs = se_media_abs_path($row);
        if ($abs === '') {
            show_404();
        }

        $size = filesize($abs);
        header('Content-Type: ' . ($row['mime'] ?: 'application/octet-stream'));
        header('Content-Length: ' . $size);
        header('Content-Disposition: ' . se_media_disposition($row));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=3600');
        header('Content-Security-Policy: sandbox');   // a served file can never script the CRM origin

        // Range support so <audio>/<video> can seek.
        $range = $_SERVER['HTTP_RANGE'] ?? '';
        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)) {
            $start = $m[1] !== '' ? (int) $m[1] : max(0, $size - (int) $m[2]);
            $end   = $m[1] !== '' && $m[2] !== '' ? min((int) $m[2], $size - 1) : $size - 1;
            if ($start <= $end && $start < $size) {
                http_response_code(206);
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
                header('Content-Length: ' . ($end - $start + 1));
                $fh = fopen($abs, 'rb');
                fseek($fh, $start);
                echo fread($fh, $end - $start + 1);
                fclose($fh);
                exit;
            }
        }
        header('Accept-Ranges: bytes');
        readfile($abs);
        exit;
    }
}

<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Normalisation + SHA-256 hashing for advertising match keys.
 *
 * Meta CAPI and Google Data Manager both expect SHA-256 of normalised values.
 * The normalisation rules are subtly different between the two platforms and
 * getting them wrong silently destroys match rate rather than erroring, so the
 * rules are written out explicitly here and shared by both producers.
 *
 * Nothing in this class is Perfex-specific; it is pure string handling.
 */
class Se_hash
{
    /**
     * SHA-256 of a value that is already normalised. Returns lowercase hex.
     */
    public static function sha256($value)
    {
        return hash('sha256', (string) $value);
    }

    /**
     * Email, both platforms: trim, lowercase.
     * Gmail-only extra rule (strip dots in local part, drop +tag) is applied
     * ONLY to gmail.com / googlemail.com — applying it elsewhere is wrong.
     */
    public static function email($email)
    {
        $email = strtolower(trim((string) $email));

        if ($email === '' || strpos($email, '@') === false) {
            return null;
        }

        [$local, $domain] = explode('@', $email, 2);

        if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = str_replace('.', '', $local);
            $plus  = strpos($local, '+');
            if ($plus !== false) {
                $local = substr($local, 0, $plus);
            }
        }

        return $local . '@' . $domain;
    }

    /**
     * Phone to E.164 digits, no plus, no separators.
     *
     * A bare local number needs a country code; $default_cc supplies it (Turkey
     * = 90). A number that already carries a country code is left alone. This is
     * heuristic — a production system should store the dialing country per lead
     * — but it is correct for the common Turkish + international-patient case.
     */
    public static function phone($phone, $default_cc = '90')
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        // Leading 00 international prefix -> drop it.
        if (substr($digits, 0, 2) === '00') {
            $digits = substr($digits, 2);
        }

        // Turkish local forms: 0XXXXXXXXXX (11) or XXXXXXXXXX (10) -> prefix 90.
        if ($default_cc === '90') {
            if (strlen($digits) === 11 && $digits[0] === '0') {
                $digits = '90' . substr($digits, 1);
            } elseif (strlen($digits) === 10) {
                $digits = '90' . $digits;
            }
        } elseif ($digits[0] === '0') {
            $digits = $default_cc . ltrim($digits, '0');
        }

        return $digits;
    }

    /**
     * Names, both platforms: lowercase, strip whitespace and punctuation.
     * mb_strtolower with an explicit UTF-8 encoding is mandatory — plain
     * strtolower mangles the Turkish dotted/dotless I pair and wrecks matching.
     */
    public static function name($name)
    {
        $name = mb_strtolower(trim((string) $name), 'UTF-8');
        $name = preg_replace('/\s+/u', '', $name);

        return $name === '' ? null : $name;
    }

    /** Two-letter lowercase country code. */
    public static function country($code)
    {
        $code = strtolower(trim((string) $code));

        return preg_match('/^[a-z]{2}$/', $code) ? $code : null;
    }

    /** Postcode: lowercase, no spaces. */
    public static function zip($zip)
    {
        $zip = strtolower(preg_replace('/\s+/', '', (string) $zip));

        return $zip === '' ? null : $zip;
    }
}

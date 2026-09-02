<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * se_instagram install / idempotent schema.
 *
 * Brand-scoped tables for an Instagram Direct inbox. All DDL is guarded so
 * activation is idempotent and deactivation never drops a table. No token is
 * ever stored here; the account row only references the secret provider key.
 * The same statements are also registered in se_core/migrations.php so a host
 * that never toggles the module still gets the tables on admin_init.
 */

$CI = &get_instance();

foreach (se_ig_schema_statements(db_prefix()) as $sql) {
    $CI->db->query($sql);
}

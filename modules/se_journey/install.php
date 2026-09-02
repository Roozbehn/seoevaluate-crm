<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * se_journey install: the same idempotent DDL se_core's migration runner
 * applies on admin_init (schema v14), so a host that toggles the module
 * before the migration ran still gets its tables. Deactivation never drops.
 */
$CI = &get_instance();
foreach (se_journey_schema_statements(db_prefix()) as $sql) {
    $CI->db->query($sql);
}

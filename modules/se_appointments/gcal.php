<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Google Calendar sync adapter — configuration driven.
 *
 * Real sync needs a Google service account + per-brand/staff calendar mapping,
 * which do not exist yet, so this ships with a FIXTURE adapter: it records the
 * intended operation and returns a deterministic synthetic event id, exercising
 * the whole idempotent create/update/cancel path without any network call.
 * Status: externally gated. When a real adapter is registered via
 * se_gcal_register_adapter(), se_gcal_sync() routes to it instead.
 */

$GLOBALS['SE_GCAL_ADAPTER'] = null;

/** Register a real adapter: callable(array $op): array{ok:bool, event_id:?string, error:string}. */
function se_gcal_register_adapter(callable $adapter)
{
    $GLOBALS['SE_GCAL_ADAPTER'] = $adapter;
}

/** Is a real (non-fixture) adapter configured? */
function se_gcal_is_live()
{
    return is_callable($GLOBALS['SE_GCAL_ADAPTER'] ?? null);
}

/**
 * Fixture adapter: no network; deterministic id; idempotent by (op,appt).
 *
 * The id it returns is marked `fixture => true` so the caller never writes it
 * into a real appointment row. A `gcal-fixture-*` value persisted as a Google
 * event id is indistinguishable from a real one later, and every subsequent
 * sync then believes an event exists that Google has never heard of.
 */
function se_gcal_fixture_adapter(array $op)
{
    $id = 'gcal-fixture-' . $op['appointment_id'] . '-' . substr(md5($op['calendar_key'] . '|' . $op['start']), 0, 10);

    return [
        'ok'       => true,
        'event_id' => $op['operation'] === 'cancel' ? null : $id,
        'error'    => '',
        'fixture'  => true,
    ];
}

/** Is this adapter result a test fixture rather than a real Google event? */
function se_gcal_result_is_fixture(array $result)
{
    if (!empty($result['fixture'])) {
        return true;
    }

    return isset($result['event_id']) && is_string($result['event_id'])
        && strpos($result['event_id'], 'gcal-fixture-') === 0;
}

/** Per-brand/staff calendar mapping key (config-driven; empty when unmapped). */
function se_gcal_calendar_key($brand_id, $staff_id)
{
    $key = get_option('se_gcal_calendar_' . (int) $brand_id . '_' . (int) $staff_id);
    if (!$key) {
        $key = get_option('se_gcal_calendar_' . (int) $brand_id);
    }
    return $key ?: '';
}

/**
 * Sync one appointment. $operation is create|update|cancel. Idempotent: pass the
 * existing google_event_id so update/cancel target the same event. Returns the
 * adapter result plus an is_fixture flag so callers classify honestly.
 */
function se_gcal_sync(array $appointment, $operation)
{
    $op = [
        'operation'      => $operation,
        'appointment_id' => (int) ($appointment['id'] ?? 0),
        'brand_id'       => (int) ($appointment['brand_id'] ?? 0),
        'staff_id'       => (int) ($appointment['staff_id'] ?? 0),
        'calendar_key'   => se_gcal_calendar_key($appointment['brand_id'] ?? 0, $appointment['staff_id'] ?? 0),
        'title'          => $appointment['title'] ?? '',
        'start'          => $appointment['start_at'] ?? '',
        'end'            => $appointment['end_at'] ?? '',
        'existing_event' => $appointment['google_event_id'] ?? null,
    ];

    $adapter = se_gcal_is_live() ? $GLOBALS['SE_GCAL_ADAPTER'] : 'se_gcal_fixture_adapter';

    try {
        $result = call_user_func($adapter, $op);
    } catch (Exception $e) {
        return ['ok' => false, 'event_id' => $op['existing_event'], 'error' => 'gcal adapter error', 'is_fixture' => !se_gcal_is_live()];
    }

    $result['is_fixture'] = !se_gcal_is_live();
    return $result;
}

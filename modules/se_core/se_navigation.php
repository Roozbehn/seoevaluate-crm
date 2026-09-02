<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Clinic navigation.
 *
 * WHY FLAT
 * --------
 * The screens used to sit in one grouped "SEO Evaluate CRM" section beside
 * the Perfex modules of an agency install. The CRM now serves a single clinic,
 * so the working screens are top-level items in working order — Dashboard,
 * Leads, Patients, Appointments, WhatsApp, Customers, Reports — and the
 * integration screens (Meta Lead Ads, Conversion Outbox, Google Data Manager,
 * Integration Health, Integration Credentials, Consent Settings) sit in one
 * "Integrations" group that only configuration-capable staff see.
 *
 * Every item is permission-aware: a staff member sees only what they may open,
 * and the Integrations group disappears entirely when they may open nothing in
 * it. The gate on each item is never looser than the controller's own gate, so
 * nothing shown here bounces on click.
 *
 * Positions interleave with the surviving core items (see
 * se_clinic_sidebar_positions() in se_clinic.php, which also removes the core
 * items a clinic does not use and points the core "Dashboard" item at the
 * clinic dashboard).
 */

/** Top-level clinic items: [slug, label key, href, icon, position, can]. */
function se_nav_items()
{
    return [
        [
            'slug'     => 'se-patients',
            'label'    => 'se_patients',
            'href'     => 'se_core/se_patients',
            'icon'     => 'fa fa-user-md',
            'position' => 3,
            'can'      => function () { return staff_can('view', 'se_patients'); },
        ],
        [
            'slug'     => 'se-appointments',
            'label'    => 'se_appointments',
            'href'     => 'se_appointments/se_appointments/manage',
            'icon'     => 'fa fa-calendar-check',
            'position' => 4,
            'can'      => function () { return staff_can('view', 'se_appointments'); },
        ],
        [
            'slug'     => 'se-whatsapp',
            'label'    => 'se_whatsapp',
            'href'     => 'se_whatsapp/se_whatsapp/inbox',
            'icon'     => 'fab fa-whatsapp',
            'position' => 5,
            'can'      => function () { return staff_can('view', 'se_whatsapp'); },
        ],
        [
            'slug'     => 'se-instagram',
            'label'    => 'se_instagram',
            'href'     => 'se_instagram/se_instagram/inbox',
            'icon'     => 'fab fa-instagram',
            'position' => 6,
            'can'      => function () { return staff_can('view', 'se_instagram'); },
        ],
        [
            'slug'     => 'se-reports',
            'label'    => 'se_reports',
            'href'     => 'se_core/se_reports/index',
            'icon'     => 'fa fa-bar-chart',
            'position' => 8,
            'can'      => function () { return se_staff_can_report(); },
        ],
    ];
}

/** Children of the "Integrations" group: [slug, label key, href, icon, can]. */
function se_nav_integration_items()
{
    return [
        [
            'slug'  => 'se-meta-leadgen',
            'label' => 'se_meta_leadgen',
            'href'  => 'se_core/se_meta',
            'icon'  => 'fab fa-facebook-square',
            'can'   => function () { return se_staff_can_configure_brands(); },
        ],
        [
            'slug'  => 'se-outbox',
            'label' => 'se_outbox',
            'href'  => 'se_core/se_outbox',
            'icon'  => 'fa fa-paper-plane',
            // The controller admits report-capable staff too; the nav keeps
            // this delivery-queue screen with the people who can act on it.
            'can'   => function () { return se_staff_can_configure_brands(); },
        ],
        [
            'slug'  => 'se-google',
            'label' => 'se_google_dm',
            'href'  => 'se_core/se_google',
            'icon'  => 'fab fa-google',
            'can'   => function () { return se_staff_can_configure_brands(); },
        ],
        [
            'slug'  => 'se-health',
            'label' => 'se_reports_health',
            'href'  => 'se_core/se_reports/health',
            'icon'  => 'fa fa-heartbeat',
            // The controller gate is se_staff_can_report(). The nav requires
            // report AND configure: stricter than the controller (so nothing
            // shown here bounces on click — the F4 defect stays closed), and
            // report-only clinic staff keep to Reports.
            'can'   => function () { return se_staff_can_report() && se_staff_can_configure_brands(); },
        ],
        [
            'slug'  => 'se-credentials',
            'label' => 'se_credentials',
            'href'  => 'se_core/se_credentials',
            'icon'  => 'fa fa-key',
            'can'   => function () { return se_staff_can_configure_brands(); },
        ],
        [
            'slug'  => 'se-consent',
            'label' => 'se_consent_settings',
            'href'  => 'se_core/se_consent',
            'icon'  => 'fa fa-check-square',
            // EXACTLY the gate Se_consent::__construct enforces.
            'can'   => function () { return se_clinic_can_manage_consent(); },
        ],
    ];
}

/** Which top-level items may the CURRENT staff member open? */
function se_nav_visible_items()
{
    $out = [];

    foreach (se_nav_items() as $item) {
        if (call_user_func($item['can'])) {
            $out[] = $item;
        }
    }

    return $out;
}

/** Which Integrations children may the CURRENT staff member open? */
function se_nav_visible_integration_items()
{
    $out = [];

    foreach (se_nav_integration_items() as $item) {
        if (call_user_func($item['can'])) {
            $out[] = $item;
        }
    }

    return $out;
}

/**
 * Register the clinic items and the Integrations group.
 *
 * Runs on admin_init. An empty expandable group is worse than no group, so
 * the group is only registered when at least one child is visible.
 */
function se_nav_register()
{
    if (!is_staff_logged_in()) {
        return;
    }

    $CI = &get_instance();

    foreach (se_nav_visible_items() as $item) {
        $CI->app_menu->add_sidebar_menu_item($item['slug'], [
            'name'     => _l($item['label']),
            'href'     => admin_url($item['href']),
            'icon'     => $item['icon'],
            'position' => $item['position'],
            'badge'    => [],
        ]);
    }

    $integrations = se_nav_visible_integration_items();

    if (!$integrations) {
        return;
    }

    // A group of one is noise: the clinic owner holds only se_consent.manage,
    // so Consent Settings becomes a plain item for them.
    if (count($integrations) === 1 && $integrations[0]['slug'] === 'se-consent') {
        $CI->app_menu->add_sidebar_menu_item('se-consent', [
            'name'     => _l($integrations[0]['label']),
            'href'     => admin_url($integrations[0]['href']),
            'icon'     => $integrations[0]['icon'],
            'position' => 9,
            'badge'    => [],
        ]);

        return;
    }

    $CI->app_menu->add_sidebar_menu_item('se-integrations', [
        'collapse' => true,
        'name'     => _l('se_integrations'),
        'icon'     => 'fa fa-plug',
        'position' => 8,
    ]);

    $position = 1;

    foreach ($integrations as $item) {
        $CI->app_menu->add_sidebar_children_item('se-integrations', [
            'slug'     => $item['slug'],
            'name'     => _l($item['label']),
            'href'     => admin_url($item['href']),
            'icon'     => $item['icon'],
            'position' => $position++,
        ]);
    }
}

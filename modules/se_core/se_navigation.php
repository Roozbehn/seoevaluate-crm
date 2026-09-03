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

/**
 * Navigation v2 (UIUX §B/§C, CRM-M021): four operational items for every
 * clinic role — Bugün · Hastalar · Mesajlar · Randevular — and a Yönetim set
 * (Raporlar, Entegrasyonlar, Ayarlar) for owner/admin. The v1 flat list stays
 * behind the option se_clinic_nav_v2=0 as the rollback.
 */
function se_nav_v2_enabled()
{
    return (string) get_option('se_clinic_nav_v2') !== '0';
}

/** Top-level clinic items: [slug, label key, href, icon, position, can]. */
function se_nav_items()
{
    if (se_nav_v2_enabled()) {
        return [
            [
                'slug'     => 'se-hastalar',
                'label'    => 'se_nav_hastalar',
                'href'     => 'se_core/se_hastalar',
                'icon'     => 'fa fa-user',
                'position' => 2,
                'can'      => function () { return staff_can('view', 'se_journey') || staff_can('view', 'leads'); },
            ],
            [
                'slug'     => 'se-messages',
                'label'    => 'se_nav_messages',
                'href'     => 'se_whatsapp/se_whatsapp/inbox',
                'icon'     => 'fa fa-comments',
                'position' => 3,
                'can'      => function () { return staff_can('view', 'se_whatsapp') || staff_can('view', 'se_instagram'); },
            ],
            [
                'slug'     => 'se-appointments',
                'label'    => 'se_nav_appointments',
                'href'     => 'se_appointments/se_appointments/index',
                'icon'     => 'fa fa-calendar-check',
                'position' => 4,
                'can'      => function () { return staff_can('view', 'se_appointments'); },
            ],
            [
                'slug'     => 'se-reports',
                'label'    => 'se_nav_reports',
                'href'     => 'se_core/se_reports/index',
                'icon'     => 'fa fa-bar-chart',
                'position' => 6,
                'can'      => function () { return se_staff_can_report(); },
            ],
        ];
    }

    return [
        [
            'slug'     => 'se-patients',
            'label'    => 'se_patients',
            'href'     => 'se_core/se_patients',
            'icon'     => 'fa fa-user-md',
            'position' => 4,
            'can'      => function () { return staff_can('view', 'se_patients'); },
        ],
        [
            // Patient journeys (WhatsApp intake → review → consultation →
            // procedure → aftercare). Basic view only; health answers and
            // photos are gated per tab by their own capabilities.
            'slug'     => 'se-journeys',
            'label'    => 'se_journeys',
            'href'     => 'se_journey/se_journey/index',
            'icon'     => 'fa fa-route',
            'position' => 3,
            'can'      => function () { return staff_can('view', 'se_journey'); },
        ],
        [
            'slug'     => 'se-appointments',
            'label'    => 'se_appointments',
            'href'     => 'se_appointments/se_appointments/manage',
            'icon'     => 'fa fa-calendar-check',
            'position' => 5,
            'can'      => function () { return staff_can('view', 'se_appointments'); },
        ],
        [
            'slug'     => 'se-whatsapp',
            'label'    => 'se_whatsapp',
            'href'     => 'se_whatsapp/se_whatsapp/inbox',
            'icon'     => 'fab fa-whatsapp',
            'position' => 6,
            'can'      => function () { return staff_can('view', 'se_whatsapp'); },
        ],
        [
            'slug'     => 'se-instagram',
            'label'    => 'se_instagram',
            'href'     => 'se_instagram/se_instagram/inbox',
            'icon'     => 'fab fa-instagram',
            'position' => 7,
            'can'      => function () { return staff_can('view', 'se_instagram'); },
        ],
        [
            'slug'     => 'se-reports',
            'label'    => 'se_reports',
            'href'     => 'se_core/se_reports/index',
            'icon'     => 'fa fa-bar-chart',
            'position' => 9,
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
            // EXACTLY the gate Se_consent::__construct enforces. In nav v2 the
            // item lives under Ayarlar instead.
            'can'   => function () { return !se_nav_v2_enabled() && se_clinic_can_manage_consent(); },
        ],
    ];
}

/** Children of the "Ayarlar" group (v2, administrators): [slug, label key, href, icon, can]. */
function se_nav_settings_items()
{
    return [
        ['slug' => 'se-consent',   'label' => 'se_consent_settings', 'href' => 'se_core/se_consent',                 'icon' => 'fa fa-check-square', 'can' => function () { return se_clinic_can_manage_consent(); }],
        ['slug' => 'se-journey-settings', 'label' => 'se_journey_settings', 'href' => 'se_journey/se_journey/settings', 'icon' => 'fa fa-sliders', 'can' => function () { return se_staff_can_configure_brands(); }],
        ['slug' => 'se-templates', 'label' => 'se_nav_templates',   'href' => 'se_journey/se_journey/templates',      'icon' => 'fa fa-file-text-o',  'can' => function () { return se_staff_can_configure_brands(); }],
        ['slug' => 'se-flows',     'label' => 'se_nav_flows',       'href' => 'se_journey/se_journey/flows',          'icon' => 'fa fa-mobile',       'can' => function () { return se_staff_can_configure_brands(); }],
        ['slug' => 'se-perfex-leads',     'label' => 'se_nav_perfex_leads',     'href' => 'leads',    'icon' => 'fa fa-tty',       'can' => function () { return is_admin(); }],
        ['slug' => 'se-perfex-customers', 'label' => 'se_nav_perfex_customers', 'href' => 'clients',  'icon' => 'fa fa-users',     'can' => function () { return is_admin(); }],
        ['slug' => 'se-perfex-setup',     'label' => 'se_nav_perfex_setup',     'href' => 'settings', 'icon' => 'fa fa-cog',       'can' => function () { return is_admin(); }],
    ];
}

/** Which Ayarlar children may the CURRENT staff member open? */
function se_nav_visible_settings_items()
{
    $out = [];
    foreach (se_nav_settings_items() as $item) {
        if (call_user_func($item['can'])) {
            $out[] = $item;
        }
    }

    return $out;
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
        se_nav_register_settings($CI);
        return;
    }

    // A group of one is noise: the clinic owner holds only se_consent.manage,
    // so Consent Settings becomes a plain item for them.
    if (count($integrations) === 1 && $integrations[0]['slug'] === 'se-consent') {
        $CI->app_menu->add_sidebar_menu_item('se-consent', [
            'name'     => _l($integrations[0]['label']),
            'href'     => admin_url($integrations[0]['href']),
            'icon'     => $integrations[0]['icon'],
            'position' => 11,
            'badge'    => [],
        ]);
        se_nav_register_settings($CI);

        return;
    }

    $CI->app_menu->add_sidebar_menu_item('se-integrations', [
        'collapse' => true,
        'name'     => _l('se_integrations'),
        'icon'     => 'fa fa-plug',
        'position' => 10,
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

    se_nav_register_settings($CI);
}

/** v2: the Ayarlar group (rıza metinleri, süreç ayarları, şablonlar, flows, Perfex). */
function se_nav_register_settings($CI)
{
    if (!se_nav_v2_enabled()) {
        return;
    }
    $items = se_nav_visible_settings_items();
    if (!$items) {
        return;
    }
    $CI->app_menu->add_sidebar_menu_item('se-settings', [
        'collapse' => true,
        'name'     => _l('se_nav_settings'),
        'icon'     => 'fa fa-cog',
        'position' => 8,
    ]);
    $position = 1;
    foreach ($items as $item) {
        $CI->app_menu->add_sidebar_children_item('se-settings', [
            'slug'     => $item['slug'],
            'name'     => _l($item['label']),
            'href'     => admin_url($item['href']),
            'icon'     => $item['icon'],
            'position' => $position++,
        ]);
    }
}

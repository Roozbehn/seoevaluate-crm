<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * One grouped "SEO Evaluate CRM" sidebar section.
 *
 * WHY THIS REPLACES THE OLD PER-MODULE MENUS
 * ------------------------------------------
 * Each module registered its own top-level sidebar entry, so the SE features
 * appeared scattered between Contracts and Projects with no relationship to one
 * another — and two of them ("se_appointments", "se_whatsapp") rendered their
 * raw translation keys because those modules never called
 * register_language_files(). Half the built functionality (conversion outbox,
 * Meta Lead Ads, Google Data Manager, consent settings, credentials) had no
 * entry at all, so it was undiscoverable however well it worked.
 *
 * Every item is permission-aware: a staff member sees only what they may open,
 * and the parent disappears entirely when they may open nothing.
 */

/** Menu definition: [slug, capability-check, label key, href, icon]. */
function se_nav_items()
{
    return [
        [
            'slug'  => 'se-dashboard',
            'label' => 'se_dashboard',
            'href'  => 'se_core/se_dashboard',
            'icon'  => 'fa fa-tachometer',
            'can'   => function () { return se_staff_can_report() || se_staff_can_configure_brands(); },
        ],
        [
            'slug'  => 'se-patients',
            'label' => 'se_patients',
            'href'  => 'se_core/se_patients',
            'icon'  => 'fa fa-user-md',
            'can'   => function () { return staff_can('view', 'se_patients'); },
        ],
        [
            'slug'  => 'se-appointments',
            'label' => 'se_appointments',
            'href'  => 'se_appointments/se_appointments/manage',
            'icon'  => 'fa fa-calendar-check-o',
            'can'   => function () { return staff_can('view', 'se_appointments'); },
        ],
        [
            'slug'  => 'se-whatsapp',
            'label' => 'se_whatsapp',
            'href'  => 'se_whatsapp/se_whatsapp/inbox',
            'icon'  => 'fa fa-whatsapp',
            'can'   => function () { return staff_can('view', 'se_whatsapp'); },
        ],
        [
            'slug'  => 'se-meta-leadgen',
            'label' => 'se_meta_leadgen',
            'href'  => 'se_core/se_meta',
            'icon'  => 'fa fa-facebook-square',
            'can'   => function () { return se_staff_can_configure_brands(); },
        ],
        [
            'slug'  => 'se-outbox',
            'label' => 'se_outbox',
            'href'  => 'se_core/se_outbox',
            'icon'  => 'fa fa-paper-plane-o',
            'can'   => function () { return se_staff_can_report() || se_staff_can_configure_brands(); },
        ],
        [
            'slug'  => 'se-google',
            'label' => 'se_google_dm',
            'href'  => 'se_core/se_google',
            'icon'  => 'fa fa-google',
            'can'   => function () { return se_staff_can_configure_brands(); },
        ],
        [
            'slug'  => 'se-reports',
            'label' => 'se_reports',
            'href'  => 'se_core/se_reports/index',
            'icon'  => 'fa fa-bar-chart',
            'can'   => function () { return se_staff_can_report(); },
        ],
        [
            'slug'  => 'se-health',
            'label' => 'se_reports_health',
            'href'  => 'se_core/se_reports/health',
            'icon'  => 'fa fa-heartbeat',
            // EXACTLY the gate Se_reports::__construct enforces. The old
            // `|| se_staff_can_configure_brands()` showed the item to
            // configure-only staff who were then bounced on click.
            'can'   => function () { return se_staff_can_report(); },
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
            'icon'  => 'fa fa-check-square-o',
            'can'   => function () { return se_staff_can_configure_brands(); },
        ],
    ];
}

/** Which items may the CURRENT staff member open? */
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

/**
 * Register the grouped section.
 *
 * Runs on admin_init. If the staff member may open nothing, no parent is
 * registered at all — an empty expandable group is worse than no group.
 */
function se_nav_register()
{
    if (!is_staff_logged_in()) {
        return;
    }

    $visible = se_nav_visible_items();

    if (!$visible) {
        return;
    }

    $CI = &get_instance();

    $CI->app_menu->add_sidebar_menu_item('se-crm', [
        'name'     => _l('se_group'),
        'icon'     => 'fa fa-stethoscope',
        'position' => 26,
    ]);

    $position = 1;

    foreach ($visible as $item) {
        $CI->app_menu->add_sidebar_children_item('se-crm', [
            'slug'     => $item['slug'],
            'name'     => _l($item['label']),
            'href'     => admin_url($item['href']),
            'icon'     => $item['icon'],
            'position' => $position++,
        ]);
    }
}

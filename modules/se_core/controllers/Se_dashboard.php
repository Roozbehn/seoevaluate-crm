<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Clinic dashboard — the landing screen for every clinic role.
 *
 * Every figure is brand-scoped through the same fail-closed predicate the rest
 * of the system uses, so a single-brand user never sees a global total. The
 * cards a staff member sees are the counts of the screens they can already
 * open; integration cards and system warnings stay with the roles that can act
 * on them (se_clinic_can_see_integration_cards()).
 */
class Se_dashboard extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        if (!se_clinic_can_open_dashboard()) {
            access_denied('se_dashboard');
        }
    }

    public function index()
    {
        $data['title'] = _l('se_group');

        // An ordinary staff member with no brand gets an explanation, not an
        // empty dashboard they cannot distinguish from "no data yet".
        $data['has_brand'] = se_staff_has_any_brand();

        if ($data['has_brand']) {
            $data['stats'] = se_dashboard_stats();

            // Three tiers, each linking only to screens its holder can open:
            //   show_integrations (report OR configure) -> Conversion Outbox cards
            //   show_health (configure)                 -> Meta, Google, Credentials, Health, warnings
            //   show_consent (se_consent.manage)        -> Consent Settings
            $data['show_integrations'] = se_clinic_can_see_integration_cards();
            $data['show_health']       = se_staff_can_configure_brands();
            $data['show_consent']      = se_clinic_can_manage_consent();
            $data['warnings']          = $data['show_health'] ? se_dashboard_warnings() : [];

            // The Health button opens Se_reports, whose gate is report; same
            // report-AND-configure rule as the nav item, so it never bounces.
            $data['show_health_button'] = $data['show_health'] && se_staff_can_report();

            // With one brand the badge row says nothing; with several it
            // disambiguates the numbers.
            $brands         = se_all_brands(true, true);
            $data['brands'] = count($brands) > 1 ? $brands : [];
        }

        $this->load->view('se_core/se_dashboard', $data);
    }
}

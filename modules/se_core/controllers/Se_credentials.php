<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Integration credential STATUS.
 *
 * Status only. There is deliberately no field to enter, view, reveal or copy a
 * secret: a UI that can display a credential is a UI that can leak one, and
 * every screenshot, support session and browser cache becomes a disclosure
 * risk. Owners install secrets on disk; this screen reports whether that
 * worked.
 */
class Se_credentials extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        if (!se_staff_can_configure_brands()) {
            access_denied('se_credentials');
        }
    }

    public function index()
    {
        $brand = se_requested_brand_or_default($this->input->get('brand'));

        $data['title']     = _l('se_credentials');
        $data['brand']     = (int) $brand;
        $data['brands']    = se_all_brands(false, true);
        $data['providers'] = se_secret_status_all();
        $data['store']     = se_secret_store_status();

        // Per-provider progress replaces the old global-green checklist. In the
        // aggregate "All brands" view we render a read-only progress block PER
        // brand, never one brand's config as if it were global.
        if (se_is_all_brands($brand)) {
            $data['progress'] = null;
            $data['progress_all'] = array_map(function ($b) use ($data) {
                return [
                    'brand_id'   => (int) $b['id'],
                    'brand_name' => $b['name'],
                    'rows'       => se_integration_provider_progress((int) $b['id'], $data['store']),
                ];
            }, $data['brands']);
        } else {
            $data['progress'] = se_integration_provider_progress((int) $brand, $data['store']);
            $data['progress_all'] = null;
        }

        $this->load->view('se_core/se_credentials', $data);
    }
}

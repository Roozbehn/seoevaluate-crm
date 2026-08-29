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
        $data['title']     = _l('se_credentials');
        $data['providers'] = se_secret_status_all();
        $data['store']     = se_secret_store_status();

        $this->load->view('se_core/se_credentials', $data);
    }
}

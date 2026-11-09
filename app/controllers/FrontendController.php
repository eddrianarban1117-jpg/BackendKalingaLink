<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class FrontendController extends Controller
{
    public function home()
    {
        redirect(site_url('app/'));
    }

    public function index()
    {
        $frontend_entry = ROOT_DIR . 'public' . DIRECTORY_SEPARATOR
            . 'kalingalink' . DIRECTORY_SEPARATOR . 'index.html';

        if (!is_file($frontend_entry) || !is_readable($frontend_entry)) {
            http_response_code(503);
            echo 'The KalingaLink frontend is not built. Run npm run build in SIAkalingaLink.';
            return;
        }

        $frontend_html = file_get_contents($frontend_entry);
        if ($frontend_html === false) {
            throw new RuntimeException('Unable to read the KalingaLink frontend entry point.');
        }

        $this->call->view('frontend', ['frontend_html' => $frontend_html]);
    }
}
?>

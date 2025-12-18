<?php
namespace PUP\Booking\Front;

if (!defined('ABSPATH')) exit;

final class ManageShortcode
{
    public function register(): void
    {
        add_shortcode('pup_manage', [$this, 'render']);
    }

    public function render(): string
    {
        wp_enqueue_script('pup-manage', PUP_BOOKING_URL.'assets/front-manage.js', [], PUP_BOOKING_VERSION, true);
        wp_add_inline_script('pup-manage', 'window.PUP_MANAGE=' . wp_json_encode([
            'restUrl' => esc_url_raw(rest_url('pup/v1')),
        ]) . ';', 'before');

        return '<div class="pup-manage-root"></div>';
    }
}

<?php
namespace PUP\Booking\Front;

if (!defined('ABSPATH')) exit;

final class Shortcodes
{
    public function register(): void
    {
        add_shortcode('pup_booking', [$this, 'render']);
    }

    public function render($atts = []): string
    {
        $atts = shortcode_atts(['service_id' => ''], (array)$atts);
        $serviceId = (int)$atts['service_id'];

        // ✅ IMPORTANT : un handle unique et stable
        $jsHandle  = 'pup-front';
        $cssHandle = 'pup-front';

        // CSS
        wp_enqueue_style(
            $cssHandle,
            PUP_BOOKING_URL . 'assets/front-booking.css',
            [],
            PUP_BOOKING_VERSION
        );

        // JS
        wp_enqueue_script(
            $jsHandle,
            PUP_BOOKING_URL . 'assets/front-booking.js',
            [],
            PUP_BOOKING_VERSION,
            true
        );

        // ✅ Béton : injecté AVANT le fichier JS (garantit PUP_FRONT)
        $data = [
            'restUrl' => esc_url_raw(rest_url('pup/v1')),
            'defaultServiceId' => $serviceId ?: null,
        ];
        wp_add_inline_script($jsHandle, 'window.PUP_FRONT = ' . wp_json_encode($data) . ';', 'before');

        return '<div class="pup-booking-root"></div>';
    }
}

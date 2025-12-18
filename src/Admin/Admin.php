<?php
namespace PUP\Booking\Admin;

if (!defined('ABSPATH')) exit;

final class Admin
{
    private const SLUG = 'pup-booking';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function menu(): void
    {
        add_menu_page(
            'PUP Booking',
            'PUP Booking',
            'manage_options',
            self::SLUG,
            [$this, 'page'],
            'dashicons-calendar-alt',
            58
        );
    }

    public function page(): void
    {
        echo '<div class="wrap"><div id="pup-admin-root"></div></div>';
    }

    public function enqueue(string $hook): void
    {
        if (!isset($_GET['page']) || $_GET['page'] !== self::SLUG) return;

        wp_enqueue_style(
            'pup-admin-app',
            PUP_BOOKING_URL . 'assets/admin-app.css',
            [],
            PUP_BOOKING_VERSION
        );

        wp_enqueue_script(
            'pup-admin-app',
            PUP_BOOKING_URL . 'assets/admin-app.js',
            [],
            PUP_BOOKING_VERSION,
            true
        );

        wp_localize_script('pup-admin-app', 'PUP_ADMIN', [
            'restUrl' => esc_url_raw(rest_url('pup/v1')),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }
}

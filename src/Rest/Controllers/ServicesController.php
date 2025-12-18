<?php
namespace PUP\Booking\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class ServicesController
{
    public function register_routes(): void
    {
        register_rest_route('pup/v1', '/services', [
            'methods'  => 'GET',
            'callback' => [$this, 'list'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function list(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pup_services';

        $rows = $wpdb->get_results("SELECT id, name, duration_min, type, capacity_max FROM {$table} WHERE is_active=1 ORDER BY id DESC", ARRAY_A);

        return new WP_REST_Response([
            'ok' => true,
            'services' => $rows ?? [],
        ], 200);
    }
}

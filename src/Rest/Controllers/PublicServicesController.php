<?php
namespace PUP\Booking\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class PublicServicesController
{
    public function register_routes(): void
    {
        register_rest_route('pup/v1', '/public/services', [
            'methods'  => 'GET',
            'callback' => [$this, 'list'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function list(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $t = $wpdb->prefix . 'pup_services';

        $rows = $wpdb->get_results("
            SELECT id, name, duration_min, type, capacity_max
            FROM {$t}
            WHERE is_active=1
            ORDER BY id ASC
        ", ARRAY_A) ?: [];

        return new WP_REST_Response(['ok' => true, 'items' => $rows], 200);
    }
}

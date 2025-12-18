<?php
namespace PUP\Booking\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class AppointmentsController
{
    public function register_routes(): void
    {
        register_rest_route('pup/v1', '/appointments', [
            'methods'  => 'POST',
            'callback' => [$this, 'create'],
            'permission_callback' => [$this, 'perm_create'],
        ]);
    }

    public function perm_create(): bool
    {
        // Front public mais protégé nonce REST (évite spam basique).
        return (bool) wp_verify_nonce(
            (string)($_SERVER['HTTP_X_WP_NONCE'] ?? ''),
            'wp_rest'
        );
    }

    public function create(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;

        $serviceId = (int)$req->get_param('service_id');
        $email = sanitize_email((string)$req->get_param('email'));
        $start = sanitize_text_field((string)$req->get_param('start_dt'));
        $end   = sanitize_text_field((string)$req->get_param('end_dt'));

        if ($serviceId <= 0 || !is_email($email) || !$start || !$end) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Invalid payload'], 400);
        }

        $table = $wpdb->prefix . 'pup_appointments';

        $ok = $wpdb->insert($table, [
            'service_id' => $serviceId,
            'customer_email' => $email,
            'start_dt' => $start,
            'end_dt' => $end,
            'status' => 'pending',
        ], ['%d','%s','%s','%s','%s']);

        if (!$ok) {
            return new WP_REST_Response(['ok' => false, 'error' => 'DB insert failed'], 500);
        }

        return new WP_REST_Response([
            'ok' => true,
            'appointment_id' => (int)$wpdb->insert_id,
            'status' => 'pending'
        ], 201);
    }
}

<?php
namespace PUP\Booking\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class AdminEmployeesController
{
    public function register_routes(): void
    {
        register_rest_route('pup/v1', '/admin/employees', [
            [
                'methods'  => 'GET',
                'callback' => [$this, 'list'],
                'permission_callback' => [$this, 'can_manage'],
            ],
            [
                'methods'  => 'POST',
                'callback' => [$this, 'create'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);

        register_rest_route('pup/v1', '/admin/employees/(?P<id>\d+)', [
            [
                'methods'  => 'PUT',
                'callback' => [$this, 'update'],
                'permission_callback' => [$this, 'can_manage'],
            ],
            [
                'methods'  => 'DELETE',
                'callback' => [$this, 'disable'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);
    }

    public function can_manage(): bool
    {
        return current_user_can('manage_options');
    }

    public function list(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $t = $wpdb->prefix . 'pup_employees';

        $rows = $wpdb->get_results("
            SELECT id, wp_user_id, display_name, kind, capacity, email, timezone, is_active, google_sync_enabled, created_at
            FROM {$t}
            ORDER BY id DESC
        ", ARRAY_A);

        return new WP_REST_Response(['ok' => true, 'items' => $rows ?: []], 200);
    }

    public function create(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $t = $wpdb->prefix . 'pup_employees';

        $kind = sanitize_text_field($body['kind'] ?? 'human');
        if (!in_array($kind, ['human','resource'], true)) $kind = 'human';
        
        $capacity = max(1, (int)($body['capacity'] ?? 1));

        $data = $this->sanitize($req);
        
        $ok = $wpdb->insert($t, $data, $this->formats($data));
        if (!$ok) {
            return new WP_REST_Response(['ok' => false, 'error' => $wpdb->last_error ?: 'DB insert failed'], 500);
        }

        $id = (int)$wpdb->insert_id;

        $row = $wpdb->get_row($wpdb->prepare("
            SELECT id, wp_user_id, display_name, kind, capacity, email, timezone, is_active, google_sync_enabled
            FROM {$t}
            WHERE id=%d
        ", $id), ARRAY_A);

        return new WP_REST_Response(['ok' => true, 'item' => $row], 201);
    }

    public function update(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $t = $wpdb->prefix . 'pup_employees';
        $id = (int)$req['id'];

        $kind = sanitize_text_field($body['kind'] ?? 'human');
        if (!in_array($kind, ['human','resource'], true)) $kind = 'human';
        
        $capacity = max(1, (int)($body['capacity'] ?? 1));

        $data = $this->sanitize($req);

        $ok = $wpdb->update($t, $data, ['id' => $id], $this->formats($data), ['%d']);
        if ($ok === false) {
            return new WP_REST_Response(['ok' => false, 'error' => $wpdb->last_error ?: 'DB update failed'], 500);
        }

        $row = $wpdb->get_row($wpdb->prepare("
            SELECT id, wp_user_id, display_name, kind, capacity, email, timezone, is_active, google_sync_enabled
            FROM {$t}
            WHERE id=%d
        ", $id), ARRAY_A);

        return new WP_REST_Response(['ok' => true, 'item' => $row], 200);
    }

    public function disable(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $t = $wpdb->prefix . 'pup_employees';
        $id = (int)$req['id'];

        $ok = $wpdb->update($t, ['is_active' => 0], ['id' => $id], ['%d'], ['%d']);
        if ($ok === false) {
            return new WP_REST_Response(['ok' => false, 'error' => $wpdb->last_error ?: 'DB update failed'], 500);
        }

        return new WP_REST_Response(['ok' => true], 200);
    }

    private function sanitize(WP_REST_Request $req): array
    {
        $wpUser = $req->get_param('wp_user_id');
        $wpUser = ($wpUser === null || $wpUser === '') ? null : (int)$wpUser;

        $tz = sanitize_text_field((string)$req->get_param('timezone'));
        if (!$tz) $tz = 'Europe/Paris';

        $email = sanitize_email((string)$req->get_param('email'));
        $email = $email ?: null;

        return [
            'wp_user_id' => $wpUser,
            'display_name' => sanitize_text_field((string)$req->get_param('display_name')) ?: 'Employé',
            'email' => $email,
            'timezone' => $tz,
            'is_active' => (int)!!$req->get_param('is_active'),
            'google_sync_enabled' => (int)!!$req->get_param('google_sync_enabled'),
        ];
    }

    private function formats(array $data): array
    {
        $out = [];
        foreach ($data as $v) $out[] = is_int($v) ? '%d' : '%s';
        return $out;
    }
}

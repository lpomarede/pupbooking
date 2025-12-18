<?php
namespace PUP\Booking\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class AdminServicesController
{
    public function register_routes(): void
    {
        // Liste + création
        register_rest_route('pup/v1', '/admin/services', [
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

        // Lecture / update / delete
        register_rest_route('pup/v1', '/admin/services/(?P<id>\d+)', [
            [
                'methods'  => 'GET',
                'callback' => [$this, 'get'],
                'permission_callback' => [$this, 'can_manage'],
            ],
            [
                'methods'  => 'PUT',
                'callback' => [$this, 'update'],
                'permission_callback' => [$this, 'can_manage'],
            ],
            [
                'methods'  => 'DELETE',
                'callback' => [$this, 'delete'],
                'permission_callback' => [$this, 'can_manage'],
            ],
        ]);

        // Seed demo
        register_rest_route('pup/v1', '/admin/services/seed', [
            'methods'  => 'POST',
            'callback' => [$this, 'seed'],
            'permission_callback' => [$this, 'can_manage'],
        ]);
    }

    public function can_manage(): bool
    {
        return current_user_can('manage_options');
    }

    public function list(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $t = $wpdb->prefix . 'pup_services';

        $rows = $wpdb->get_results("
            SELECT id, name, category_id, booking_mode,
                   duration_min, buffer_before_min, buffer_after_min, type, capacity_max,
                   min_notice_min, cancel_limit_min, is_active, created_at
            FROM {$t}
            ORDER BY id DESC
        ", ARRAY_A);

        return new WP_REST_Response(['ok' => true, 'items' => $rows ?: []], 200);
    }

    public function get(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $t = $wpdb->prefix . 'pup_services';
        $id = (int)$req['id'];

        $row = $wpdb->get_row($wpdb->prepare("
            SELECT id, name, category_id, booking_mode, description, duration_min, buffer_before_min, buffer_after_min, type, capacity_max,
                   min_notice_min, cancel_limit_min, is_active
            FROM {$t}
            WHERE id = %d
        ", $id), ARRAY_A);

        if (!$row) return new WP_REST_Response(['ok' => false, 'error' => 'Not found'], 404);
        return new WP_REST_Response(['ok' => true, 'item' => $row], 200);
    }

    public function create(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $t = $wpdb->prefix . 'pup_services';

        $data = $this->sanitize_service_payload($req);

        $ok = $wpdb->insert($t, $data, $this->formats_for($data));
        if (!$ok) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'DB insert failed',
                'db_error' => $wpdb->last_error,
            ], 500);
        }

        $id = (int)$wpdb->insert_id;

        $row = $wpdb->get_row($wpdb->prepare("
            SELECT id, name, category_id, booking_mode, description, duration_min, buffer_before_min, buffer_after_min, type, capacity_max,
                min_notice_min, cancel_limit_min, is_active
            FROM {$t}
            WHERE id = %d
        ", $id), ARRAY_A);

        return new WP_REST_Response(['ok' => true, 'id' => $id, 'item' => $row], 201);
    }

    public function update(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $t = $wpdb->prefix . 'pup_services';
        $id = (int)$req['id'];

        $data = $this->sanitize_service_payload($req);

        $ok = $wpdb->update($t, $data, ['id' => $id], $this->formats_for($data), ['%d']);
        if ($ok === false) {
            return new WP_REST_Response([
                'ok' => false,
                'error' => 'DB update failed',
                'db_error' => $wpdb->last_error,
            ], 500);
        }

        $row = $wpdb->get_row($wpdb->prepare("
            SELECT id, name, category_id, booking_mode, description, duration_min, buffer_before_min, buffer_after_min, type, capacity_max,
                min_notice_min, cancel_limit_min, is_active
            FROM {$t}
            WHERE id = %d
        ", $id), ARRAY_A);

        if (!$row) return new WP_REST_Response(['ok' => false, 'error' => 'Not found'], 404);

        return new WP_REST_Response(['ok' => true, 'item' => $row], 200);
    }

    public function delete(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $t = $wpdb->prefix . 'pup_services';
        $id = (int)$req['id'];

        // soft-delete (désactivation)
        $ok = $wpdb->update($t, ['is_active' => 0], ['id' => $id], ['%d'], ['%d']);
        if ($ok === false) return new WP_REST_Response(['ok' => false, 'error' => 'DB delete failed'], 500);

        return new WP_REST_Response(['ok' => true], 200);
    }

    public function seed(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $t = $wpdb->prefix . 'pup_services';

        $existing = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$t}");
        if ($existing > 0) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Already has data'], 400);
        }

        $items = [
            [
                'name' => 'Massage Signature 60 min',
                'category_id' => null,
                'booking_mode' => 'slot',
                'description' => '',
                'duration_min' => 60,
                'buffer_before_min' => 0,
                'buffer_after_min' => 10,
                'type' => 'individual',
                'capacity_max' => null,
                'min_notice_min' => 0,
                'cancel_limit_min' => 1440,
                'is_active' => 1,
            ],
            [
                'name' => 'Massage Duo 60 min',
                'category_id' => null,
                'booking_mode' => 'slot',
                'description' => '',
                'duration_min' => 60,
                'buffer_before_min' => 0,
                'buffer_after_min' => 15,
                'type' => 'multi',
                'capacity_max' => null,
                'min_notice_min' => 0,
                'cancel_limit_min' => 1440,
                'is_active' => 1,
            ],
            [
                'name' => 'Afterwork (1 à 6 pers)',
                'category_id' => null,
                'booking_mode' => 'slot',
                'description' => '',
                'duration_min' => 90,
                'buffer_before_min' => 0,
                'buffer_after_min' => 15,
                'type' => 'capacity',
                'capacity_max' => 6,
                'min_notice_min' => 0,
                'cancel_limit_min' => 1440,
                'is_active' => 1,
            ],
        ];

        foreach ($items as $data) {
            $wpdb->insert($t, $data, $this->formats_for($data));
        }

        return $this->list($req);
    }

    private function sanitize_service_payload(WP_REST_Request $req): array
    {
        $name = sanitize_text_field((string)$req->get_param('name'));
        $desc = (string)$req->get_param('description');
        $desc = wp_kses_post($desc);

        $type = sanitize_key((string)$req->get_param('type'));
        if (!in_array($type, ['individual','multi','capacity'], true)) $type = 'individual';

        $capacity = $req->get_param('capacity_max');
        $capacity = ($type === 'capacity') ? max(1, (int)$capacity) : null;
        
        $categoryId = $req->get_param('category_id');
        $categoryId = ($categoryId === null || $categoryId === '') ? null : (int)$categoryId;
        
        $bookingMode = sanitize_key((string)$req->get_param('booking_mode'));
        if (!in_array($bookingMode, ['slot','product'], true)) $bookingMode = 'slot';


        $data = [
            'name' => $name ?: 'Nouveau service',
            'category_id' => $categoryId,
            'booking_mode' => $bookingMode,
            'description' => $desc,
            'duration_min' => max(5, (int)$req->get_param('duration_min')),
            'buffer_before_min' => max(0, (int)$req->get_param('buffer_before_min')),
            'buffer_after_min' => max(0, (int)$req->get_param('buffer_after_min')),
            'type' => $type,
            'capacity_max' => $capacity,
            'min_notice_min' => max(0, (int)$req->get_param('min_notice_min')),
            'cancel_limit_min' => max(0, (int)$req->get_param('cancel_limit_min')),
            'is_active' => (int)!!$req->get_param('is_active'),
        ];

        return $data;
    }

    private function formats_for(array $data): array
    {
        // formats alignés à $data order
        $formats = [];
        foreach ($data as $k => $v) {
            if ($k === 'capacity_max' && $v === null) { $formats[] = null; continue; }
            $formats[] = is_int($v) ? '%d' : '%s';
        }
        // dbDelta/wpdb n'aime pas null format -> on patch au moment insert/update
        // on remplace les null par %s et on enverra null (wpdb gère)
        return array_map(fn($f) => $f ?? '%s', $formats);
    }
}

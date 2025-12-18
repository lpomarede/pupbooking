<?php
namespace PUP\Booking\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class AdminOptionsController
{
    public function register_routes(): void
    {
        // Liste + création
        register_rest_route('pup/v1', '/admin/options', [
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
        register_rest_route('pup/v1', '/admin/options/(?P<id>\d+)', [
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
    }

    public function can_manage(): bool
    {
        return current_user_can('manage_options');
    }

    public function list(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $t = $wpdb->prefix . 'pup_options';

        $rows = $wpdb->get_results("
            SELECT id, name, description, price, duration_add_min, is_active, created_at
            FROM {$t}
            ORDER BY id DESC
        ", ARRAY_A);

        return new WP_REST_Response(['ok' => true, 'items' => $rows ?: []], 200);
    }

    public function get(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $t = $wpdb->prefix . 'pup_options';
        $id = (int)$req['id'];

        $row = $wpdb->get_row($wpdb->prepare("
            SELECT id, name, description, price, duration_add_min, is_active
            FROM {$t}
            WHERE id=%d
        ", $id), ARRAY_A);

        if (!$row) return new WP_REST_Response(['ok'=>false,'error'=>'Not found'], 404);
        return new WP_REST_Response(['ok'=>true,'item'=>$row], 200);
    }

    public function create(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $t = $wpdb->prefix . 'pup_options';

        $data = $this->sanitize_payload($req);

        $ok = $wpdb->insert($t, $data, ['%s','%s','%f','%d','%d']);
        if (!$ok) {
            return new WP_REST_Response(['ok'=>false,'error'=>'DB insert failed','db_error'=>$wpdb->last_error], 500);
        }

        $id = (int)$wpdb->insert_id;
        $row = $wpdb->get_row($wpdb->prepare("
            SELECT id, name, description, price, duration_add_min, is_active
            FROM {$t} WHERE id=%d
        ", $id), ARRAY_A);

        return new WP_REST_Response(['ok'=>true,'item'=>$row], 201);
    }

    public function update(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $t = $wpdb->prefix . 'pup_options';
        $id = (int)$req['id'];

        $data = $this->sanitize_payload($req);

        $ok = $wpdb->update($t, $data, ['id'=>$id], ['%s','%s','%f','%d','%d'], ['%d']);
        if ($ok === false) {
            return new WP_REST_Response(['ok'=>false,'error'=>'DB update failed','db_error'=>$wpdb->last_error], 500);
        }

        $row = $wpdb->get_row($wpdb->prepare("
            SELECT id, name, description, price, duration_add_min, is_active
            FROM {$t} WHERE id=%d
        ", $id), ARRAY_A);

        if (!$row) return new WP_REST_Response(['ok'=>false,'error'=>'Not found'], 404);
        return new WP_REST_Response(['ok'=>true,'item'=>$row], 200);
    }

    public function delete(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $t = $wpdb->prefix . 'pup_options';
        $id = (int)$req['id'];

        // soft delete
        $ok = $wpdb->update($t, ['is_active'=>0], ['id'=>$id], ['%d'], ['%d']);
        if ($ok === false) return new WP_REST_Response(['ok'=>false,'error'=>'DB delete failed'], 500);

        return new WP_REST_Response(['ok'=>true], 200);
    }

    private function sanitize_payload(WP_REST_Request $req): array
    {
        $name = sanitize_text_field((string)$req->get_param('name'));
        $desc = wp_kses_post((string)$req->get_param('description'));

        // DECIMAL: on normalise "12,50" -> "12.50"
        $priceRaw = (string)$req->get_param('price');
        $priceRaw = str_replace(',', '.', $priceRaw);
        $price = (float)$priceRaw;

        $dur = (int)$req->get_param('duration_add_min');
        if ($dur < 0) $dur = 0;

        $isActive = (int)!!$req->get_param('is_active');

        return [
            'name' => $name ?: 'Nouvelle option',
            'description' => $desc,
            'price' => $price,
            'duration_add_min' => $dur,
            'is_active' => $isActive ? 1 : 0,
        ];
    }
}

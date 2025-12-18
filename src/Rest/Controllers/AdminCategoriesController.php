<?php
namespace PUP\Booking\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class AdminCategoriesController
{
  public function register_routes(): void
  {
    register_rest_route('pup/v1', '/admin/categories', [
      [
        'methods' => 'GET',
        'callback' => [$this, 'list'],
        'permission_callback' => [$this, 'can_manage'],
      ],
      [
        'methods' => 'POST',
        'callback' => [$this, 'create'],
        'permission_callback' => [$this, 'can_manage'],
      ],
    ]);

    register_rest_route('pup/v1', '/admin/categories/(?P<id>\d+)', [
      [
        'methods' => 'PUT',
        'callback' => [$this, 'update'],
        'permission_callback' => [$this, 'can_manage'],
      ],
      [
        'methods' => 'DELETE',
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
    $t = $wpdb->prefix . 'pup_service_categories';
    $rows = $wpdb->get_results("SELECT * FROM {$t} ORDER BY sort_order ASC, name ASC", ARRAY_A) ?: [];
    return new WP_REST_Response(['ok'=>true,'items'=>$rows], 200);
  }

  public function create(WP_REST_Request $req): WP_REST_Response
  {
    global $wpdb;
    $t = $wpdb->prefix . 'pup_service_categories';

    $name = sanitize_text_field((string)$req->get_param('name'));
    $slug = sanitize_title((string)$req->get_param('slug'));
    $desc = sanitize_textarea_field((string)$req->get_param('description'));
    $sort = (int)$req->get_param('sort_order');
    $active = (int)$req->get_param('is_active') ? 1 : 0;

    if (!$name) return new WP_REST_Response(['ok'=>false,'error'=>'name required'], 400);
    if (!$slug) $slug = sanitize_title($name);

    $ok = $wpdb->insert($t, [
      'name'=>$name,
      'slug'=>$slug,
      'description'=>$desc ?: null,
      'sort_order'=>$sort,
      'is_active'=>$active,
      'created_at'=>current_time('mysql'),
      'updated_at'=>current_time('mysql'),
    ]);

    if (!$ok) return new WP_REST_Response(['ok'=>false,'error'=>$wpdb->last_error ?: 'insert failed'], 500);

    return new WP_REST_Response(['ok'=>true,'id'=>(int)$wpdb->insert_id], 201);
  }

  public function update(WP_REST_Request $req): WP_REST_Response
  {
    global $wpdb;
    $t = $wpdb->prefix . 'pup_service_categories';
    $id = (int)$req['id'];

    $data = [];
    if ($req->has_param('name')) $data['name'] = sanitize_text_field((string)$req->get_param('name'));
    if ($req->has_param('slug')) $data['slug'] = sanitize_title((string)$req->get_param('slug'));
    if ($req->has_param('description')) $data['description'] = sanitize_textarea_field((string)$req->get_param('description'));
    if ($req->has_param('sort_order')) $data['sort_order'] = (int)$req->get_param('sort_order');
    if ($req->has_param('is_active')) $data['is_active'] = (int)$req->get_param('is_active') ? 1 : 0;

    if (!$data) return new WP_REST_Response(['ok'=>false,'error'=>'no fields'], 400);

    $data['updated_at'] = current_time('mysql');
    $wpdb->update($t, $data, ['id'=>$id]);

    if ($wpdb->last_error) return new WP_REST_Response(['ok'=>false,'error'=>$wpdb->last_error], 500);
    return new WP_REST_Response(['ok'=>true], 200);
  }

  public function delete(WP_REST_Request $req): WP_REST_Response
  {
    global $wpdb;
    $id = (int)$req['id'];

    // soft guard: emp�cher suppression si des services pointent dessus (MVP)
    $tS = $wpdb->prefix . 'pup_services';
    $count = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tS} WHERE category_id=%d", $id));
    if ($count > 0) {
      return new WP_REST_Response(['ok'=>false,'error'=>"Category used by {$count} services"], 409);
    }

    $t = $wpdb->prefix . 'pup_service_categories';
    $wpdb->delete($t, ['id'=>$id]);
    if ($wpdb->last_error) return new WP_REST_Response(['ok'=>false,'error'=>$wpdb->last_error], 500);
    return new WP_REST_Response(['ok'=>true], 200);
  }
}

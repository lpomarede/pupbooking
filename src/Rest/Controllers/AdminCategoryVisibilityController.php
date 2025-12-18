<?php
namespace PUP\Booking\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class AdminCategoryVisibilityController
{
  public function register_routes(): void
  {
    register_rest_route('pup/v1', '/admin/category-visibility', [
      'methods' => 'GET',
      'callback' => [$this, 'matrix'],
      'permission_callback' => [$this, 'can_manage'],
    ]);

    register_rest_route('pup/v1', '/admin/category-visibility', [
      'methods' => 'POST',
      'callback' => [$this, 'save'],
      'permission_callback' => [$this, 'can_manage'],
    ]);
  }

  public function can_manage(): bool
  {
    return current_user_can('manage_options');
  }

  public function matrix(WP_REST_Request $req): WP_REST_Response
  {
    global $wpdb;
    $tCats = $wpdb->prefix.'pup_service_categories';
    $tCustCats = $wpdb->prefix.'pup_customer_categories';
    $tVis = $wpdb->prefix.'pup_category_visibility';

    $cats = $wpdb->get_results("SELECT id,name FROM {$tCats} WHERE is_active=1 ORDER BY sort_order ASC, name ASC", ARRAY_A) ?: [];
    $ccs  = $wpdb->get_results("SELECT id,name FROM {$tCustCats} WHERE is_active=1 ORDER BY name ASC", ARRAY_A) ?: [];

    $rules = $wpdb->get_results("SELECT category_id, customer_category_id, is_visible FROM {$tVis}", ARRAY_A) ?: [];
    $map = [];
    foreach ($rules as $r) {
      $map[(int)$r['category_id']][(int)$r['customer_category_id']] = (int)$r['is_visible'];
    }

    return new WP_REST_Response(['ok'=>true,'categories'=>$cats,'customer_categories'=>$ccs,'rules'=>$map], 200);
  }

  public function save(WP_REST_Request $req): WP_REST_Response
  {
    global $wpdb;
    $payload = $req->get_json_params();
    if (!is_array($payload)) return new WP_REST_Response(['ok'=>false,'error'=>'Invalid JSON'], 400);

    $categoryId = (int)($payload['category_id'] ?? 0);
    $customerCategoryId = (int)($payload['customer_category_id'] ?? 0);
    $isVisible = isset($payload['is_visible']) && (int)$payload['is_visible'] ? 1 : 0;

    if (!$categoryId || !$customerCategoryId) {
      return new WP_REST_Response(['ok'=>false,'error'=>'category_id and customer_category_id required'], 400);
    }

    $tVis = $wpdb->prefix.'pup_category_visibility';
    // UPSERT
    $exists = (int)$wpdb->get_var($wpdb->prepare("
      SELECT COUNT(*) FROM {$tVis} WHERE category_id=%d AND customer_category_id=%d
    ", $categoryId, $customerCategoryId));

    if ($exists) {
      $wpdb->update($tVis, ['is_visible'=>$isVisible], [
        'category_id'=>$categoryId,
        'customer_category_id'=>$customerCategoryId,
      ]);
    } else {
      $wpdb->insert($tVis, [
        'category_id'=>$categoryId,
        'customer_category_id'=>$customerCategoryId,
        'is_visible'=>$isVisible,
      ]);
    }

    if ($wpdb->last_error) return new WP_REST_Response(['ok'=>false,'error'=>$wpdb->last_error], 500);
    return new WP_REST_Response(['ok'=>true], 200);
  }
}

<?php
namespace PUP\Booking\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class PublicCatalogController
{
  public function register_routes(): void
  {
    register_rest_route('pup/v1', '/public/catalog', [
      'methods'  => 'GET',
      'callback' => [$this, 'get'],
      'permission_callback' => '__return_true',
    ]);
  }

  public function get(WP_REST_Request $req): WP_REST_Response
  {
    global $wpdb;

    $customerCategoryId = $req->get_param('customer_category_id');
    $customerCategoryId = ($customerCategoryId === null || $customerCategoryId === '') ? null : (int)$customerCategoryId;

    $tCats = $wpdb->prefix.'pup_service_categories';
    $tSvc  = $wpdb->prefix.'pup_services';
    $tVis  = $wpdb->prefix.'pup_category_visibility';

    // Catégories actives
    $cats = $wpdb->get_results("
      SELECT id, name, slug, description, sort_order
      FROM {$tCats}
      WHERE is_active=1
      ORDER BY sort_order ASC, name ASC
    ", ARRAY_A) ?: [];

    // Services actifs (avec category + booking_mode)
    $tPrices = $wpdb->prefix.'pup_service_prices';
    
    $svcs = $wpdb->get_results("
      SELECT
        s.id, s.name, s.description, s.category_id, s.booking_mode, s.duration_min, s.type, s.capacity_max,
        COALESCE(p.price, 0.00) AS price,
        COALESCE(p.currency, 'EUR') AS currency
      FROM {$tSvc} s
      LEFT JOIN {$tPrices} p
        ON p.service_id = s.id
       AND p.category_id = 0
       AND p.is_active = 1
      WHERE s.is_active=1
      ORDER BY s.id ASC
    ", ARRAY_A) ?: [];


    // Visibilité (si on fournit un customer_category_id)
    $visibleMap = null;
    if ($customerCategoryId) {
      $rows = $wpdb->get_results($wpdb->prepare("
        SELECT category_id, is_visible
        FROM {$tVis}
        WHERE customer_category_id=%d
      ", $customerCategoryId), ARRAY_A) ?: [];

      $visibleMap = [];
      foreach ($rows as $r) {
        $visibleMap[(int)$r['category_id']] = (int)$r['is_visible'];
      }

      // Si une règle existe => on l’applique. Sinon: visible par défaut.
      $cats = array_values(array_filter($cats, function($c) use ($visibleMap) {
        $cid = (int)$c['id'];
        return !array_key_exists($cid, $visibleMap) ? true : ((int)$visibleMap[$cid] === 1);
      }));
    }

    return new WP_REST_Response([
      'ok' => true,
      'categories' => $cats,
      'services' => $svcs,
      'visibility' => $visibleMap,
    ], 200);
  }
}

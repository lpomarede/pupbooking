<?php
namespace PUP\Booking\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class PublicServiceOptionsController
{
  public function register_routes(): void
  {
    register_rest_route('pup/v1', '/public/services/(?P<id>\d+)/options', [
      'methods'  => 'GET',
      'callback' => [$this, 'get'],
      'permission_callback' => '__return_true',
    ]);
  }

  public function get(WP_REST_Request $req): WP_REST_Response
  {
    global $wpdb;
    $serviceId = (int)$req['id'];
    if ($serviceId <= 0) return new WP_REST_Response(['ok'=>false,'error'=>'Invalid service id'], 400);

    $tOpt = $wpdb->prefix.'pup_options';
    $tSO  = $wpdb->prefix.'pup_service_options';
    $tSvc = $wpdb->prefix.'pup_services';

    $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tSvc} WHERE id=%d AND is_active=1", $serviceId));
    if ($exists <= 0) return new WP_REST_Response(['ok'=>false,'error'=>'Service not found'], 404);

    // Uniquement les options liées + actives (option + lien)
    $rows = $wpdb->get_results($wpdb->prepare("
      SELECT
        o.id,
        o.name,
        o.description,
        COALESCE(so.price_override, o.price) AS price,
        COALESCE(so.duration_override_min, o.duration_add_min) AS duration_add_min,
        so.sort_order
      FROM {$tSO} so
      JOIN {$tOpt} o ON o.id = so.option_id
      WHERE so.service_id=%d
        AND so.is_active=1
        AND o.is_active=1
      ORDER BY so.sort_order ASC, o.id ASC
    ", $serviceId), ARRAY_A) ?: [];

    return new WP_REST_Response(['ok'=>true,'service_id'=>$serviceId,'items'=>$rows], 200);
  }
}

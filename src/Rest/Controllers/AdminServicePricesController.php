<?php
namespace PUP\Booking\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class AdminServicePricesController
{
  public function register_routes(): void
  {
    register_rest_route('pup/v1', '/admin/services/(?P<id>\d+)/prices', [
      [
        'methods'  => 'GET',
        'callback' => [$this, 'get'],
        'permission_callback' => [$this, 'can_manage'],
      ],
      [
        'methods'  => 'PUT',
        'callback' => [$this, 'save'],
        'permission_callback' => [$this, 'can_manage'],
      ],
    ]);
  }

  public function can_manage(): bool
  {
    return current_user_can('manage_options');
  }

  public function get(WP_REST_Request $req): WP_REST_Response
  {
    global $wpdb;
    $serviceId = (int)$req['id'];
    if ($serviceId <= 0) return new WP_REST_Response(['ok'=>false,'error'=>'Invalid service id'], 400);

    $tSvc = $wpdb->prefix.'pup_services';
    $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tSvc} WHERE id=%d", $serviceId));
    if ($exists <= 0) return new WP_REST_Response(['ok'=>false,'error'=>'Service not found'], 404);

    $tP = $wpdb->prefix.'pup_service_prices';
    $rows = $wpdb->get_results($wpdb->prepare("
      SELECT id, service_id, category_id, price, currency, is_active, created_at
      FROM {$tP}
      WHERE service_id=%d
      ORDER BY category_id ASC, id DESC
    ", $serviceId), ARRAY_A) ?: [];

    return new WP_REST_Response(['ok'=>true,'service_id'=>$serviceId,'items'=>$rows], 200);
  }

  public function save(WP_REST_Request $req): WP_REST_Response
  {
    global $wpdb;
    $serviceId = (int)$req['id'];
    if ($serviceId <= 0) return new WP_REST_Response(['ok'=>false,'error'=>'Invalid service id'], 400);

    $items = $req->get_param('items');
    if (!is_array($items)) return new WP_REST_Response(['ok'=>false,'error'=>'items must be an array'], 400);

    $tSvc = $wpdb->prefix.'pup_services';
    $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tSvc} WHERE id=%d", $serviceId));
    if ($exists <= 0) return new WP_REST_Response(['ok'=>false,'error'=>'Service not found'], 404);

    $tP = $wpdb->prefix.'pup_service_prices';

    try {
      $wpdb->query('START TRANSACTION');

      // MVP simple : on purge puis on recrée (comme service_options)
      $wpdb->query($wpdb->prepare("DELETE FROM {$tP} WHERE service_id=%d", $serviceId));
      if ($wpdb->last_error) throw new \Exception('Delete prices failed: '.$wpdb->last_error);

      foreach ($items as $it) {
        $catId = (int)($it['category_id'] ?? 0); // 0 = prix par défaut
        if ($catId < 0) $catId = 0;

        $priceRaw = (string)($it['price'] ?? '0');
        $priceRaw = str_replace(',', '.', $priceRaw);
        $price = (float)$priceRaw;
        if ($price < 0) $price = 0;

        $currency = strtoupper(sanitize_text_field((string)($it['currency'] ?? 'EUR')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) $currency = 'EUR';

        $isActive = (int)($it['is_active'] ?? 1) ? 1 : 0;

        $ok = $wpdb->insert($tP, [
          'service_id' => $serviceId,
          'category_id' => $catId,
          'price' => $price,
          'currency' => $currency,
          'is_active' => $isActive,
          'created_at' => current_time('mysql'),
        ], ['%d','%d','%f','%s','%d','%s']);

        if (!$ok) throw new \Exception('Insert price failed: '.($wpdb->last_error ?: ''));
      }

      $wpdb->query('COMMIT');
      return $this->get($req);

    } catch (\Throwable $e) {
      $wpdb->query('ROLLBACK');
      return new WP_REST_Response(['ok'=>false,'error'=>$e->getMessage()], 500);
    }
  }
}

<?php
namespace PUP\Booking\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class AdminServiceEmployeesController
{
  public function register_routes(): void
  {
    register_rest_route('pup/v1', '/admin/services/(?P<id>\d+)/employees', [
      [
        'methods' => 'GET',
        'callback' => [$this, 'get'],
        'permission_callback' => [$this, 'can_manage'],
      ],
      [
        'methods' => 'PUT',
        'callback' => [$this, 'save'],
        'permission_callback' => [$this, 'can_manage'],
      ],
    ]);
  }

  public function can_manage(): bool { return current_user_can('manage_options'); }

  public function get(WP_REST_Request $req): WP_REST_Response
  {
    global $wpdb;
    $serviceId = (int)$req['id'];

    $tLink = $wpdb->prefix.'pup_service_employees';
    $tEmp  = $wpdb->prefix.'pup_employees';

    $rows = $wpdb->get_results($wpdb->prepare("
      SELECT e.id, e.display_name, e.kind, e.capacity, e.is_active
      FROM {$tLink} se
      JOIN {$tEmp} e ON e.id = se.employee_id
      WHERE se.service_id = %d
      ORDER BY e.kind ASC, e.display_name ASC
    ", $serviceId), ARRAY_A) ?: [];

    return new WP_REST_Response(['ok'=>true,'items'=>$rows], 200);
  }

  public function save(WP_REST_Request $req): WP_REST_Response
  {
    global $wpdb;
    $serviceId = (int)$req['id'];
    $ids = $req->get_param('employee_ids');
    if (!is_array($ids)) $ids = [];

    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ids = array_values(array_filter($ids, fn($x) => $x > 0));

    $tLink = $wpdb->prefix.'pup_service_employees';

    try {
      $wpdb->query('START TRANSACTION');

      $wpdb->query($wpdb->prepare("DELETE FROM {$tLink} WHERE service_id=%d", $serviceId));
      if ($wpdb->last_error) throw new \Exception($wpdb->last_error);

      foreach ($ids as $eid) {
        $ok = $wpdb->insert($tLink, ['service_id'=>$serviceId,'employee_id'=>$eid], ['%d','%d']);
        if (!$ok) throw new \Exception($wpdb->last_error ?: 'insert failed');
      }

      $wpdb->query('COMMIT');
      return $this->get($req);

    } catch (\Throwable $e) {
      $wpdb->query('ROLLBACK');
      return new WP_REST_Response(['ok'=>false,'error'=>$e->getMessage()], 500);
    }
  }
}

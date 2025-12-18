<?php
namespace PUP\Booking\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class AdminServiceOptionsController
{
    public function register_routes(): void
    {
        register_rest_route('pup/v1', '/admin/services/(?P<id>\d+)/options', [
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

        $tSvc = $wpdb->prefix . 'pup_services';
        $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tSvc} WHERE id=%d", $serviceId));
        if ($exists <= 0) return new WP_REST_Response(['ok'=>false,'error'=>'Service not found'], 404);

        $tOpt = $wpdb->prefix . 'pup_options';
        $tSO  = $wpdb->prefix . 'pup_service_options';

        // On renvoie toutes les options + info "linked"
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT
              o.id,
              o.name,
              o.price,
              o.duration_add_min,
              o.is_active,
              so.service_id IS NOT NULL AS linked,
              COALESCE(so.sort_order, 0) AS sort_order,
              so.price_override,
              so.duration_override_min,
              COALESCE(so.is_active, 1) AS link_is_active
            FROM {$tOpt} o
            LEFT JOIN {$tSO} so
              ON so.option_id = o.id AND so.service_id = %d
            ORDER BY linked DESC, sort_order ASC, o.id DESC
        ", $serviceId), ARRAY_A);

        return new WP_REST_Response(['ok'=>true,'service_id'=>$serviceId,'items'=>$rows ?: []], 200);
    }

    public function save(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;

        $serviceId = (int)$req['id'];

        $tSvc = $wpdb->prefix . 'pup_services';
        $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tSvc} WHERE id=%d", $serviceId));
        if ($exists <= 0) return new WP_REST_Response(['ok'=>false,'error'=>'Service not found'], 404);

        $items = $req->get_param('items');
        if (!is_array($items)) $items = [];

        $tSO = $wpdb->prefix . 'pup_service_options';

        // Stratégie MVP : on remplace tout (simple, robuste)
        $wpdb->query($wpdb->prepare("DELETE FROM {$tSO} WHERE service_id=%d", $serviceId));

        foreach ($items as $it) {
            $optionId = (int)($it['option_id'] ?? 0);
            if ($optionId <= 0) continue;

            $sort = (int)($it['sort_order'] ?? 0);

            $priceOverride = $it['price_override'] ?? null;
            if ($priceOverride === '' || $priceOverride === null) {
                $priceOverride = null;
            } else {
                $priceOverride = (float)str_replace(',', '.', (string)$priceOverride);
            }

            $durOverride = $it['duration_override_min'] ?? null;
            if ($durOverride === '' || $durOverride === null) {
                $durOverride = null;
            } else {
                $durOverride = max(0, (int)$durOverride);
            }

            $linkActive = (int)($it['is_active'] ?? 1) ? 1 : 0;

            $ok = $wpdb->insert($tSO, [
                'service_id' => $serviceId,
                'option_id' => $optionId,
                'sort_order' => $sort,
                'price_override' => $priceOverride,
                'duration_override_min' => $durOverride,
                'is_active' => $linkActive,
            ], ['%d','%d','%d','%f','%d','%d']);

            if (!$ok) {
                return new WP_REST_Response(['ok'=>false,'error'=>'DB insert failed','db_error'=>$wpdb->last_error], 500);
            }
        }

        // Renvoie l'état
        return $this->get($req);
    }
}

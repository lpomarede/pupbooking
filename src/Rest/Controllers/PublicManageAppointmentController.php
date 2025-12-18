<?php
namespace PUP\Booking\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class PublicManageAppointmentController
{
    public function register_routes(): void
    {
        register_rest_route('pup/v1', '/public/manage', [
            'methods' => 'GET',
            'callback' => [$this, 'get'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('pup/v1', '/public/manage/cancel', [
            'methods' => 'POST',
            'callback' => [$this, 'cancel'],
            'permission_callback' => '__return_true',
        ]);
        
        register_rest_route('pup/v1', '/public/manage/reschedule', [
          'methods' => 'POST',
          'callback' => [$this, 'reschedule'],
          'permission_callback' => '__return_true',
        ]);
    }

    private function findByToken(string $token): ?array
    {
        global $wpdb;
        $hash = hash('sha256', $token);
        $tA = $wpdb->prefix . 'pup_appointments';
        $tS = $wpdb->prefix . 'pup_services';
        $tE = $wpdb->prefix . 'pup_employees';

        $row = $wpdb->get_row($wpdb->prepare("
          SELECT a.*, s.name AS service_name
          FROM {$tA} a
          JOIN {$tS} s ON s.id=a.service_id
          WHERE a.manage_token_hash=%s
            AND (a.manage_token_expires IS NULL OR a.manage_token_expires > %s)
          LIMIT 1
        ", $hash, current_time('mysql')), ARRAY_A);

        if (!$row) return null;
        return $row;
    }

    public function get(WP_REST_Request $req): WP_REST_Response
    {
        $token = sanitize_text_field((string)$req->get_param('token'));
        if (!$token) return new WP_REST_Response(['ok'=>false,'error'=>'Missing token'], 400);

        $row = $this->findByToken($token);
        if (!$row) return new WP_REST_Response(['ok'=>false,'error'=>'Invalid token'], 404);

        return new WP_REST_Response(['ok'=>true,'appointment'=>[
            'id' => (int)$row['id'],
            'service' => $row['service_name'],
            'start_dt' => $row['start_dt'],
            'end_dt' => $row['end_dt'],
            'status' => $row['status'],
            'service_id' => (int)$row['service_id'],
            'customer_email' => $row['customer_email'],
        ]], 200);
    }

    public function cancel(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $token = sanitize_text_field((string)$req->get_param('token'));
        if (!$token) return new WP_REST_Response(['ok'=>false,'error'=>'Missing token'], 400);

        $row = $this->findByToken($token);
        if (!$row) return new WP_REST_Response(['ok'=>false,'error'=>'Invalid token'], 404);

        if (in_array($row['status'], ['cancelled','completed'], true)) {
            return new WP_REST_Response(['ok'=>true,'status'=>$row['status']], 200);
        }

        $tA = $wpdb->prefix . 'pup_appointments';
        $tAlloc = $wpdb->prefix . 'pup_appointment_allocations';

        $wpdb->update($tA, ['status'=>'cancelled'], ['id'=>(int)$row['id']]);
        $wpdb->delete($tAlloc, ['appointment_id'=>(int)$row['id']], ['%d']);

        return new WP_REST_Response(['ok'=>true,'status'=>'cancelled'], 200);
    }
    
    public function reschedule(WP_REST_Request $req): WP_REST_Response
    {
    global $wpdb;

    $token = sanitize_text_field((string)$req->get_param('token'));
    $date  = sanitize_text_field((string)$req->get_param('date')); // YYYY-MM-DD
    $time  = sanitize_text_field((string)$req->get_param('time')); // HH:MM
    if (!$token || !$date || !$time) {
        return new WP_REST_Response(['ok'=>false,'error'=>'Missing fields'], 400);
    }

    $row = $this->findByToken($token);
    if (!$row) return new WP_REST_Response(['ok'=>false,'error'=>'Invalid token'], 404);

    if (in_array($row['status'], ['cancelled','done','no_show'], true)) {
        return new WP_REST_Response(['ok'=>false,'error'=>'Appointment not reschedulable'], 409);
    }

    // Charger service (durée + buffers)
    $tServices = $wpdb->prefix . 'pup_services';
    $service = $wpdb->get_row($wpdb->prepare("
        SELECT id, name, duration_min, buffer_before_min, buffer_after_min, cancel_limit_min
        FROM {$tServices}
        WHERE id=%d
    ", (int)$row['service_id']), ARRAY_A);

    if (!$service) return new WP_REST_Response(['ok'=>false,'error'=>'Service not found'], 404);

    $tz = wp_timezone();
    $start = \DateTimeImmutable::createFromFormat('Y-m-d H:i', "{$date} {$time}", $tz);
    if (!$start) return new WP_REST_Response(['ok'=>false,'error'=>'Invalid date/time'], 400);

    $durationMin = (int)$service['duration_min'];
    $bufBefore   = (int)$service['buffer_before_min'];
    $bufAfter    = (int)$service['buffer_after_min'];

    $end = $start->modify("+{$durationMin} minutes");

    $allocStart = $start->modify("-{$bufBefore} minutes");
    $allocEnd   = $start->modify("+" . ($durationMin + $bufAfter) . " minutes");

    $tAlloc = $wpdb->prefix . 'pup_appointment_allocations';
    $tAppt  = $wpdb->prefix . 'pup_appointments';

    // Récupérer l’employee_id lié (MVP = 1 allocation employee)
    $alloc = $wpdb->get_row($wpdb->prepare("
        SELECT id, employee_id, resource_id
        FROM {$tAlloc}
        WHERE appointment_id=%d
        ORDER BY id ASC
        LIMIT 1
    ", (int)$row['id']), ARRAY_A);

    if (!$alloc || empty($alloc['employee_id'])) {
        return new WP_REST_Response(['ok'=>false,'error'=>'Allocation not found'], 500);
    }

    $employeeId = (int)$alloc['employee_id'];

    // Vérifier overlap en excluant CE rdv
    $overlap = (int)$wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*)
        FROM {$tAlloc}
        WHERE employee_id=%d
          AND appointment_id <> %d
          AND start_dt < %s
          AND end_dt > %s
    ", $employeeId, (int)$row['id'], $allocEnd->format('Y-m-d H:i:s'), $allocStart->format('Y-m-d H:i:s')));

    if ($overlap > 0) {
        return new WP_REST_Response(['ok'=>false,'error'=>'Slot no longer available'], 409);
    }

    // Transaction (si dispo)
    $wpdb->query('START TRANSACTION');

    try {
        $ok1 = $wpdb->update($tAppt, [
            'start_dt' => $start->format('Y-m-d H:i:s'),
            'end_dt'   => $end->format('Y-m-d H:i:s'),
            'updated_at' => current_time('mysql'),
        ], ['id' => (int)$row['id']]);

        if ($ok1 === false) throw new \Exception($wpdb->last_error ?: 'Update appointment failed');

        // Mets à jour toutes les allocations (si un jour tu en as plusieurs, elles suivront)
        $ok2 = $wpdb->query($wpdb->prepare("
            UPDATE {$tAlloc}
            SET start_dt=%s, end_dt=%s
            WHERE appointment_id=%d
        ", $allocStart->format('Y-m-d H:i:s'), $allocEnd->format('Y-m-d H:i:s'), (int)$row['id']));

        if ($ok2 === false) throw new \Exception($wpdb->last_error ?: 'Update allocations failed');

        $wpdb->query('COMMIT');

        return new WP_REST_Response(['ok'=>true,'appointment'=>[
            'id' => (int)$row['id'],
            'service' => $service['name'],
            'start_dt' => $start->format('Y-m-d H:i:s'),
            'end_dt' => $end->format('Y-m-d H:i:s'),
            'status' => $row['status'],
        ]], 200);

    } catch (\Throwable $e) {
        $wpdb->query('ROLLBACK');
        return new WP_REST_Response(['ok'=>false,'error'=>$e->getMessage()], 500);
    }
}

    
}

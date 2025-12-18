<?php
namespace PUP\Booking\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class PublicAppointmentsController
{
    public function register_routes(): void
    {
        register_rest_route('pup/v1', '/public/appointments', [
            'methods'  => 'POST',
            'callback' => [$this, 'create'],
            'permission_callback' => '__return_true', // public (MVP)
        ]);
    }

    public function create(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;

        // Anti-spam ultra simple (MVP)
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = 'pup_rate_' . md5($ip);
        $count = (int)get_transient($key);
        if ($count >= 20) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Too many requests'], 429);
        }
        set_transient($key, $count + 1, 5 * MINUTE_IN_SECONDS);

        $serviceId  = (int)$req->get_param('service_id');
        $employeeId = (int)$req->get_param('employee_id');
        $date       = sanitize_text_field((string)$req->get_param('date'));      // YYYY-MM-DD
        $time       = sanitize_text_field((string)$req->get_param('time'));      // HH:MM
        $firstName  = sanitize_text_field((string)$req->get_param('first_name'));
        $lastName   = sanitize_text_field((string)$req->get_param('last_name'));
        $email      = sanitize_email((string)$req->get_param('email'));
        $phone      = sanitize_text_field((string)$req->get_param('phone'));
        $qty        = (int)$req->get_param('qty');
        if ($qty <= 0) $qty = 1;

        if (!$serviceId || !$employeeId || !$date || !$time || !$email) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Missing required fields'], 400);
        }

        $tz = wp_timezone();

        // Service
        $tServices = $wpdb->prefix . 'pup_services';
        $service = $wpdb->get_row($wpdb->prepare("
            SELECT id, name, duration_min, buffer_before_min, buffer_after_min, type, capacity_max, cancel_limit_min, min_notice_min, is_active
            FROM {$tServices}
            WHERE id=%d
        ", $serviceId), ARRAY_A);

        if (!$service || (int)$service['is_active'] !== 1) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Service not found'], 404);
        }

        // Employee active
        $tEmp = $wpdb->prefix . 'pup_employees';
        $emp = $wpdb->get_row($wpdb->prepare("
            SELECT id, display_name
            FROM {$tEmp}
            WHERE id=%d AND is_active=1
        ", $employeeId), ARRAY_A);

        if (!$emp) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Employee not found'], 404);
        }

        // Datetimes
        $start = \DateTimeImmutable::createFromFormat('Y-m-d H:i', "{$date} {$time}", $tz);
        $nowTs = (int) current_time('timestamp', true);
        $now = (new \DateTimeImmutable('@' . $nowTs))->setTimezone($tz);

        // Interdire toute réservation dans le passé (ou à l’instant)
        if ($start <= $now) {
            return new WP_REST_Response(['ok'=>false,'error'=>'Cannot book in the past'], 409);
        }
        
        // Respecter le délai mini de réservation (min_notice_min)
        $minNotice = (int)$service['min_notice_min'];
        if ($minNotice > 0) {
            $minStart = $now->modify("+{$minNotice} minutes");
            if ($start < $minStart) {
                return new WP_REST_Response(['ok'=>false,'error'=>'Too late to book this slot'], 409);
            }
        }
        if (!$start) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Invalid date/time'], 400);
        }

        $durationMin = (int)$service['duration_min'];
        $bufBefore   = (int)$service['buffer_before_min'];
        $bufAfter    = (int)$service['buffer_after_min'];

        $end = $start->modify("+{$durationMin} minutes");

        // Re-check dispo via allocations (sï¿½curitï¿½)
        $allocStart = $start->modify("-{$bufBefore} minutes");
        $allocEnd   = $start->modify("+" . ($durationMin + $bufAfter) . " minutes");

        $tAlloc = $wpdb->prefix . 'pup_appointment_allocations';
        $overlap = (int)$wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM {$tAlloc}
            WHERE employee_id=%d
              AND start_dt < %s
              AND end_dt > %s
        ", $employeeId, $allocEnd->format('Y-m-d H:i:s'), $allocStart->format('Y-m-d H:i:s')));

        if ($overlap > 0) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Slot no longer available'], 409);
        }

        // Capacity check (MVP)
        if ($service['type'] === 'capacity' && !empty($service['capacity_max'])) {
            $tAppt = $wpdb->prefix . 'pup_appointments';
            $capMax = (int)$service['capacity_max'];

            $sumQty = (int)$wpdb->get_var($wpdb->prepare("
                SELECT COALESCE(SUM(qty),0)
                FROM {$tAppt}
                WHERE service_id=%d
                  AND start_dt=%s
                  AND status IN ('pending','confirmed')
            ", $serviceId, $start->format('Y-m-d H:i:s')));

            if ($sumQty + $qty > $capMax) {
                return new WP_REST_Response(['ok' => false, 'error' => 'Not enough capacity'], 409);
            }
        }

        // Create customer row (optional MVP)
        $tCust = $wpdb->prefix . 'pup_customers';
        $customerId = null;
        $existingCustomerId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$tCust} WHERE email=%s", $email));
        if ($existingCustomerId) {
            $customerId = (int)$existingCustomerId;
            $wpdb->update($tCust, [
                'phone' => $phone ?: null,
                'first_name' => $firstName ?: null,
                'last_name' => $lastName ?: null,
                'updated_at' => current_time('mysql'),
            ], ['id' => $customerId]);
        } else {
            $wpdb->insert($tCust, [
                'email' => $email,
                'phone' => $phone ?: null,
                'first_name' => $firstName ?: null,
                'last_name' => $lastName ?: null,
                'created_at' => current_time('mysql'),
            ]);
            $customerId = (int)$wpdb->insert_id;
        }

        // Insert appointment
        $tAppt = $wpdb->prefix . 'pup_appointments';
        $status = 'pending'; // MVP: on confirme plus tard (paiement etc.)
        $ok = $wpdb->insert($tAppt, [
            'service_id' => $serviceId,
            'customer_id' => $customerId,
            'start_dt' => $start->format('Y-m-d H:i:s'),
            'end_dt' => $end->format('Y-m-d H:i:s'),
            'status' => $status,
            'qty' => $qty,
            'price_total' => 0.00,
            'currency' => 'EUR',
            'notes_customer' => null,
            'notes_internal' => null,
            'created_at' => current_time('mysql'),
        ]);

        if (!$ok) {
            return new WP_REST_Response(['ok' => false, 'error' => $wpdb->last_error ?: 'DB insert failed'], 500);
        }

        $appointmentId = (int)$wpdb->insert_id;
        
        
        // --- manage token (secret link) ---
        $token = bin2hex(random_bytes(24)); // 48 chars hex
        $tokenHash = hash('sha256', $token);
        $expires = (new \DateTimeImmutable('now', wp_timezone()))->modify('+180 days')->format('Y-m-d H:i:s');
        
        $wpdb->update($tAppt, [
          'manage_token_hash' => $tokenHash,
          'manage_token_expires' => $expires,
        ], ['id' => $appointmentId]);
        
        // --- email (HTML) ---
        $manageUrl = add_query_arg(['token' => $token], site_url('/gerer-mon-rdv/'));
        $subject = 'Votre réservation – ' . $service['name'];
        
        $body = '
          <p>Bonjour,</p>
          <p>Votre réservation a bien été enregistrée.</p>
          <p><b>Prestation :</b> ' . esc_html($service['name']) . '<br>
             <b>Date :</b> ' . esc_html($date) . ' à ' . esc_html($time) . '<br>
             <b>Praticien :</b> ' . esc_html($emp['display_name']) . '</p>
          <p>Gérer votre rendez-vous (annuler / reporter) :</p>
          <p><a href="' . esc_url($manageUrl) . '" style="display:inline-block;padding:10px 14px;border-radius:10px;background:#111;color:#fff;text-decoration:none;">Gérer mon RDV</a></p>
          <p style="color:#666;font-size:12px;">Lien personnel : ne le transférez pas.</p>
        ';
        
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        
        wp_mail($email, $subject, $body, $headers);

        // Insert allocation (employee busy window incl buffers)
        $ok2 = $wpdb->insert($tAlloc, [
            'appointment_id' => $appointmentId,
            'employee_id' => $employeeId,
            'resource_id' => null,
            'start_dt' => $allocStart->format('Y-m-d H:i:s'),
            'end_dt' => $allocEnd->format('Y-m-d H:i:s'),
        ]);

        if (!$ok2) {
            // rollback soft: mark cancelled
            $wpdb->update($tAppt, ['status' => 'cancelled'], ['id' => $appointmentId]);
            return new WP_REST_Response(['ok' => false, 'error' => $wpdb->last_error ?: 'Allocation failed'], 500);
        }

        return new WP_REST_Response([
            'ok' => true,
            'appointment' => [
                'id' => $appointmentId,
                'status' => $status,
                'date' => $date,
                'time' => $time,
                'employee' => $emp['display_name'],
                'service' => $service['name'],
            ]
        ], 201);
    }
}

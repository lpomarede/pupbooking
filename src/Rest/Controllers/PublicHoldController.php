<?php
namespace PUP\Booking\Rest\Controllers;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

class PublicHoldController extends WP_REST_Controller
{
    public function register_routes()
    {
        register_rest_route('pup/v1', '/public/hold', [
            'methods' => 'POST',
            'callback' => [$this, 'hold'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('pup/v1', '/public/confirm', [
            'methods' => 'POST',
            'callback' => [$this, 'confirm'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Step "HOLD" : crée un RDV en statut hold + token + expiration,
     * en vérifiant que le créneau est toujours libre (anti double booking).
     */
    public function hold(WP_REST_Request $req)
    {
        global $wpdb;

        try {
            $body = (array) $req->get_json_params();

            // Anti-spam ultra simple (même logique que PublicAppointmentsController)
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $key = 'pup_rate_' . md5($ip);
            $count = (int) get_transient($key);
            if ($count >= 40) {
                return new WP_REST_Response(['ok' => false, 'error' => 'Too many requests'], 429);
            }
            set_transient($key, $count + 1, 5 * MINUTE_IN_SECONDS);

            $serviceId  = isset($body['service_id']) ? (int) $body['service_id'] : 0;
            $employeeId = isset($body['employee_id']) ? (int) $body['employee_id'] : 0; // MVP: requis
            $date       = isset($body['date']) ? trim((string) $body['date']) : '';
            $time       = isset($body['time']) ? trim((string) $body['time']) : '';

            $firstName  = isset($body['first_name']) ? sanitize_text_field((string) $body['first_name']) : '';
            $lastName   = isset($body['last_name']) ? sanitize_text_field((string) $body['last_name']) : '';
            $email      = isset($body['email']) ? sanitize_email((string) $body['email']) : '';
            $phone      = isset($body['phone']) ? sanitize_text_field((string) $body['phone']) : '';

            $qty        = isset($body['qty']) ? (int) $body['qty'] : 1;
            if ($qty <= 0) $qty = 1;

            // Nouveaux champs customers
            $address    = isset($body['address']) ? sanitize_text_field((string) $body['address']) : null;
            $postalCode = isset($body['postal_code']) ? sanitize_text_field((string) $body['postal_code']) : null;
            $city       = isset($body['city']) ? sanitize_text_field((string) $body['city']) : null;
            $birthday   = isset($body['birthday']) ? trim((string) $body['birthday']) : '';

            if ($serviceId <= 0) return $this->err('service_id manquant.');
            if ($employeeId <= 0) return $this->err('employee_id manquant (MVP).');
            if (!$this->is_iso_date($date)) return $this->err('Date invalide (YYYY-MM-DD).');
            if (!$this->is_hhmm($time)) return $this->err('Heure invalide (HH:MM).');
            if (!$email || !is_email($email)) return $this->err('Email invalide.');
            if ($birthday !== '' && !$this->is_iso_date($birthday)) return $this->err('Birthday invalide (YYYY-MM-DD).');

            $tz = wp_timezone();

            $tServices   = $wpdb->prefix . 'pup_services';
            $tEmployees  = $wpdb->prefix . 'pup_employees';
            $tCustomers  = $wpdb->prefix . 'pup_customers';
            $tAppts      = $wpdb->prefix . 'pup_appointments';
            $tAlloc      = $wpdb->prefix . 'pup_appointment_allocations';
            $tPrices     = $wpdb->prefix . 'pup_service_prices';

            // Service (durée + buffers)
            $service = $wpdb->get_row($wpdb->prepare("
                SELECT id, name, duration_min, buffer_before_min, buffer_after_min
                FROM {$tServices}
                WHERE id=%d
            ", $serviceId), ARRAY_A);

            if (!$service) return $this->err('Service introuvable.', 404);

            // Vérif employé existe + actif
            $empOk = (int) $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$tEmployees}
                WHERE id=%d AND is_active=1
            ", $employeeId));
            if ($empOk <= 0) return $this->err('Ressource / employé introuvable ou inactif.', 404);

            $durationMin = max(1, (int) $service['duration_min']);
            $bufBefore   = max(0, (int) ($service['buffer_before_min'] ?? 0));
            $bufAfter    = max(0, (int) ($service['buffer_after_min'] ?? 0));

            $start = \DateTimeImmutable::createFromFormat('Y-m-d H:i', "{$date} {$time}", $tz);
            if (!$start) return $this->err('Impossible de construire start_dt.');
            $end = $start->modify("+{$durationMin} minutes");

            // Fenêtre d'occupation réelle (buffers)
            $allocStart = $start->modify("-{$bufBefore} minutes");
            $allocEnd   = $start->modify("+" . ($durationMin + $bufAfter) . " minutes");

            // IMPORTANT: re-check dispo (anti double booking) via table allocations
            // On bloque si une allocation existe (confirmed/pending/hold non expiré)
            $nowMysql = current_time('mysql');
            $overlap = (int) $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*)
                FROM {$tAlloc} al
                JOIN {$tAppts} a ON a.id = al.appointment_id
                WHERE al.employee_id = %d
                  AND al.start_dt < %s
                  AND al.end_dt   > %s
                  AND (
                        a.status IN ('confirmed','pending')
                        OR (a.status='hold' AND a.hold_expires_dt IS NOT NULL AND a.hold_expires_dt > %s)
                      )
            ", $employeeId, $allocEnd->format('Y-m-d H:i:s'), $allocStart->format('Y-m-d H:i:s'), $nowMysql));

            if ($overlap > 0) {
                return $this->err('Créneau indisponible (déjà pris).', 409);
            }

            // Upsert customer by email
            $customerId = (int) $wpdb->get_var($wpdb->prepare("
                SELECT id FROM {$tCustomers} WHERE email=%s LIMIT 1
            ", $email));

            $now = current_time('mysql');

            $custData = [
                'email'       => $email,
                'phone'       => $phone ?: null,
                'first_name'  => $firstName ?: null,
                'last_name'   => $lastName ?: null,
                'address'     => $address ?: null,
                'postal_code' => $postalCode ?: null,
                'city'        => $city ?: null,
                'birthday'    => ($birthday !== '' ? $birthday : null),
                'updated_at'  => $now,
            ];

            if ($customerId > 0) {
                $wpdb->update($tCustomers, $custData, ['id' => $customerId]);
            } else {
                $custData['created_at'] = $now;
                $wpdb->insert($tCustomers, $custData);
                $customerId = (int) $wpdb->insert_id;
            }

            if ($customerId <= 0) return $this->err('Impossible de créer/mettre à jour le client.');

            // Tarif service (catégorie 0 par défaut)
            $priceRow = $wpdb->get_row($wpdb->prepare("
                SELECT price, currency
                FROM {$tPrices}
                WHERE service_id=%d AND category_id=0 AND is_active=1
                ORDER BY id DESC
                LIMIT 1
            ", $serviceId), ARRAY_A);

            $unitPrice = 0.00;
            $currency  = 'EUR';

            if ($priceRow) {
                $unitPrice = (float) $priceRow['price'];
                $currency  = (string) ($priceRow['currency'] ?: 'EUR');
            } else {
                // fallback si jamais tu as un champ price_base dans pup_services (certains snapshots l'ont, d'autres non)
                $maybe = $wpdb->get_var($wpdb->prepare("SELECT price_base FROM {$tServices} WHERE id=%d", $serviceId));
                if ($maybe !== null && $maybe !== '') $unitPrice = (float) $maybe;
            }

            $priceTotal = round($unitPrice * $qty, 2);

            // HOLD token
            $token = $this->random_token(32);
            $tokenHash = hash('sha256', $token);

            $holdMinutes = 10;
            $holdExpires = (new \DateTimeImmutable($now, $tz))->modify("+{$holdMinutes} minutes")->format('Y-m-d H:i:s');

            // Transaction: insert appointment + allocation
            $wpdb->query('START TRANSACTION');

            try {
                $apptData = [
                    'service_id'         => $serviceId,
                    'employee_id'        => $employeeId,
                    'customer_id'        => $customerId,
                    'start_dt'           => $start->format('Y-m-d H:i:s'),
                    'end_dt'             => $end->format('Y-m-d H:i:s'),
                    'duration_total_min' => $durationMin,
                    'qty'                => $qty,
                    'price_total'        => $priceTotal,
                    'currency'           => $currency,
                    'status'             => 'hold',
                    'hold_token_hash'    => $tokenHash,
                    'hold_expires_dt'    => $holdExpires,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ];

                $ok = $wpdb->insert($tAppts, $apptData);
                if (!$ok) {
                    throw new \Exception('Insertion appointment hold impossible: ' . ($wpdb->last_error ?: ''));
                }

                $appointmentId = (int) $wpdb->insert_id;
                if ($appointmentId <= 0) {
                    throw new \Exception('Appointment id invalide après insert.');
                }

                // Insert allocation (pour bloquer + permettre reschedule/cancel)
                $ok2 = $wpdb->insert($tAlloc, [
                    'appointment_id' => $appointmentId,
                    'employee_id'    => $employeeId,
                    'resource_id'    => null,
                    'start_dt'       => $allocStart->format('Y-m-d H:i:s'),
                    'end_dt'         => $allocEnd->format('Y-m-d H:i:s'),
                ]);

                if (!$ok2) {
                    throw new \Exception('Allocation hold impossible: ' . ($wpdb->last_error ?: ''));
                }

                $wpdb->query('COMMIT');
            } catch (\Throwable $e) {
                $wpdb->query('ROLLBACK');
                return $this->err($e->getMessage(), 500);
            }

            return new WP_REST_Response([
                'ok'   => true,
                'hold' => [
                    'token'          => $token,
                    'appointment_id' => $appointmentId,
                    'expires_dt'     => $holdExpires,
                ],
                'pricing' => [
                    'unit_price' => $unitPrice,
                    'qty'        => $qty,
                    'total'      => $priceTotal,
                    'currency'   => $currency,
                ],
            ], 200);

        } catch (\Throwable $e) {
            return new WP_REST_Response([
                'ok'    => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Step "CONFIRM" : transforme un hold en confirmed,
     * en re-checkant la dispo (anti race condition) + en générant manage_token.
     */
    public function confirm(WP_REST_Request $req)
    {
        global $wpdb;

        $body = (array) $req->get_json_params();
        $token = isset($body['token']) ? trim((string) $body['token']) : '';

        if (empty($token)) return $this->err('Token manquant.');

        $tokenHash = hash('sha256', $token);
        $tAppts = $wpdb->prefix . 'pup_appointments';
        $tAlloc = $wpdb->prefix . 'pup_appointment_allocations';

        $wpdb->query('START TRANSACTION');

        try {
            // Lock du hold pour éviter double confirm en concurrence
            $appt = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM {$tAppts}
                WHERE hold_token_hash=%s
                  AND status='hold'
                LIMIT 1
                FOR UPDATE
            ", $tokenHash), ARRAY_A);

            if (!$appt) {
                $wpdb->query('ROLLBACK');
                return $this->err('Réservation introuvable ou déjà confirmée.');
            }

            // Expiration
            if (!empty($appt['hold_expires_dt']) && strtotime($appt['hold_expires_dt']) < current_time('timestamp')) {
                $wpdb->query('ROLLBACK');
                return $this->err('Le créneau a expiré, merci de recommencer.', 409);
            }

            $appointmentId = (int) $appt['id'];

            // Récupérer l’allocation liée (MVP: 1 allocation employee)
            $alloc = $wpdb->get_row($wpdb->prepare("
                SELECT id, employee_id, start_dt, end_dt
                FROM {$tAlloc}
                WHERE appointment_id=%d
                ORDER BY id ASC
                LIMIT 1
                FOR UPDATE
            ", $appointmentId), ARRAY_A);

            if (!$alloc || empty($alloc['employee_id'])) {
                $wpdb->query('ROLLBACK');
                return $this->err('Allocation introuvable pour ce hold.', 500);
            }

            $employeeId = (int) $alloc['employee_id'];
            $allocStart = $alloc['start_dt'];
            $allocEnd   = $alloc['end_dt'];

            // Re-check overlap : existe-t-il une autre allocation qui chevauche ?
            $nowMysql = current_time('mysql');
            $overlap = (int) $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*)
                FROM {$tAlloc} al
                JOIN {$tAppts} a ON a.id = al.appointment_id
                WHERE al.employee_id = %d
                  AND al.appointment_id <> %d
                  AND al.start_dt < %s
                  AND al.end_dt   > %s
                  AND (
                        a.status IN ('confirmed','pending')
                        OR (a.status='hold' AND a.hold_expires_dt IS NOT NULL AND a.hold_expires_dt > %s)
                      )
            ", $employeeId, $appointmentId, $allocEnd, $allocStart, $nowMysql));

            if ($overlap > 0) {
                $wpdb->query('ROLLBACK');
                return $this->err('Créneau plus disponible (déjà réservé).', 409);
            }

            // Générer manage token (lien perso gestion rdv)
            $manageToken = bin2hex(random_bytes(24)); // 48 chars hex
            $manageHash  = hash('sha256', $manageToken);
            $manageExp   = (new \DateTimeImmutable('now', wp_timezone()))->modify('+180 days')->format('Y-m-d H:i:s');

            $ok = $wpdb->update($tAppts, [
                'status'               => 'confirmed',
                'updated_at'           => current_time('mysql'),
                'hold_token_hash'      => null,
                'hold_expires_dt'      => null,
                'manage_token_hash'    => $manageHash,
                'manage_token_expires' => $manageExp,
            ], ['id' => $appointmentId]);

            if ($ok === false) {
                throw new \Exception($wpdb->last_error ?: 'Update confirm failed');
            }

            $wpdb->query('COMMIT');

            return new WP_REST_Response([
                'ok' => true,
                'appointment_id' => $appointmentId,
                'manage' => [
                    'token' => $manageToken,
                    'expires_dt' => $manageExp,
                ],
            ], 200);

        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function err($msg, $status = 400)
    {
        return new WP_REST_Response(['ok' => false, 'error' => $msg], $status);
    }

    private function is_iso_date($s)
    {
        if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
        $d = \DateTime::createFromFormat('Y-m-d', $s);
        return $d && $d->format('Y-m-d') === $s;
    }

    private function is_hhmm($s)
    {
        return is_string($s) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $s);
    }

    private function random_token($len = 32)
    {
        $bytes = (int) ceil($len / 2);
        return substr(bin2hex(random_bytes($bytes)), 0, $len);
    }
}

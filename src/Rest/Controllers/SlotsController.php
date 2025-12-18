<?php
namespace PUP\Booking\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class SlotsController
{
    public function register_routes(): void
    {
        register_rest_route('pup/v1', '/slots', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_slots'],
            'permission_callback' => '__return_true', // public
        ]);
    }

    public function get_slots(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;

        $serviceId = (int)$req->get_param('service_id');
        $date      = sanitize_text_field((string)$req->get_param('date')); // YYYY-MM-DD
        
        // Validation date basique
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return new WP_REST_Response(['ok'=>false,'error'=>'Invalid date format (expected YYYY-MM-DD)'], 400);
        }

        $stepMin = (int)$req->get_param('step_min');
        if ($stepMin <= 0) $stepMin = 15; // Valeur par défaut plus sûre

        if (!$serviceId) {
            return new WP_REST_Response(['ok' => false, 'error' => 'service_id is required'], 400);
        }

        // 1. Charger le Service
        $tServices = $wpdb->prefix . 'pup_services';
        $service = $wpdb->get_row($wpdb->prepare("
            SELECT id, name, duration_min, buffer_before_min, buffer_after_min, type, capacity_max, min_notice_min, is_active
            FROM {$tServices}
            WHERE id=%d
        ", $serviceId), ARRAY_A);

        if (!$service || (int)$service['is_active'] !== 1) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Service not found or inactive'], 404);
        }

        // 2. Charger les ressources nécessaires (Intersection / Relation ET)
        // On cherche les employés liés via pup_service_employees
        $tLink = $wpdb->prefix . 'pup_service_employees';
        $tEmp  = $wpdb->prefix . 'pup_employees';

        $resources = $wpdb->get_results($wpdb->prepare("
            SELECT e.id, e.display_name, e.kind, e.timezone
            FROM {$tLink} se
            JOIN {$tEmp} e ON e.id = se.employee_id
            WHERE se.service_id = %d AND e.is_active = 1
        ", $serviceId), ARRAY_A);

        // Si aucune ressource n'est liée, le service n'est pas "jouable" techniquement
        if (empty($resources)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Configuration error: No resources linked to this service'], 500);
        }

        // Configuration Timezone / Dates
        $tz = wp_timezone(); 
        $dayStart = new \DateTimeImmutable($date . ' 00:00:00', $tz);
        $dayEnd   = new \DateTimeImmutable($date . ' 23:59:59', $tz);

        // Délai minimum de réservation (Min Notice)
        $nowTs = (int) current_time('timestamp', true); // GMT timestamp
        $now   = (new \DateTimeImmutable('@' . $nowTs))->setTimezone($tz);
        
        $minStartAllowed = $now;
        if ((int)$service['min_notice_min'] > 0) {
            $minStartAllowed = $now->modify("+{$service['min_notice_min']} minutes");
        }

        // Initialiser l'intersection avec "toute la journée" (timestamp range)
        // On va réduire cet intervalle ressource par ressource.
        $commonIntervals = [
            [$dayStart->getTimestamp(), $dayEnd->getTimestamp()]
        ];

        // 3. Boucle sur chaque ressource pour réduire l'espace des possibles
        foreach ($resources as $res) {
            $resId = (int)$res['id'];
            
            // A. Calculer les disponibilités brutes de la ressource (Horaires - Exceptions)
            $resAvail = $this->get_resource_availability($resId, $date, $dayStart, $dayEnd);

            // B. Soustraire les allocations existantes (Bookings) de cette ressource
            $resBusy = $this->get_resource_allocations($resId, $dayStart, $dayEnd);
            $resFree = $this->subtract_intervals($resAvail, $resBusy);

            // C. Intersection avec le global
            $commonIntervals = $this->intersect_intervals($commonIntervals, $resFree);
            
            // Si à un moment c'est vide, on arrête tout (pas de créneau commun)
            if (empty($commonIntervals)) break;
        }

        // 4. Générer les slots finaux à partir des intervalles communs
        $durationMin = (int)$service['duration_min'];
        $bufBefore   = (int)$service['buffer_before_min'];
        $bufAfter    = (int)$service['buffer_after_min'];

        $slots = [];

        foreach ($commonIntervals as [$tsStart, $tsEnd]) {
            // Convertir en DateTime pour manipuler avec les minutes
            $rangeStart = (new \DateTimeImmutable('@' . $tsStart))->setTimezone($tz);
            $rangeEnd   = (new \DateTimeImmutable('@' . $tsEnd))->setTimezone($tz);

            // Curseur de début
            $cursor = $rangeStart;

            // Arrondir au pas (step)
            $cursor = $this->round_up_to_step($cursor, $stepMin);

            // Si le curseur est avant le délai min de résa (aujourd'hui), on avance
            if ($cursor < $minStartAllowed) {
                $cursor = $minStartAllowed;
                $cursor = $this->round_up_to_step($cursor, $stepMin);
            }

            while (true) {
                // Définition du créneau Client (ex: 10:00 -> 11:00)
                $slotClientStart = $cursor;
                $slotClientEnd   = $slotClientStart->modify("+{$durationMin} minutes");

                // Définition du blocage Technique (Buffer Avant + Slot + Buffer Après)
                // ex: 09:50 -> 11:10 (si 10min avant, 10min après)
                $allocStart = $slotClientStart->modify("-{$bufBefore} minutes");
                $allocEnd   = $slotClientEnd->modify("+{$bufAfter} minutes");

                // Vérification: Est-ce que le blocage technique rentre dans l'intervalle dispo ?
                // allocStart >= rangeStart  ET  allocEnd <= rangeEnd
                if ($allocStart < $rangeStart) {
                    // Pas assez de place avant (buffer), on avance
                    $cursor = $cursor->modify("+{$stepMin} minutes");
                    continue;
                }

                if ($allocEnd > $rangeEnd) {
                    // Dépasse la fin de l'intervalle dispo, on arrête pour ce range
                    break;
                }

                // C'est valide
                $slots[] = $slotClientStart->format('H:i');

                // Slot suivant
                $cursor = $cursor->modify("+{$stepMin} minutes");
            }
        }

        $slots = array_values(array_unique($slots));
        sort($slots);

        // 5. Préparer la réponse pour le Front
        // Le front attend une liste d'items. Pour l'intersection, on renvoie
        // un item unique représentant l'équipe ou le premier "Humain" pour porter l'ID.
        
        $primaryResource = null;
        foreach ($resources as $r) {
            if ($r['kind'] === 'human') {
                $primaryResource = $r;
                break;
            }
        }
        if (!$primaryResource && count($resources) > 0) {
            $primaryResource = $resources[0];
        }

        $items = [];
        if (!empty($slots)) {
            $items[] = [
                'employee_id'   => $primaryResource['id'],
                'employee_name' => $primaryResource['display_name'], // ou "Équipe" si on voulait
                'date'          => $date,
                'slots'         => $slots,
                // Debug info utile
                'resources_checked' => array_column($resources, 'display_name'),
            ];
        }

        return new WP_REST_Response([
            'ok' => true, 
            'service' => [
                'id' => (int)$service['id'],
                'name' => $service['name'],
                'duration_min' => $durationMin,
            ],
            'items' => $items
        ], 200);
    }

    // -------------------------------------------------------------------------
    // HELPERS : Disponibilités & Allocations
    // -------------------------------------------------------------------------

    private function get_resource_availability(int $empId, string $date, \DateTimeImmutable $dayStart, \DateTimeImmutable $dayEnd): array
    {
        global $wpdb;
        $tSched = $wpdb->prefix . 'pup_employee_schedules';
        $tExc   = $wpdb->prefix . 'pup_employee_exceptions';

        // 1. Horaires de base (semaine)
        $weekday = (int)$dayStart->format('N'); // 1 (Lun) - 7 (Dim)
        
        $schedules = $wpdb->get_results($wpdb->prepare("
            SELECT start_time, end_time FROM {$tSched}
            WHERE employee_id=%d AND weekday=%d
            ORDER BY start_time ASC
        ", $empId, $weekday), ARRAY_A);

        $intervals = [];
        if ($schedules) {
            foreach ($schedules as $s) {
                // Construire les timestamps pour ce jour précis
                $st = $this->time_to_ts($dayStart, $s['start_time']);
                $et = $this->time_to_ts($dayStart, $s['end_time']);
                if ($et > $st) $intervals[] = [$st, $et];
            }
        }

        // 2. Appliquer les exceptions
        $exceptions = $wpdb->get_results($wpdb->prepare("
            SELECT type, start_time, end_time FROM {$tExc}
            WHERE employee_id=%d AND date=%s
        ", $empId, $date), ARRAY_A);

        foreach ($exceptions as $ex) {
            $type = $ex['type'];
            // Si null => toute la journée
            $exStart = $ex['start_time'] ? $this->time_to_ts($dayStart, $ex['start_time']) : $dayStart->getTimestamp();
            $exEnd   = $ex['end_time']   ? $this->time_to_ts($dayStart, $ex['end_time'])   : $dayEnd->getTimestamp(); // ou +1 sec

            // Important: Exception Busy/Closed = Soustraction. Exception Open = Addition ?
            // Brief : "exceptions (congés / ouvertures spéciales)"
            // Logique standard :
            // - Closed/Busy : on retire de la dispo.
            // - Open : on AJOUTE de la dispo (ex: ouverture exceptionnelle un dimanche).
            
            if ($type === 'closed' || $type === 'busy') {
                $intervals = $this->subtract_intervals($intervals, [[$exStart, $exEnd]]);
            } elseif ($type === 'open') {
                $intervals = $this->add_intervals($intervals, [[$exStart, $exEnd]]);
            }
        }

        return $intervals;
    }

    private function get_resource_allocations(int $empId, \DateTimeImmutable $dayStart, \DateTimeImmutable $dayEnd): array
    {
        global $wpdb;
        $tAppts = $wpdb->prefix . 'pup_appointments';
        $tz = $dayStart->getTimezone();
        $now = current_time('mysql');

        // On cherche les RDV confirmés OU les holds qui n'ont pas encore expiré
        $allocs = $wpdb->get_results($wpdb->prepare("
            SELECT start_dt, end_dt
            FROM {$tAppts}
            WHERE employee_id = %d
              AND (
                status = 'confirmed' 
                OR (status = 'hold' AND hold_expires_dt > %s)
              )
              AND start_dt < %s
              AND end_dt > %s
        ", $empId, $now, $dayEnd->format('Y-m-d H:i:s'), $dayStart->format('Y-m-d H:i:s')), ARRAY_A);

        $busy = [];
        if ($allocs) {
            foreach ($allocs as $a) {
                $st = (new \DateTimeImmutable($a['start_dt'], $tz))->getTimestamp();
                $en = (new \DateTimeImmutable($a['end_dt'], $tz))->getTimestamp();
                if ($en > $st) $busy[] = [$st, $en];
            }
        }
        return $busy;
    }

    // -------------------------------------------------------------------------
    // HELPERS : Mathématiques d'Intervalles (Timestamps)
    // -------------------------------------------------------------------------

    /**
     * Soustrait les plages $minus de $source.
     * Ex: Source [10-12], Minus [11-13] -> Result [10-11]
     */
    private function subtract_intervals(array $source, array $minus): array
    {
        // On traite chaque intervalle à soustraire un par un
        foreach ($minus as [$mStart, $mEnd]) {
            $newSource = [];
            foreach ($source as [$sStart, $sEnd]) {
                // Pas de chevauchement
                if ($mEnd <= $sStart || $mStart >= $sEnd) {
                    $newSource[] = [$sStart, $sEnd];
                    continue;
                }

                // Chevauchement : on découpe
                // Partie avant ?
                if ($sStart < $mStart) {
                    $newSource[] = [$sStart, $mStart];
                }
                // Partie après ?
                if ($sEnd > $mEnd) {
                    $newSource[] = [$mEnd, $sEnd];
                }
            }
            $source = $newSource;
            if (empty($source)) break;
        }
        return $source;
    }

    /**
     * Intersection de deux sets d'intervalles (AND logique).
     * On garde uniquement ce qui existe dans A ET dans B.
     */
    private function intersect_intervals(array $setA, array $setB): array
    {
        $result = [];
        $i = 0; $j = 0;
        $nA = count($setA); $nB = count($setB);

        // Tri nécessaire pour algorithme de balayage efficace
        usort($setA, fn($a,$b) => $a[0] <=> $b[0]);
        usort($setB, fn($a,$b) => $a[0] <=> $b[0]);

        while ($i < $nA && $j < $nB) {
            $a = $setA[$i];
            $b = $setB[$j];

            // Max des débuts, Min des fins
            $start = max($a[0], $b[0]);
            $end   = min($a[1], $b[1]);

            if ($start < $end) {
                $result[] = [$start, $end];
            }

            // Avancer celui qui finit le plus tôt
            if ($a[1] < $b[1]) {
                $i++;
            } else {
                $j++;
            }
        }
        return $result;
    }

    /**
     * Ajoute (Union) des intervalles. (Pour exceptions type 'open').
     * Simplifié : ajoute et merge les overlaps.
     */
    private function add_intervals(array $source, array $add): array
    {
        $all = array_merge($source, $add);
        if (empty($all)) return [];

        usort($all, fn($a,$b) => $a[0] <=> $b[0]);

        $merged = [];
        $current = $all[0];

        for ($i = 1; $i < count($all); $i++) {
            $next = $all[$i];
            if ($next[0] <= $current[1]) {
                // Overlap or touch -> extend current end if needed
                $current[1] = max($current[1], $next[1]);
            } else {
                // Gap -> push current, start new
                $merged[] = $current;
                $current = $next;
            }
        }
        $merged[] = $current;
        return $merged;
    }

    // -------------------------------------------------------------------------
    // UTILS
    // -------------------------------------------------------------------------

    private function time_to_ts(\DateTimeImmutable $baseDate, string $timeStr): int
    {
        // $timeStr format HH:MM:SS or HH:MM
        $parts = explode(':', $timeStr);
        $h = (int)($parts[0] ?? 0);
        $m = (int)($parts[1] ?? 0);
        return $baseDate->setTime($h, $m, 0)->getTimestamp();
    }

    private function round_up_to_step(\DateTimeImmutable $dt, int $stepMin): \DateTimeImmutable
    {
        $ts = $dt->getTimestamp();
        $minutes = (int) date('i', $ts);
        $seconds = (int) date('s', $ts);
        
        // Si pile sur le step (ex: 00, 15, 30) et 00 secondes, on ne touche pas
        $mod = ($minutes % $stepMin);
        if ($mod === 0 && $seconds === 0) return $dt;

        // Sinon on avance au prochain step
        $next = $dt->modify("+" . ($stepMin - $mod) . " minutes");
        // Reset secondes à 00
        return $next->setTime((int)$next->format('H'), (int)$next->format('i'), 0);
    }
}
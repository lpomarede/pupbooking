<?php
namespace PUP\Booking\Rest\Controllers;

use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

final class AdminEmployeePlanningController
{
    public function register_routes(): void
    {
        // GET planning (schedules + exceptions)
        register_rest_route('pup/v1', '/admin/employees/(?P<id>\d+)/planning', [
            'methods'  => 'GET',
            'callback' => [$this, 'get_planning'],
            'permission_callback' => [$this, 'can_manage'],
        ]);

        // PUT planning (replace schedules + upsert exceptions list)
        register_rest_route('pup/v1', '/admin/employees/(?P<id>\d+)/planning', [
            'methods'  => 'PUT',
            'callback' => [$this, 'save_planning'],
            'permission_callback' => [$this, 'can_manage'],
        ]);
    }

    public function can_manage(): bool
    {
        return current_user_can('manage_options');
    }

    public function get_planning(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $employeeId = (int)$req['id'];

        $tS = $wpdb->prefix . 'pup_employee_schedules';
        $tE = $wpdb->prefix . 'pup_employee_exceptions';

        $schedules = $wpdb->get_results($wpdb->prepare("
            SELECT id, weekday, start_time, end_time
            FROM {$tS}
            WHERE employee_id=%d
            ORDER BY weekday ASC, start_time ASC
        ", $employeeId), ARRAY_A) ?: [];

        $exceptions = $wpdb->get_results($wpdb->prepare("
            SELECT id, date, start_time, end_time, type, note
            FROM {$tE}
            WHERE employee_id=%d
            ORDER BY date DESC, start_time ASC
            LIMIT 200
        ", $employeeId), ARRAY_A) ?: [];

        return new WP_REST_Response([
            'ok' => true,
            'schedules' => $schedules,
            'exceptions' => $exceptions,
        ], 200);
    }

    public function save_planning(WP_REST_Request $req): WP_REST_Response
    {
        global $wpdb;
        $employeeId = (int)$req['id'];

        $payloadSchedules = $req->get_param('schedules');
        $payloadExceptions = $req->get_param('exceptions');

        if (!is_array($payloadSchedules)) $payloadSchedules = [];
        if (!is_array($payloadExceptions)) $payloadExceptions = [];

        $tS = $wpdb->prefix . 'pup_employee_schedules';
        $tE = $wpdb->prefix . 'pup_employee_exceptions';

        $wpdb->query('START TRANSACTION');

        try {
            // 1) replace schedules
            $wpdb->delete($tS, ['employee_id' => $employeeId], ['%d']);

            foreach ($payloadSchedules as $row) {
                $weekday = (int)($row['weekday'] ?? 0);
                $start = sanitize_text_field((string)($row['start_time'] ?? ''));
                $end   = sanitize_text_field((string)($row['end_time'] ?? ''));

                if ($weekday < 1 || $weekday > 7) continue;
                if (!$start || !$end) continue;

                $wpdb->insert($tS, [
                    'employee_id' => $employeeId,
                    'weekday' => $weekday,
                    'start_time' => $start,
                    'end_time' => $end,
                ], ['%d','%d','%s','%s']);
            }

            // 2) upsert exceptions: simple strategy = replace all for employee (MVP)
            $wpdb->delete($tE, ['employee_id' => $employeeId], ['%d']);

            foreach ($payloadExceptions as $ex) {
                $date = sanitize_text_field((string)($ex['date'] ?? ''));
                $type = sanitize_key((string)($ex['type'] ?? 'closed'));
                if (!in_array($type, ['closed','open','busy'], true)) $type = 'closed';

                $start = isset($ex['start_time']) ? sanitize_text_field((string)$ex['start_time']) : null;
                $end   = isset($ex['end_time']) ? sanitize_text_field((string)$ex['end_time']) : null;
                $note  = isset($ex['note']) ? sanitize_text_field((string)$ex['note']) : null;

                if (!$date) continue;

                $wpdb->insert($tE, [
                    'employee_id' => $employeeId,
                    'date' => $date,
                    'start_time' => $start ?: null,
                    'end_time' => $end ?: null,
                    'type' => $type,
                    'note' => $note,
                ], ['%d','%s','%s','%s','%s','%s']);
            }

            $wpdb->query('COMMIT');
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_REST_Response(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return $this->get_planning($req);
    }
}

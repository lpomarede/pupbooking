<?php
namespace PUP\Booking\Infrastructure;

if (!defined('ABSPATH')) exit;

final class Cron
{
    public function register(): void
    {
        add_filter('cron_schedules', function (array $schedules): array {
            $schedules['pup_every_minute'] = [
                'interval' => 60,
                'display'  => 'Every Minute',
            ];
            return $schedules;
        });

        add_action('pup_cleanup_holds', [$this, 'cleanup_holds']);
    }

    public function cleanup_holds(): void
    {
        global $wpdb;

        $tAppt  = $wpdb->prefix . 'pup_appointments';
        $tAlloc = $wpdb->prefix . 'pup_appointment_allocations';

        $now = current_time('mysql');

        $expiredIds = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id
                 FROM {$tAppt}
                 WHERE status = %s
                   AND hold_expires_dt IS NOT NULL
                   AND hold_expires_dt < %s
                 LIMIT 200",
                'hold',
                $now
            )
        );

        if (empty($expiredIds)) return;

        $expiredIds = array_values(array_map('intval', $expiredIds));
        $placeholders = implode(',', array_fill(0, count($expiredIds), '%d'));

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$tAlloc} WHERE appointment_id IN ({$placeholders})",
                ...$expiredIds
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$tAppt}
                 SET status = %s,
                     updated_at = %s,
                     cancelled_at = %s,
                     cancel_reason = %s,
                     hold_token_hash = NULL,
                     hold_expires_dt = NULL
                 WHERE id IN ({$placeholders})",
                'cancelled',
                $now,
                $now,
                'hold_expired',
                ...$expiredIds
            )
        );
    }
}
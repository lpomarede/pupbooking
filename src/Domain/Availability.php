<?php
namespace PUPBooking\Domain;

class Availability
{
    /**
     * Retourne true si AU MOINS 1 ressource a un overlap sur [start,end[
     * en tenant compte de:
     * - confirmed : toujours bloquant
     * - hold : bloquant seulement si hold_expires_dt > NOW()
     */
    public static function hasOverlap(\wpdb $wpdb, string $prefix, array $resourceIds, string $startDt, string $endDt, ?int $excludeAppointmentId = null): bool
    {
        if (empty($resourceIds)) return false;

        $alloc = "{$prefix}pup_appointment_allocations";
        $appts = "{$prefix}pup_appointments";

        $placeholders = implode(',', array_fill(0, count($resourceIds), '%d'));

        // Overlap logique: existing.start < newEnd AND existing.end > newStart
        $sql = "
            SELECT 1
            FROM {$alloc} a
            INNER JOIN {$appts} p ON p.id = a.appointment_id
            WHERE a.employee_id IN ($placeholders)
              AND a.start_dt < %s
              AND a.end_dt   > %s
              AND (
                   p.status = 'confirmed'
                   OR (p.status = 'hold' AND p.hold_expires_dt IS NOT NULL AND p.hold_expires_dt > NOW())
              )
        ";

        $params = array_merge($resourceIds, [$endDt, $startDt]);

        if ($excludeAppointmentId) {
            $sql .= " AND p.id <> %d";
            $params[] = $excludeAppointmentId;
        }

        $sql .= " LIMIT 1";

        $prepared = $wpdb->prepare($sql, $params);
        $found = $wpdb->get_var($prepared);

        return !empty($found);
    }
}

<?php
namespace PUP\Booking\Infrastructure;

if (!defined('ABSPATH')) exit;

final class Activator
{
    private const OPTION_DB_VERSION = 'pup_booking_db_version';

    /**
     * Callback du register_activation_hook()
     */
    public static function activate(): void
    {
        // s'assure que la fréquence existe au moment du schedule
        add_filter('cron_schedules', function($schedules){
            if (!isset($schedules['pup_every_minute'])) {
                $schedules['pup_every_minute'] = [
                    'interval' => 60,
                    'display'  => 'Every Minute'
                ];
            }
            return $schedules;
        });

        self::maybe_install_or_upgrade();
    }

    /**
     * Appelé en admin_init (comme tu le fais), et/ou en activation hook.
     * Recrée les tables si elles manquent, ou si version DB change.
     */
    public static function maybe_install_or_upgrade(): void
    {
        // évite de lancer 10 fois par requête
        static $done = false;
        if ($done) return;
        $done = true;

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $prefix = $wpdb->prefix;
        $charsetCollate = $wpdb->get_charset_collate();

        $current = (string) get_option(self::OPTION_DB_VERSION, '');
        $target  = (string) Schema::DB_VERSION;

        // Si une table manque, on force dbDelta même si version déjà à jour
        $missing = self::is_any_table_missing($prefix);

        if (!$missing && $current === $target) {
            return; // tout est OK
        }

        foreach (Schema::tables_sql($prefix, $charsetCollate) as $sql) {
            dbDelta($sql);
        }

        // cron cleanup holds (toutes les minutes)
        if (!wp_next_scheduled('pup_cleanup_holds')) {
            wp_schedule_event(time() + 60, 'pup_every_minute', 'pup_cleanup_holds');
        }

        update_option(self::OPTION_DB_VERSION, $target, true);
    }

    private static function is_any_table_missing(string $prefix): bool
    {
        global $wpdb;

        // On extrait les noms de tables à partir des CREATE TABLE du Schema
        $sqlList = Schema::tables_sql($prefix, $wpdb->get_charset_collate());

        $tables = [];
        foreach ($sqlList as $sql) {
            if (preg_match('/CREATE TABLE\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
                $tables[] = $m[1];
            }
        }

        // Fallback : si pour une raison quelconque on n'a rien parsé, on force l'upgrade
        if (!$tables) return true;

        foreach ($tables as $table) {
            $found = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            if ($found !== $table) return true;
        }

        return false;
    }
}

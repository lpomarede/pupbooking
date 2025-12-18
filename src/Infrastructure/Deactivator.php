<?php
namespace PUP\Booking\Infrastructure;

if (!defined('ABSPATH')) exit;

final class Deactivator
{
    public static function deactivate(): void
    {
        $ts = wp_next_scheduled('pup_cleanup_holds');
        if ($ts) wp_unschedule_event($ts, 'pup_cleanup_holds');

    }
}

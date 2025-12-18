<?php
/**
 * Plugin Name: PUP Booking (Custom)
 * Description: Module de réservation full custom pour Prenez Une Pause.
 * Version: 0.1.0
 * Author: Laurent POMAREDE
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) exit;

define('PUP_BOOKING_VERSION', '0.1.5');
define('PUP_BOOKING_PATH', plugin_dir_path(__FILE__));
define('PUP_BOOKING_URL', plugin_dir_url(__FILE__));

/**
 * Autoloader minimal (DOIT être dispo avant register_activation_hook)
 */
spl_autoload_register(function ($class) {
    if (strpos($class, 'PUP\\Booking\\') !== 0) return;

    $relative = str_replace('PUP\\Booking\\', '', $class);
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
    $file = PUP_BOOKING_PATH . 'src/' . $relative . '.php';

    if (file_exists($file)) require_once $file;
});

register_activation_hook(__FILE__, ['PUP\\Booking\\Infrastructure\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['PUP\\Booking\\Infrastructure\\Deactivator', 'deactivate']);

add_action('plugins_loaded', function () {
    PUP\Booking\Plugin::instance()->boot();
});

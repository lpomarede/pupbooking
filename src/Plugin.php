<?php
namespace PUP\Booking;

use PUP\Booking\Infrastructure\Assets;
use PUP\Booking\Rest\Rest;
use PUP\Booking\Admin\Admin;
use PUP\Booking\Front\Shortcodes;
use PUP\Booking\Infrastructure\Cron;

if (!defined('ABSPATH')) exit;

final class Plugin
{
    private static ?Plugin $instance = null;

    public static function instance(): Plugin
    {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct()
    {
        $this->autoload();
    }

    public function boot(): void
    {
        // migrations DB (run en admin pour éviter de le faire à chaque page front)
        if (is_admin()) {
            add_action('admin_init', function () {
                \PUP\Booking\Infrastructure\Activator::maybe_install_or_upgrade();
            });
        }

        (new \PUP\Booking\Infrastructure\Assets())->register();
        (new \PUP\Booking\Rest\Rest())->register();
        (new \PUP\Booking\Front\Shortcodes())->register();
        (new \PUP\Booking\Front\ManageShortcode())->register();
        (new \PUP\Booking\Infrastructure\Cron())->register();

        if (is_admin()) {
            (new \PUP\Booking\Admin\Admin())->register();
        }
    }

    private function autoload(): void
    {
        spl_autoload_register(function ($class) {
            if (strpos($class, 'PUP\\Booking\\') !== 0) return;

            $relative = str_replace('PUP\\Booking\\', '', $class);
            $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
            $file = PUP_BOOKING_PATH . 'src/' . $relative . '.php';

            if (file_exists($file)) require_once $file;
        });
    }
}

<?php
namespace PUP\Booking\Infrastructure;

if (!defined('ABSPATH')) exit;

final class Assets
{
    public function register(): void
    {
        //add_action('wp_enqueue_scripts', [$this, 'enqueue_front']);
    }

    public function enqueue_front(): void
    {
        return;
    }
}

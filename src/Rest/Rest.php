<?php
namespace PUP\Booking\Rest;

use PUP\Booking\Rest\Controllers\ServicesController;
use PUP\Booking\Rest\Controllers\SlotsController;
use PUP\Booking\Rest\Controllers\AppointmentsController;
use PUP\Booking\Rest\Controllers\AdminServicesController;
use PUP\Booking\Rest\Controllers\AdminEmployeesController;
use PUP\Booking\Rest\Controllers\AdminEmployeePlanningController;
use PUP\Booking\Rest\Controllers\PublicAppointmentsController;
use PUP\Booking\Rest\Controllers\PublicServicesController;
use PUP\Booking\Rest\Controllers\PublicManageAppointmentController;
use PUP\Booking\Rest\Controllers\PublicHoldController;
use PUP\Booking\Rest\Controllers\AdminCategoriesController;
use PUP\Booking\Rest\Controllers\AdminCategoryVisibilityController;
use PUP\Booking\Rest\Controllers\AdminOptionsController;
use PUP\Booking\Rest\Controllers\AdminServiceOptionsController;
use PUP\Booking\Rest\Controllers\PublicCatalogController;
use PUP\Booking\Rest\Controllers\PublicServiceOptionsController;
use PUP\Booking\Rest\Controllers\AdminServicePricesController;
use PUP\Booking\Rest\Controllers\AdminServiceEmployeesController;

if (!defined('ABSPATH')) exit;

final class Rest
{
    public function register(): void
    {
        add_action('rest_api_init', function () {
            (new ServicesController())->register_routes();
            (new SlotsController())->register_routes();
            (new AppointmentsController())->register_routes();
            (new AdminServicesController())->register_routes();
            (new AdminEmployeesController())->register_routes();
            (new AdminEmployeePlanningController())->register_routes();
            (new PublicAppointmentsController())->register_routes();
            (new PublicServicesController())->register_routes();
            (new Controllers\PublicManageAppointmentController())->register_routes();
            (new Controllers\PublicHoldController())->register_routes();
            (new Controllers\AdminCategoriesController())->register_routes();
            (new Controllers\AdminCategoryVisibilityController())->register_routes();
            (new \PUP\Booking\Rest\Controllers\AdminOptionsController())->register_routes();
            (new \PUP\Booking\Rest\Controllers\AdminServiceOptionsController())->register_routes();
            (new Controllers\PublicCatalogController())->register_routes();
            (new Controllers\PublicServiceOptionsController())->register_routes();
            (new Controllers\AdminServicePricesController())->register_routes();
            (new Controllers\AdminServiceEmployeesController())->register_routes();
        });
    }
}

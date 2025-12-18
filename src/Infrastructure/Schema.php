<?php
namespace PUP\Booking\Infrastructure;

if (!defined('ABSPATH')) exit;

final class Schema
{
    public const DB_VERSION = '1.0.7';

    /**
     * Retourne un tableau de CREATE TABLE (1 par table) pour dbDelta().
     * IMPORTANT: dbDelta aime les statements séparés.
     */
    public static function tables_sql(string $prefix, string $charsetCollate): array
    {
        return [

"CREATE TABLE {$prefix}pup_customer_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  rules_json JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_category_name (name),
  KEY idx_category_active (is_active)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_customers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  wp_user_id BIGINT UNSIGNED NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NULL,
  first_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NULL,
  address VARCHAR(190) NULL,
  postal_code VARCHAR(20) NULL,
  city VARCHAR(120) NULL,
  category_id BIGINT UNSIGNED NULL,
  marketing_optin TINYINT(1) NOT NULL DEFAULT 0,
  birthday DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_customer_email (email),
  KEY idx_customer_wp_user (wp_user_id),
  KEY idx_customer_category (category_id),
  KEY idx_customer_birthday (birthday)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_employees (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  wp_user_id BIGINT UNSIGNED NULL,
  display_name VARCHAR(190) NOT NULL,
  email VARCHAR(190) NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'human',
  capacity INT NOT NULL DEFAULT 1,
  timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Paris',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  google_sync_enabled TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_employee_wp_user (wp_user_id),
  KEY idx_employee_active (is_active),
  KEY idx_employee_email (email)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_resources (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type ENUM('room','equipment') NOT NULL DEFAULT 'room',
  name VARCHAR(190) NOT NULL,
  capacity INT UNSIGNED NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_resource_name_type (type, name),
  KEY idx_resource_active (is_active),
  KEY idx_resource_type (type)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_services (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  description LONGTEXT NULL,
  category_id BIGINT UNSIGNED NULL,
  booking_mode ENUM('slot','product') NOT NULL DEFAULT 'slot',
  duration_min INT UNSIGNED NOT NULL DEFAULT 60,
  buffer_before_min INT UNSIGNED NOT NULL DEFAULT 0,
  buffer_after_min INT UNSIGNED NOT NULL DEFAULT 0,
  type ENUM('individual','multi','capacity') NOT NULL DEFAULT 'individual',
  capacity_max INT UNSIGNED NULL,
  min_notice_min INT UNSIGNED NOT NULL DEFAULT 0,
  cancel_limit_min INT UNSIGNED NOT NULL DEFAULT 1440,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_service_active (is_active),
  KEY idx_service_type (type),
  KEY idx_service_name (name),
  KEY idx_service_category (category_id),
  KEY idx_service_booking_mode (booking_mode)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_options (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  description TEXT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  duration_add_min INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_active (is_active)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_service_options (
  service_id BIGINT UNSIGNED NOT NULL,
  option_id BIGINT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  price_override DECIMAL(10,2) NULL,
  duration_override_min INT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (service_id, option_id),
  KEY idx_service (service_id),
  KEY idx_option (option_id)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_appointment_options (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  appointment_id BIGINT UNSIGNED NOT NULL,
  option_id BIGINT UNSIGNED NOT NULL,
  qty INT UNSIGNED NOT NULL DEFAULT 1,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  duration_add_min INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_appt_option (appointment_id, option_id),
  KEY idx_ao_appt (appointment_id),
  KEY idx_ao_option (option_id)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_service_prices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_service_category (service_id, category_id),
  KEY idx_price_service (service_id),
  KEY idx_price_category (category_id)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_service_employees (
  service_id BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NOT NULL,
  is_allowed TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (service_id, employee_id),
  KEY idx_se_employee (employee_id),
  KEY idx_se_service (service_id)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_service_requirements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id BIGINT UNSIGNED NOT NULL,
  requirement_type ENUM('employee_count','resource') NOT NULL,
  resource_id BIGINT UNSIGNED NULL,
  qty INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY idx_req_service (service_id),
  KEY idx_req_type (requirement_type),
  KEY idx_req_resource (resource_id)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_service_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(100) NOT NULL,
  description TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cat_slug (slug),
  KEY idx_cat_active (is_active),
  KEY idx_cat_sort (sort_order)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_category_visibility (
  category_id BIGINT UNSIGNED NOT NULL,
  customer_category_id BIGINT UNSIGNED NOT NULL,
  is_visible TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (category_id, customer_category_id),
  KEY idx_vis_cat (category_id),
  KEY idx_vis_custcat (customer_category_id)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_employee_schedules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id BIGINT UNSIGNED NOT NULL,
  weekday TINYINT UNSIGNED NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_emp_weekday_span (employee_id, weekday, start_time, end_time),
  KEY idx_sched_employee (employee_id),
  KEY idx_sched_weekday (weekday)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_employee_exceptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id BIGINT UNSIGNED NOT NULL,
  date DATE NOT NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  type ENUM('closed','open','busy') NOT NULL DEFAULT 'closed',
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_exc_employee_date (employee_id, date),
  KEY idx_exc_type (type)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_appointments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  service_id BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NULL,
  customer_id BIGINT(20) UNSIGNED NOT NULL,
  start_dt DATETIME NOT NULL,
  end_dt DATETIME NOT NULL,
  duration_total_min INT NOT NULL,
  qty INT NOT NULL DEFAULT 1,
  price_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  status VARCHAR(30) NOT NULL DEFAULT 'pending',
  hold_token_hash VARCHAR(64) NULL,
  hold_expires_dt DATETIME NULL,
  manage_token_hash VARCHAR(64) NULL,
  manage_token_expires DATETIME NULL,
  cancel_reason VARCHAR(255) NULL,
  notes_customer TEXT NULL,
  notes_internal TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_customer_id (customer_id),
  KEY idx_start (start_dt),
  KEY idx_status (status),
  KEY idx_hold (hold_token_hash),
  KEY idx_hold_exp (status, hold_expires_dt),
  KEY idx_manage (manage_token_hash)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_appointment_allocations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  appointment_id BIGINT UNSIGNED NOT NULL,
  employee_id BIGINT UNSIGNED NULL,
  resource_id BIGINT UNSIGNED NULL,
  start_dt DATETIME NOT NULL,
  end_dt DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_alloc_appt (appointment_id),
  KEY idx_alloc_employee (employee_id, start_dt, end_dt),
  KEY idx_alloc_resource (resource_id, start_dt, end_dt),
  KEY idx_alloc_span (start_dt, end_dt)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_appointment_attendees (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  appointment_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  email VARCHAR(190) NULL,
  qty INT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_att_appt (appointment_id),
  KEY idx_att_customer (customer_id),
  KEY idx_att_email (email)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  appointment_id BIGINT UNSIGNED NOT NULL,
  method ENUM('stripe','giftcard','cash','card_on_site') NOT NULL,
  amount_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  status ENUM('unpaid','paid','partial','refunded','failed') NOT NULL DEFAULT 'unpaid',
  stripe_pi_id VARCHAR(190) NULL,
  stripe_charge_id VARCHAR(190) NULL,
  meta_json JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payment_stripe_pi (stripe_pi_id),
  KEY idx_pay_appt (appointment_id),
  KEY idx_pay_status (status),
  KEY idx_pay_method (method)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_giftcards (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) NOT NULL,
  initial_amount DECIMAL(10,2) NOT NULL,
  balance DECIMAL(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  expires_at DATETIME NULL,
  status ENUM('active','expired','blocked') NOT NULL DEFAULT 'active',
  purchaser_customer_id BIGINT UNSIGNED NULL,
  recipient_email VARCHAR(190) NULL,
  message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gc_code (code),
  KEY idx_gc_status (status),
  KEY idx_gc_expires (expires_at),
  KEY idx_gc_recipient (recipient_email)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_giftcard_tx (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  giftcard_id BIGINT UNSIGNED NOT NULL,
  appointment_id BIGINT UNSIGNED NULL,
  delta_amount DECIMAL(10,2) NOT NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gctx_gc (giftcard_id),
  KEY idx_gctx_appt (appointment_id),
  KEY idx_gctx_created (created_at)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_email_templates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(100) NOT NULL,
  subject VARCHAR(190) NOT NULL,
  html LONGTEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email_slug (slug),
  KEY idx_email_active (is_active)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_email_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  to_email VARCHAR(190) NOT NULL,
  template_slug VARCHAR(100) NULL,
  appointment_id BIGINT UNSIGNED NULL,
  context_json JSON NULL,
  status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
  error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_email_to (to_email),
  KEY idx_email_tpl (template_slug),
  KEY idx_email_appt (appointment_id),
  KEY idx_email_status (status),
  KEY idx_email_created (created_at)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_type VARCHAR(50) NOT NULL,
  payload_json JSON NOT NULL,
  run_at DATETIME NOT NULL,
  status ENUM('queued','running','done','failed') NOT NULL DEFAULT 'queued',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_jobs_run (status, run_at),
  KEY idx_jobs_type (job_type),
  KEY idx_jobs_created (created_at)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_google_accounts (
  employee_id BIGINT UNSIGNED NOT NULL,
  calendar_id VARCHAR(190) NULL,
  access_token_enc TEXT NULL,
  refresh_token_enc TEXT NULL,
  token_expires_at DATETIME NULL,
  scope VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (employee_id),
  KEY idx_google_cal (calendar_id)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_google_events (
  appointment_id BIGINT UNSIGNED NOT NULL,
  google_event_id VARCHAR(190) NOT NULL,
  employee_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL,
  PRIMARY KEY (appointment_id),
  UNIQUE KEY uq_google_event (google_event_id),
  KEY idx_google_event_emp (employee_id)
) {$charsetCollate};",

"CREATE TABLE {$prefix}pup_settings (
  setting_key VARCHAR(100) NOT NULL,
  setting_value LONGTEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) {$charsetCollate};",

        ];
    }
}

<?php
/**
 * Plugin Name: TicketJunkies – Event Ticketing for Elementor
 * Description: Complete event ticketing (events, ticket types, checkout, QR tickets, organizer & attendee dashboards, reports, admin panel) with drag-and-drop Elementor widgets.
 * Version: 2.0.8
 * Author: Dj Payne Events
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: ticketjunkies
 * Plugin URI: https://github.com/djpayne242/ticket-junkies-2.0
 * Update URI: https://github.com/djpayne242/ticket-junkies-2.0
 */
if (!defined('ABSPATH')) exit;

define('TJ_VERSION', '2.0.8');
define('TJ_FILE', __FILE__);
define('TJ_DIR', plugin_dir_path(__FILE__));
define('TJ_URL', plugin_dir_url(__FILE__));

foreach (['class-tj-qr', 'class-tj-export', 'class-tj-store', 'class-tj-notify', 'class-tj-geo', 'class-tj-directory', 'class-tj-rest', 'class-tj-import', 'class-tj-frontend', 'class-tj-elementor', 'class-tj-admin', 'class-tj-updater', 'class-tj-woo'] as $f) {
  $p = TJ_DIR . 'includes/' . $f . '.php';
  if (file_exists($p)) require_once $p;
}

register_activation_hook(__FILE__, function () {
  if (class_exists('TJ_Store')) TJ_Store::install();
  if (class_exists('TJ_Frontend')) TJ_Frontend::install();
  flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function () { flush_rewrite_rules(); });

add_action('plugins_loaded', function () {
  if (class_exists('TJ_Store')) TJ_Store::maybe_upgrade();
  if (class_exists('TJ_Rest')) TJ_Rest::init();
  if (class_exists('TJ_Import')) TJ_Import::init();
  if (class_exists('TJ_Frontend')) TJ_Frontend::init();
  if (class_exists('TJ_Elementor')) TJ_Elementor::init();
  if (class_exists('TJ_Admin')) TJ_Admin::init();
  if (class_exists('TJ_Updater')) TJ_Updater::init();
  if (class_exists('TJ_Woo')) TJ_Woo::init();
  if (class_exists('TJ_Store')) add_action('init', ['TJ_Store', 'restore_bundled_events'], 20);
  if (class_exists('TJ_Store')) add_action('init', ['TJ_Store', 'apply_bundled_flyers'], 21);
});

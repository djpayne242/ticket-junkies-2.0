<?php if (!defined('ABSPATH')) exit;

/**
 * Elementor integration: a "TicketJunkies" widget category with drag-and-drop widgets.
 */
final class TJ_Elementor {
  public static function init() {
    add_action('elementor/elements/categories_registered', function ($m) {
      $m->add_category('ticketjunkies', ['title' => '🎟️ TicketJunkies', 'icon' => 'eicon-ticket']);
    });
    add_action('elementor/widgets/register', [__CLASS__, 'register']);
    add_action('elementor/editor/after_enqueue_styles', function () {
      wp_add_inline_style('elementor-editor', '.elementor-element .icon .eicon-tj{font-size:28px}');
    });
  }

  public static function register($manager) {
    if (!class_exists('\Elementor\Widget_Base')) return;
    require_once TJ_DIR . 'includes/elementor-widgets.php';
    foreach (tj_elementor_widget_classes() as $cls) $manager->register(new $cls());
  }
}

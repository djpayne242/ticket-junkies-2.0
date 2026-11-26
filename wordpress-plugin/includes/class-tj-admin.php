<?php if (!defined('ABSPATH')) exit;

/**
 * WordPress dashboard screen: TicketJunkies → Start here.
 */
final class TJ_Admin {
  public static function init() {
    add_action('admin_menu', [__CLASS__, 'menu']);
    add_action('admin_init', function () {
      if (!get_option('tj_pages') && current_user_can('manage_options') && class_exists('TJ_Frontend')) TJ_Frontend::install();
      // v1.3.3: sites set up earlier get the new Scanner page once (if it's deleted later, it isn't forced back)
      elseif (!get_option('tj_scanner_page_added') && current_user_can('manage_options') && class_exists('TJ_Frontend')) {
        update_option('tj_scanner_page_added', 1);
        $p = get_option('tj_pages', []);
        if (empty($p['scanner'])) TJ_Frontend::install(false, 'scanner');
      }
      // v1.3.5: the Scanner page is now called "Ticket Scanner" (only renamed if you haven't changed its title yourself)
      if (!get_option('tj_scanner_title_v2') && current_user_can('manage_options')) {
        update_option('tj_scanner_title_v2', 1);
        $p = get_option('tj_pages', []);
        if (!empty($p['scanner']) && get_the_title($p['scanner']) === 'Scanner') wp_update_post(['ID' => (int) $p['scanner'], 'post_title' => 'Ticket Scanner']);
      }
    });
    add_action('admin_post_tj_ui', [__CLASS__, 'save_ui']);
    add_action('admin_post_tj_pages', [__CLASS__, 'rebuild_pages']);
    add_action('admin_post_tj_import', [__CLASS__, 'import']);
    add_filter('plugin_action_links_' . plugin_basename(TJ_FILE), function ($l) { array_unshift($l, '<a href="' . esc_url(admin_url('admin.php?page=ticketjunkies')) . '">Start here</a>'); return $l; });
    add_filter('display_post_states', function ($states, $post) {
      $k = get_post_meta($post->ID, '_tj_page', true);
      if ($k) $states['tj'] = 'TicketJunkies – ' . ucfirst($k);
      return $states;
    }, 10, 2);
    if (defined('WP_CLI') && WP_CLI) {
      WP_CLI::add_command('tj pages', function ($args, $assoc) {
        TJ_Frontend::install(!empty($assoc['force']));
        WP_CLI::success('TicketJunkies pages ready: ' . wp_json_encode(get_option('tj_pages')));
      });
    }
  }

  public static function menu() {
    add_menu_page('TicketJunkies', 'TicketJunkies', 'manage_options', 'ticketjunkies', [__CLASS__, 'page'], 'dashicons-tickets-alt', 26);
    add_submenu_page('ticketjunkies', 'Start here', 'Start here', 'manage_options', 'ticketjunkies', [__CLASS__, 'page']);
    add_submenu_page('ticketjunkies', 'Admin Panel', 'Admin Panel ↗', 'manage_options', 'tj-open-admin', function () { echo '<script>location.href=' . wp_json_encode(tj_page_url('admin')) . '</script>'; });
  }

  public static function page() {
    $pages = get_option('tj_pages', []);
    $ui = TJ_Frontend::ui();
    $msg = isset($_GET['tj_msg']) ? sanitize_text_field(wp_unslash($_GET['tj_msg'])) : '';
    $labels = ['home' => 'Homepage', 'event' => 'Event page', 'checkout' => 'Checkout', 'tickets' => 'My Dashboard (attendees)', 'organizer' => 'Organizer Dashboard', 'admin' => 'Admin Panel', 'login' => 'Log In', 'register' => 'Create Account', 'ticket' => 'Ticket viewer'];
    echo '<div class="wrap"><h1>🎟️ TicketJunkies</h1>';
    if ($msg) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
    echo '<p style="font-size:14px;max-width:820px">Your ticketing site is built from normal WordPress pages that you can design with <strong>Elementor</strong>. Drag TicketJunkies widgets from the <em>🎟️ TicketJunkies</em> group in the Elementor panel, and change brand colors in Elementor → <em>Site Settings → Global Colors</em> (Primary = blue, Secondary = green, Accent = orange).</p>';
    echo '<h2>Your pages</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>Page</th><th>Address</th><th></th></tr></thead><tbody>';
    foreach ($labels as $k => $label) {
      $id = isset($pages[$k]) ? (int) $pages[$k] : 0;
      $ok = $id && get_post_status($id) === 'publish';
      echo '<tr><td><strong>' . esc_html($label) . '</strong></td><td>' . ($ok ? '<a href="' . esc_url(get_permalink($id)) . '" target="_blank">' . esc_html(get_permalink($id)) . '</a>' : '<em>missing</em>') . '</td><td>';
      if ($ok) {
        if (defined('ELEMENTOR_VERSION')) echo '<a class="button button-primary" href="' . esc_url(admin_url('post.php?post=' . $id . '&action=elementor')) . '">Edit with Elementor</a> ';
        echo '<a class="button" href="' . esc_url(get_permalink($id)) . '" target="_blank">View</a>';
      }
      echo '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px">';
    wp_nonce_field('tj_pages');
    echo '<input type="hidden" name="action" value="tj_pages" /><button class="button" name="mode" value="missing">Re-create missing pages</button> ';
    echo '<button class="button" name="mode" value="force" onclick="return confirm(\'Reset ALL TicketJunkies pages to the original design? Your Elementor changes on those pages will be replaced.\')">Reset page designs</button></form>';

    echo '<h2 style="margin-top:28px">Header & footer</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('tj_ui');
    echo '<input type="hidden" name="action" value="tj_ui" /><table class="form-table" role="presentation">';
    echo '<tr><th>TicketJunkies header</th><td><label><input type="checkbox" name="header" value="1" ' . checked($ui['header'], 1, false) . '> Show the TicketJunkies header (logo, menu with log in / dashboards, light/dark switch)</label><p class="description">Turn off if you build your own header with Elementor Pro\'s Theme Builder.</p></td></tr>';
    echo '<tr><th>TicketJunkies footer</th><td><label><input type="checkbox" name="footer" value="1" ' . checked($ui['footer'], 1, false) . '> Show the TicketJunkies footer</label></td></tr>';
    echo '<tr><th>Logo image URL</th><td><input type="url" class="regular-text" name="logo" value="' . esc_attr($ui['logo']) . '" placeholder="Leave empty to use the site logo / TicketJunkies logo" /><p class="description">Tip: upload your logo to Media → Library, copy its URL and paste it here. If empty, Appearance → Customize → Site Identity logo is used.</p></td></tr>';
    echo '<tr><th>Footer text</th><td><input type="text" class="regular-text" name="footer_text" value="' . esc_attr($ui['footer_text']) . '" placeholder="' . esc_attr(get_bloginfo('name')) . '. All rights reserved." /></td></tr>';
    echo '</table>';
    submit_button('Save header & footer');
    echo '</form>';

    echo '<h2 style="margin-top:28px">Bring over your existing TicketJunkies data</h2>';
    echo '<p style="max-width:820px">Upload the <code>ticketjunkies-import.json</code> file made from your current site. Events (with flyers), ticket types, orders, tickets & QR codes, accounts and settings are copied in. Imported customers sign in with "Lost your password?" the first time. Running it again skips anything already imported.</p>';
    echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('tj_import');
    echo '<input type="hidden" name="action" value="tj_import" /><input type="file" name="bundle" accept=".json,application/json" required /> ';
    submit_button('Import', 'secondary', 'submit', false);
    echo '</form>';
    echo '<h2 style="margin-top:28px">Shortcodes (without Elementor)</h2><p><code>[ticketjunkies widget="grid"]</code> · <code>[ticketjunkies widget="map"]</code> · <code>[ticketjunkies widget="search"]</code> · <code>[ticketjunkies widget="chips"]</code> · <code>[ticketjunkies widget="weather"]</code> · <code>[ticketjunkies screen="event|checkout|tickets|organizer|admin|login|register|ticket"]</code></p>';
    echo '</div>';
  }

  public static function save_ui() {
    if (!current_user_can('manage_options') || !check_admin_referer('tj_ui')) wp_die('Not allowed');
    update_option('tj_ui', [
      'header' => empty($_POST['header']) ? 0 : 1, 'footer' => empty($_POST['footer']) ? 0 : 1,
      'logo' => esc_url_raw(wp_unslash($_POST['logo'] ?? '')), 'footer_text' => sanitize_text_field(wp_unslash($_POST['footer_text'] ?? '')),
    ]);
    wp_safe_redirect(admin_url('admin.php?page=ticketjunkies&tj_msg=' . rawurlencode('Header & footer saved.')));
    exit;
  }

  public static function rebuild_pages() {
    if (!current_user_can('manage_options') || !check_admin_referer('tj_pages')) wp_die('Not allowed');
    $force = ($_POST['mode'] ?? '') === 'force';
    if ($force) { $kit = (int) get_option('elementor_active_kit'); if ($kit) { $s = get_post_meta($kit, '_elementor_page_settings', true); if (is_array($s)) { unset($s['tj_configured']); update_post_meta($kit, '_elementor_page_settings', $s); } } }
    TJ_Frontend::install($force);
    flush_rewrite_rules();
    wp_safe_redirect(admin_url('admin.php?page=ticketjunkies&tj_msg=' . rawurlencode($force ? 'All TicketJunkies pages were reset to the original design.' : 'Missing pages were re-created.')));
    exit;
  }

  public static function import() {
    if (!current_user_can('manage_options') || !check_admin_referer('tj_import')) wp_die('Not allowed');
    if (!class_exists('TJ_Import')) wp_die('Importer not available');
    if (empty($_FILES['bundle']['tmp_name']) || !is_uploaded_file($_FILES['bundle']['tmp_name'])) wp_die('Please choose the import file.');
    $bundle = json_decode(file_get_contents($_FILES['bundle']['tmp_name']), true); // phpcs:ignore
    if (!is_array($bundle)) wp_die('That file is not a TicketJunkies import file.');
    @set_time_limit(300);
    $summary = TJ_Import::run($bundle);
    $parts = [];
    foreach ((array) $summary as $k => $v) if (is_scalar($v)) $parts[] = "$k: $v";
    wp_safe_redirect(admin_url('admin.php?page=ticketjunkies&tj_msg=' . rawurlencode('Import finished. ' . implode(', ', $parts))));
    exit;
  }
}

<?php
if (!defined('ABSPATH')) exit;

/**
 * Keeps the plugin connected to GitHub (github.com/djpayne242/ticket-junkies-2.0).
 * When a newer release is published there, WordPress shows "Update available"
 * on the Plugins screen and can update in one click (or automatically).
 */
final class TJ_Updater {
  const REPO = 'djpayne242/ticket-junkies-2.0';
  const ASSET = 'ticketjunkies-wordpress-plugin.zip';
  const CACHE = 'tj_github_release';

  public static function init() {
    add_filter('pre_set_site_transient_update_plugins', [__CLASS__, 'inject']);
    add_filter('plugins_api', [__CLASS__, 'info'], 20, 3);
    add_filter('upgrader_source_selection', [__CLASS__, 'fix_folder'], 10, 4);
    add_action('upgrader_process_complete', function () { delete_site_transient(self::CACHE); }, 10, 0);
    add_filter('plugin_row_meta', [__CLASS__, 'row_links'], 10, 2);
    // "Check GitHub for updates now" link on the Plugins screen
    add_action('admin_init', function () {
      if (!isset($_GET['tj_check_updates']) || !current_user_can('update_plugins')) return;
      check_admin_referer('tj_check_updates');
      delete_site_transient(self::CACHE);
      delete_site_transient('update_plugins');
      wp_update_plugins();
      wp_safe_redirect(admin_url('plugins.php?tj_checked=1'));
      exit;
    });
    add_action('admin_notices', function () {
      if (empty($_GET['tj_checked'])) return;
      $r = self::release();
      $msg = $r && version_compare($r['version'], TJ_VERSION, '>')
        ? sprintf('TicketJunkies %s is available on GitHub. Click "update now" below.', esc_html($r['version']))
        : sprintf('TicketJunkies is up to date (version %s).', esc_html(TJ_VERSION));
      echo '<div class="notice notice-info is-dismissible"><p>' . $msg . '</p></div>';
    });
  }

  public static function basename() { return plugin_basename(TJ_FILE); }

  /** Latest GitHub release: ['version','zip','url','notes','published'] or null. Cached for 6 hours. */
  public static function release($force = false) {
    if (!$force) {
      $c = get_site_transient(self::CACHE);
      if (is_array($c)) return $c ? $c : null;
    }
    $res = wp_remote_get('https://api.github.com/repos/' . self::REPO . '/releases/latest', [
      'timeout' => 10,
      'headers' => ['Accept' => 'application/vnd.github+json', 'User-Agent' => 'TicketJunkies-WordPress/' . TJ_VERSION],
    ]);
    $out = [];
    if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) === 200) {
      $j = json_decode(wp_remote_retrieve_body($res), true);
      if (is_array($j) && !empty($j['tag_name'])) {
        $zip = '';
        foreach ((array) ($j['assets'] ?? []) as $a) if (($a['name'] ?? '') === self::ASSET) $zip = $a['browser_download_url'];
        if ($zip) $out = [
          'version' => ltrim((string) $j['tag_name'], 'vV'),
          'zip' => $zip,
          'url' => (string) ($j['html_url'] ?? ('https://github.com/' . self::REPO)),
          'notes' => (string) ($j['body'] ?? ''),
          'published' => (string) ($j['published_at'] ?? ''),
        ];
      }
    }
    // Cache misses for 1 hour so a GitHub hiccup never slows the dashboard
    set_site_transient(self::CACHE, $out, $out ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
    return $out ? $out : null;
  }

  public static function inject($transient) {
    if (!is_object($transient) || empty($transient->checked)) return $transient;
    $r = self::release();
    $base = self::basename();
    $item = (object) [
      'id' => 'github.com/' . self::REPO, 'slug' => 'ticketjunkies', 'plugin' => $base,
      'new_version' => $r ? $r['version'] : TJ_VERSION, 'url' => 'https://github.com/' . self::REPO,
      'package' => $r ? $r['zip'] : '', 'tested' => get_bloginfo('version'), 'requires_php' => '7.4',
      'icons' => ['1x' => TJ_URL . 'assets/img/apple-touch-icon.png'],
    ];
    if ($r && version_compare($r['version'], TJ_VERSION, '>')) $transient->response[$base] = $item;
    else { unset($transient->response[$base]); $transient->no_update[$base] = $item; }
    return $transient;
  }

  public static function info($result, $action, $args) {
    if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'ticketjunkies') return $result;
    $r = self::release();
    return (object) [
      'name' => 'TicketJunkies – Event Ticketing for Elementor', 'slug' => 'ticketjunkies',
      'version' => $r ? $r['version'] : TJ_VERSION, 'author' => 'Dj Payne Events',
      'homepage' => 'https://github.com/' . self::REPO, 'download_link' => $r ? $r['zip'] : '',
      'requires' => '6.0', 'requires_php' => '7.4', 'tested' => get_bloginfo('version'),
      'last_updated' => $r ? $r['published'] : '',
      'sections' => [
        'description' => 'Complete event ticketing for WordPress + Elementor. Source code: <a href="https://github.com/' . self::REPO . '" target="_blank">github.com/' . self::REPO . '</a>',
        'changelog' => $r && $r['notes'] ? wpautop(esc_html($r['notes'])) : 'See the releases page on GitHub.',
      ],
    ];
  }

  /** GitHub zips must unpack into wp-content/plugins/ticketjunkies (not a renamed folder). */
  public static function fix_folder($source, $remote_source, $upgrader, $extra = []) {
    if (empty($extra['plugin']) || $extra['plugin'] !== self::basename()) return $source;
    global $wp_filesystem;
    $want = trailingslashit($remote_source) . 'ticketjunkies/';
    if (untrailingslashit($source) === untrailingslashit($want)) return $source;
    if ($wp_filesystem && $wp_filesystem->move($source, $want, true)) return $want;
    return $source;
  }

  public static function row_links($links, $file) {
    if ($file !== self::basename()) return $links;
    $links[] = '<a href="https://github.com/' . self::REPO . '" target="_blank" rel="noopener">GitHub</a>';
    if (current_user_can('update_plugins')) $links[] = '<a href="' . esc_url(wp_nonce_url(admin_url('plugins.php?tj_check_updates=1'), 'tj_check_updates')) . '">Check GitHub for updates</a>';
    return $links;
  }
}

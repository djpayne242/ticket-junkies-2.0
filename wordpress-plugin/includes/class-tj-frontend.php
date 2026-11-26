<?php if (!defined('ABSPATH')) exit;

/**
 * Front end: assets, header/footer, page links and the pages created on activation.
 */

/** URL of one of the plugin's pages: home, event, checkout, tickets, organizer, admin, login, register, ticket */
function tj_page_url($key) {
  $pages = get_option('tj_pages', []);
  if ($key === 'home') {
    $front = (int) get_option('page_on_front');
    if (!empty($pages['home']) && get_post_status($pages['home']) === 'publish' && (int) $pages['home'] !== $front) return get_permalink($pages['home']);
    return home_url('/');
  }
  if (!empty($pages[$key]) && get_post_status($pages[$key]) === 'publish') return get_permalink($pages[$key]);
  return home_url('/');
}

/** True when the plugin page for $key exists and is published */
function tj_has_page($key) {
  $pages = get_option('tj_pages', []);
  return !empty($pages[$key]) && get_post_status($pages[$key]) === 'publish';
}

/** Map an app route ('#/event/evt_x', '#/t/ord/key', '#/dashboard' …) to a full URL on the right page */
function tj_route_url($hash) {
  $hash = '#/' . ltrim(preg_replace('~^#?/?~', '', (string) $hash), '/');
  $seg = preg_split('~[/?]~', substr($hash, 2))[0];
  $map = ['' => 'home', 'event' => 'event', 'checkout' => 'checkout', 'order' => 'checkout', 'tickets' => 'tickets', 'dashboard' => 'organizer', 'create' => 'organizer', 'edit' => 'organizer', 'attendees' => 'organizer', 'reports' => 'organizer', 'admin' => 'admin', 'login' => 'login', 'register' => 'register', 't' => 'ticket', 'scan' => 'scanner', 'app' => 'tickets'];
  $key = isset($map[$seg]) ? $map[$seg] : 'home';
  // Sites without the separate Scanner page keep scanning inside the Organizer Dashboard page
  if ($key === 'scanner' && !tj_has_page('scanner')) $key = 'organizer';
  $base = tj_page_url($key);
  if ($key === 'home') { $q = strpos($hash, '?') !== false ? substr($hash, strpos($hash, '?') + 1) : ''; return $base . ($q ? (strpos($base, '?') !== false ? '&' : '?') . $q : ''); }
  return $base . $hash;
}

final class TJ_Frontend {
  const SCREENS = [
    'event' => 'Event details & tickets', 'checkout' => 'Checkout', 'tickets' => 'My Ticket Dashboard (attendees)',
    'organizer' => 'Organizer Dashboard', 'admin' => 'Admin Panel', 'login' => 'Log in form', 'register' => 'Create account form',
    'ticket' => 'Ticket viewer (links in emails & texts)', 'home' => 'Complete homepage (all-in-one)',
    'scanner' => 'Door Scanner (check-in & guest list)',
  ];

  public static function ui() {
    return wp_parse_args(get_option('tj_ui', []), ['header' => 1, 'footer' => 1, 'logo' => '', 'footer_text' => '']);
  }

  public static function init() {
    add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
    add_action('wp_head', [__CLASS__, 'head'], 1);
    add_action('wp_loaded', [__CLASS__, 'app_endpoints'], 1);
    add_filter('wp_resource_hints', function ($urls, $rel) { if ($rel === 'preconnect') { $urls[] = 'https://fonts.googleapis.com'; $urls[] = ['href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous']; } return $urls; }, 10, 2);
    add_filter('body_class', function ($c) { $c[] = 'tj-page'; return $c; });
    self::hardening();
    add_shortcode('ticketjunkies', [__CLASS__, 'shortcode']);
    // Attendees & organizers use the TicketJunkies dashboards, not the WordPress back office
    add_filter('show_admin_bar', function ($show) { return $show && current_user_can('edit_posts'); });
    add_action('admin_init', function () {
      if (wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON) || current_user_can('edit_posts')) return;
      if (isset($_SERVER['SCRIPT_NAME']) && basename($_SERVER['SCRIPT_NAME']) === 'admin-post.php') return;
      $u = wp_get_current_user();
      if (!$u || !$u->ID) return;
      wp_safe_redirect(in_array('tj_organizer', (array) $u->roles, true) ? tj_route_url('#/dashboard') : tj_route_url('#/tickets'));
      exit;
    });
    add_action('wp_body_open', [__CLASS__, 'preloader'], 1);
    add_action('wp_footer', [__CLASS__, 'preloader_fallback'], 1);
    // Logging out anywhere (including wp-login.php) lands on the home page
    add_filter('logout_redirect', function ($to, $requested, $user) { return home_url('/'); }, 20, 3);
    // Attendees and organizers who log in through wp-login.php go to their TicketJunkies dashboard
    // (WordPress staff who can edit posts keep going to wp-admin so they can manage the site)
    add_filter('login_redirect', function ($to, $requested, $user) {
      if (!$user || is_wp_error($user) || user_can($user, 'edit_posts')) return $to;
      if ($requested && strpos($requested, admin_url()) !== 0) return $to;
      return in_array('tj_organizer', (array) $user->roles, true) ? tj_route_url('#/dashboard') : tj_route_url('#/tickets');
    }, 20, 3);
    $ui = self::ui();
    if ($ui['header'] || $ui['footer']) add_filter('hello_elementor_header_footer', '__return_false');
    if ($ui['header']) add_action('wp_body_open', [__CLASS__, 'header'], 5);
    if ($ui['footer']) add_action('wp_footer', [__CLASS__, 'footer'], 0);
    add_action('wp_footer', [__CLASS__, 'footer_guard'], -1);
  }

  /**
   * Security & speed hardening for the whole site. Each part can be switched off with a filter
   * (e.g. add_filter('tj_harden_xmlrpc', '__return_false') if a service such as Jetpack needs XML-RPC).
   */
  public static function hardening() {
    // Security headers on every front-end page
    add_action('send_headers', function () {
      if (headers_sent() || !apply_filters('tj_harden_headers', true)) return;
      header('X-Content-Type-Options: nosniff');
      header('X-Frame-Options: SAMEORIGIN');
      header('Referrer-Policy: strict-origin-when-cross-origin');
      header('Permissions-Policy: camera=(self), geolocation=(self), microphone=(), payment=(), usb=()');
      if (is_ssl()) header('Strict-Transport-Security: max-age=15552000');
    });
    // Don't advertise the WordPress version
    remove_action('wp_head', 'wp_generator');
    add_filter('the_generator', '__return_empty_string');
    // XML-RPC is a common password-guessing target; block its log-in methods (pingbacks too)
    if (apply_filters('tj_harden_xmlrpc', true)) {
      add_filter('xmlrpc_enabled', '__return_false');
      add_filter('xmlrpc_methods', function ($m) { unset($m['pingback.ping'], $m['pingback.extensions.getPingbacks']); return $m; });
      add_filter('wp_headers', function ($h) { unset($h['X-Pingback']); return $h; });
    }
    // Stop visitors listing user accounts (?author=1 and the public users API), which bots use to guess log-in names
    if (apply_filters('tj_harden_user_list', true)) {
      add_action('template_redirect', function () {
        if (!is_user_logged_in() && isset($_GET['author'])) { wp_safe_redirect(home_url('/'), 301); exit; }
      }, 0);
      add_filter('rest_endpoints', function ($e) {
        if (is_user_logged_in()) return $e;
        foreach (array_keys($e) as $route) if (strpos($route, '/wp/v2/users') === 0) unset($e[$route]);
        return $e;
      });
    }
    // Speed: drop the emoji script/styles every page loads (all browsers show emoji natively)
    if (apply_filters('tj_speed_emoji', true)) {
      remove_action('wp_head', 'print_emoji_detection_script', 7);
      remove_action('wp_print_styles', 'print_emoji_styles');
      remove_action('admin_print_scripts', 'print_emoji_detection_script');
      remove_action('admin_print_styles', 'print_emoji_styles');
      add_filter('emoji_svg_url', '__return_false');
    }
    // Speed: remove the old embed helper script and RSD/WLW links nobody uses
    remove_action('wp_head', 'rsd_link');
    remove_action('wp_head', 'wlwmanifest_link');
    add_action('wp_footer', function () { if (apply_filters('tj_speed_embed', true)) wp_dequeue_script('wp-embed'); });
  }

  // ------------------------------------------------------------------ mobile app (install + offline)
  /** Path of the site address, e.g. "/" or "/blog/" — keeps app links on the same address the visitor is using */
  public static function home_path() {
    $p = wp_parse_url(home_url('/'), PHP_URL_PATH);
    return $p ? trailingslashit($p) : '/';
  }
  /** Install details for the phone: the TicketJunkies app, or TJ Scanner for promoters */
  public static function manifest($kind) {
    $path = self::home_path();
    $img = TJ_URL . 'assets/img/';
    $scan_start = wp_make_link_relative(add_query_arg('app', 'scanner', tj_page_url(tj_has_page('scanner') ? 'scanner' : 'organizer'))) . '#/scan';
    if ($kind === 'scanner') {
      return [
        'id' => $path . '?app=scanner', 'name' => 'TJ Scanner – door check-in', 'short_name' => 'TJ Scanner',
        'description' => 'Scan TicketJunkies ticket QR codes at the door.',
        'start_url' => $scan_start, 'scope' => $path, 'display' => 'standalone', 'orientation' => 'portrait',
        'background_color' => '#12161f', 'theme_color' => '#12161f',
        'icons' => [
          ['src' => $img . 'scanner-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
          ['src' => $img . 'scanner-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ],
      ];
    }
    $name = wp_strip_all_tags(get_bloginfo('name'));
    return [
      'id' => $path, 'name' => $name !== '' ? $name : 'TicketJunkies', 'short_name' => apply_filters('tj_app_short_name', 'TicketJunkies'),
      'description' => 'Discover events and buy tickets. Your tickets open even offline.',
      'start_url' => $path . '?source=app', 'scope' => $path, 'display' => 'standalone', 'orientation' => 'portrait',
      'background_color' => '#ffffff', 'theme_color' => '#376eb8', 'categories' => ['entertainment', 'events', 'lifestyle'],
      'icons' => [
        ['src' => $img . 'icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $img . 'icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $img . 'icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
      ],
      'shortcuts' => [
        ['name' => 'My tickets', 'url' => wp_make_link_relative(tj_route_url('#/tickets')), 'icons' => [['src' => $img . 'icon-192.png', 'sizes' => '192x192']]],
        ['name' => 'Scan tickets (promoters)', 'url' => $scan_start, 'icons' => [['src' => $img . 'scanner-192.png', 'sizes' => '192x192']]],
      ],
    ];
  }
  /** /?tj_sw=1 is the offline worker, /?tj_manifest=main|scanner the install details (turn off with add_filter('tj_pwa', '__return_false')) */
  public static function app_endpoints() {
    if (!isset($_GET['tj_sw']) && !isset($_GET['tj_manifest'])) return;
    if (is_admin() || !apply_filters('tj_pwa', true)) return;
    if (isset($_GET['tj_sw'])) {
      $file = TJ_DIR . 'assets/sw.js';
      $js = is_readable($file) ? file_get_contents($file) : false;
      if ($js === false) { status_header(404); exit; }
      $js = str_replace("const VERSION = '1.3.0';", "const VERSION = '" . TJ_VERSION . '-' . filemtime($file) . "';", $js);
      status_header(200);
      nocache_headers();
      header('Content-Type: application/javascript; charset=utf-8');
      header('Service-Worker-Allowed: ' . self::home_path());
      header('X-Content-Type-Options: nosniff');
      echo $js;
      exit;
    }
    $kind = sanitize_key(wp_unslash($_GET['tj_manifest'])) === 'scanner' ? 'scanner' : 'main';
    status_header(200);
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    echo wp_json_encode(self::manifest($kind), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
  }

  /** Theme is applied before paint so dark mode never flashes white */
  public static function head() {
    echo "<meta name=\"color-scheme\" content=\"light dark\" />\n";
    echo "<script>(function(){var p='auto';try{p=localStorage.getItem('tj_theme')||'auto'}catch(e){}var d=p==='dark'||(p==='auto'&&window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches);document.documentElement.setAttribute('data-theme',d?'dark':'light');document.documentElement.setAttribute('data-theme-pref',p);})();</script>\n";
    if (!has_site_icon()) {
      echo '<link rel="icon" href="' . esc_url(TJ_URL . 'assets/img/favicon.png') . "\" />\n";
      echo '<link rel="apple-touch-icon" href="' . esc_url(TJ_URL . 'assets/img/apple-touch-icon.png') . "\" />\n";
    }
    if (apply_filters('tj_pwa', true)) {
      echo '<link rel="manifest" href="' . esc_attr(self::home_path() . '?tj_manifest=main') . "\" />\n";
      echo "<meta name=\"mobile-web-app-capable\" content=\"yes\" />\n<meta name=\"apple-mobile-web-app-capable\" content=\"yes\" />\n<meta name=\"apple-mobile-web-app-title\" content=\"TicketJunkies\" />\n<meta name=\"apple-mobile-web-app-status-bar-style\" content=\"default\" />\n";
    }
    echo "<meta name=\"theme-color\" content=\"#ffffff\" media=\"(prefers-color-scheme: light)\" />\n<meta name=\"theme-color\" content=\"#12161f\" media=\"(prefers-color-scheme: dark)\" />\n";
    // Social media previews, only when no SEO plugin (Yoast, Rank Math, All in One SEO, SEOPress) already adds them
    $seo = defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('AIOSEO_VERSION') || defined('SEOPRESS_VERSION');
    if (!$seo && is_singular()) {
      $title = wp_strip_all_tags(get_the_title()) . ' | ' . get_bloginfo('name');
      $desc = get_bloginfo('description') ? get_bloginfo('description') : 'Discover events and buy tickets.';
      $img = has_post_thumbnail() ? get_the_post_thumbnail_url(null, 'large') : TJ_URL . 'assets/img/og/default.png';
      $url = get_permalink();
      printf("<meta property=\"og:type\" content=\"website\" />\n<meta property=\"og:site_name\" content=\"%s\" />\n<meta property=\"og:title\" content=\"%s\" />\n<meta property=\"og:description\" content=\"%s\" />\n<meta property=\"og:url\" content=\"%s\" />\n<meta property=\"og:image\" content=\"%s\" />\n<meta name=\"twitter:card\" content=\"summary_large_image\" />\n<meta name=\"twitter:title\" content=\"%s\" />\n<meta name=\"twitter:description\" content=\"%s\" />\n<meta name=\"twitter:image\" content=\"%s\" />\n",
        esc_attr(get_bloginfo('name')), esc_attr($title), esc_attr($desc), esc_url($url), esc_url($img), esc_attr($title), esc_attr($desc), esc_url($img));
    }
  }

  /** Preloader: a small icon in the middle of the screen while a page opens (Admin → Settings → Preloader) */
  private static $pre_done = false;
  public static function preloader_markup($hidden = false) {
    $s = TJ_Store::settings();
    $p = $s['preloader'];
    if (empty($p['enabled'])) return '';
    $icon = (string) $p['icon'];
    $src = ($icon === 'custom' && $p['customUrl'] !== '') ? $p['customUrl'] : (in_array($icon, array('logo', 'custom'), true) ? TJ_URL . 'assets/img/favicon.png' : TJ_URL . 'assets/img/preloader/' . sanitize_file_name($icon) . '.svg');
    $size = max(24, min(160, (int) $p['size']));
    $cls = 'tj-pre pre-' . sanitize_html_class($p['animation']) . ' pre-speed-' . sanitize_html_class($p['speed']) . ' pre-bg-' . sanitize_html_class($p['background']) . ($hidden ? ' done' : '');
    $js = "(function(){var w=document.getElementById('tjPre');if(!w)return;var t0=Date.now(),tm=0;"
      . "function hide(){clearTimeout(tm);tm=setTimeout(function(){w.classList.add('done')},Math.max(0,300-(Date.now()-t0)))}"
      . "if(document.readyState==='complete')hide();else window.addEventListener('load',hide);"
      . "setTimeout(function(){w.classList.add('done')},8000);"
      . "window.addEventListener('pageshow',function(e){if(e.persisted)w.classList.add('done')});"
      . "document.addEventListener('click',function(e){var a=e.target&&e.target.closest?e.target.closest('a[href]'):null;"
      . "if(!a||e.button||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey||(a.target&&a.target!=='_self')||a.hasAttribute('download'))return;"
      . "var u;try{u=new URL(a.href,location.href)}catch(x){return}"
      . "if(u.origin!==location.origin||(u.pathname===location.pathname&&u.search===location.search))return;"
      . "if(/\\/wp-(admin|login)|\\.(pdf|zip|csv|xlsx|docx|ics|png|jpe?g|gif|webp)$/i.test(u.pathname))return;"
      . "setTimeout(function(){if(e.defaultPrevented)return;t0=Date.now();w.classList.remove('done');clearTimeout(tm);tm=setTimeout(function(){w.classList.add('done')},8000)},0)});})();";
    return '<div class="tj-root tj-pre-host"><div id="tjPre" class="' . esc_attr($cls) . '" style="--pre-size:' . $size . 'px" role="status" aria-label="Loading"><img src="' . esc_url($src) . '" alt="" width="' . $size . '" height="' . $size . '" /></div></div><script>' . $js . "</script>\n";
  }
  public static function preloader() { if (self::$pre_done || self::is_editor()) return; self::$pre_done = true; echo self::preloader_markup(); }
  /** Themes without wp_body_open: add it at the bottom (hidden) so link clicks still show it */
  public static function preloader_fallback() { if (self::$pre_done || self::is_editor()) return; self::$pre_done = true; echo self::preloader_markup(true); }

  public static function is_editor() {
    if (!did_action('elementor/loaded')) return false;
    $p = \Elementor\Plugin::$instance;
    return (isset($p->preview) && $p->preview->is_preview_mode()) || (isset($p->editor) && $p->editor->is_edit_mode());
  }

  public static function config() {
    $pages = [];
    foreach (['home', 'event', 'checkout', 'tickets', 'organizer', 'admin', 'login', 'register', 'ticket'] as $k) $pages[$k] = tj_page_url($k);
    if (tj_has_page('scanner')) $pages['scanner'] = tj_page_url('scanner');
    $cfg = [
      'rest' => esc_url_raw(rest_url('tj/v1/')), 'nonce' => wp_create_nonce('wp_rest'), 'home' => home_url('/'),
      'assets' => TJ_URL . 'assets/', 'pages' => $pages, 'editor' => self::is_editor(),
      'lostPassword' => wp_lostpassword_url(tj_page_url('login')),
      'autoHero' => (bool) apply_filters('tj_auto_hero', true),
      'autoCalendar' => (bool) apply_filters('tj_auto_calendar', true),
    ];
    if ($cfg['editor']) {
      $r = rest_do_request(new WP_REST_Request('GET', '/tj/v1/events'));
      $d = $r->get_data();
      if (!empty($d['events'][0]['id'])) $cfg['sampleEvent'] = $d['events'][0]['id'];
    }
    return $cfg;
  }

  public static function assets() {
    wp_enqueue_style('tj-fonts', 'https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;500;600;700;800&display=swap', [], null);
    wp_enqueue_style('tj', TJ_URL . 'assets/tj.css', ['tj-fonts'], TJ_VERSION . '-' . filemtime(TJ_DIR . 'assets/tj.css'));
    // 'defer' lets the page draw before the scripts run (WordPress 6.3+; older versions just load them in the footer)
    wp_enqueue_script('tj-maps', TJ_URL . 'assets/tj-maps.js', [], TJ_VERSION . '-' . filemtime(TJ_DIR . 'assets/tj-maps.js'), ['in_footer' => true, 'strategy' => 'defer']);
    wp_enqueue_script('tj-app', TJ_URL . 'assets/tj-app.js', ['tj-maps'], TJ_VERSION . '-' . filemtime(TJ_DIR . 'assets/tj-app.js'), ['in_footer' => true, 'strategy' => 'defer']);
    wp_add_inline_script('tj-maps', 'window.TJ_CONFIG = ' . wp_json_encode(self::config()) . ';', 'before');
  }

  public static function logo() {
    $ui = self::ui();
    if (!empty($ui['logo'])) return $ui['logo'];
    $id = get_theme_mod('custom_logo');
    if ($id) { $src = wp_get_attachment_image_url($id, 'full'); if ($src) return $src; }
    return TJ_URL . 'assets/img/logo.png';
  }

  public static function header() {
    if (self::is_editor() && !is_singular()) return;
    $logo = esc_url(self::logo());
    $name = esc_attr(get_bloginfo('name'));
    echo '<div class="tj-root tj-site-header"><header class="topbar"><div class="wrap topbar-inner">';
    echo '<a href="' . esc_url(home_url('/')) . '" class="brand"><img src="' . $logo . '" alt="' . $name . '" /></a>';
    echo '<div class="theme-switch" id="themeSwitch" role="group" aria-label="Color theme">'
      . '<button type="button" data-theme-set="light" title="Light (day)" aria-label="Light theme"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="4.2"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg></button>'
      . '<button type="button" data-theme-set="dark" title="Dark (night)" aria-label="Dark theme"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 14.2A8.5 8.5 0 1 1 9.8 3.5a6.8 6.8 0 0 0 10.7 10.7z"/></svg></button>'
      . '<button type="button" data-theme-set="auto" title="Auto (follow my device)" aria-label="Automatic theme"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 3.5a8.5 8.5 0 0 1 0 17z" fill="currentColor"/></svg></button>'
      . '</div>';
    echo '<button type="button" class="menu-btn" id="menuBtn" aria-label="Menu">☰</button><nav id="nav" class="nav"></nav>';
    echo '</div></header></div>';
  }

  /** Runs before the footer is drawn: a TicketJunkies area that is still loading fills the screen, so the footer never shows up mid-page. */
  public static function footer_guard() {
    if (self::is_editor()) return;
    echo '<script>(function(){var w=document.querySelectorAll(".tj-root.tj-widget"),e=w[w.length-1];if(e&&e.querySelector(".loading")){var t=e.getBoundingClientRect().top;e.style.minHeight=Math.max(0,Math.ceil(innerHeight-t))+"px";}})();</script>' . "\n";
  }

  public static function footer() {
    if (self::is_editor() && !is_singular()) return;
    $ui = self::ui();
    $text = $ui['footer_text'] !== '' ? esc_html($ui['footer_text']) : esc_html(get_bloginfo('name')) . '. All rights reserved.';
    echo '<div class="tj-root tj-site-footer"><footer class="footer"><div class="wrap footer-inner">';
    echo '<img src="' . esc_url(self::logo()) . '" alt="' . esc_attr(get_bloginfo('name')) . '" class="footer-logo" />';
    echo '<div class="footer-links"><a href="' . esc_url(home_url('/')) . '">Browse events</a><a href="' . esc_url(tj_route_url('#/register?role=organizer')) . '">Sell tickets</a><a href="' . esc_url(tj_route_url('#/register')) . '">Create account</a>' . (apply_filters('tj_pwa', true) ? '<a href="' . esc_url(tj_route_url('#/app')) . '">📲 Get the app</a>' : '') . '</div>';
    echo '<p class="muted small">© <span id="year">' . esc_html(gmdate('Y')) . '</span> ' . $text . '</p>';
    echo '</div></footer></div>';
  }

  /** Markup for one widget (used by Elementor widgets and the [ticketjunkies] shortcode) */
  public static function widget_html($type, array $opts = []) {
    $note = '';
    if ($type === 'screen' && self::is_editor()) {
      $label = isset(self::SCREENS[$opts['screen'] ?? '']) ? self::SCREENS[$opts['screen']] : 'TicketJunkies screen';
      $note = '<div class="tj-editor-note">🎟️ <strong>TicketJunkies – ' . esc_html($label) . '</strong><br>This area is filled in automatically for each visitor (live preview below). Use the <em>Style</em> tab to change colors, fonts and corners, and add your own Elementor widgets above or below it.</div>';
    }
    return $note . '<div class="tj-root tj-widget" data-tj-widget="' . esc_attr($type) . '" data-tj-opts="' . esc_attr(wp_json_encode($opts)) . '"><div class="loading">Loading…</div></div>';
  }

  /** [ticketjunkies widget="grid" title="Upcoming events"] or [ticketjunkies screen="checkout"] */
  public static function shortcode($atts) {
    $atts = (array) $atts;
    $type = isset($atts['widget']) ? sanitize_key($atts['widget']) : (isset($atts['screen']) ? 'screen' : 'grid');
    unset($atts['widget']);
    foreach ($atts as $k => $v) { if ($v === 'false' || $v === 'no') $atts[$k] = false; elseif ($v === 'true' || $v === 'yes') $atts[$k] = true; }
    return self::widget_html($type, $atts);
  }

  // ------------------------------------------------------------------ pages created on activation
  private static function eid() { return substr(md5(uniqid('', true)), 0, 7); }
  private static function w($type, array $settings = []) { return ['id' => self::eid(), 'elType' => 'widget', 'widgetType' => $type, 'settings' => (object) $settings, 'elements' => []]; }
  private static function c(array $elements, array $settings = [], $inner = false) {
    return ['id' => self::eid(), 'elType' => 'container', 'settings' => (object) $settings, 'elements' => $elements, 'isInner' => $inner];
  }
  private static function px($t, $r = null, $b = null, $l = null) {
    return ['unit' => 'px', 'top' => (string) $t, 'right' => (string) ($r ?? $t), 'bottom' => (string) ($b ?? $t), 'left' => (string) ($l ?? ($r ?? $t)), 'isLinked' => false];
  }

  private static function screen_page($screen) {
    return [self::c([self::w('tj_' . $screen)], ['content_width' => 'boxed', 'padding' => self::px(24, 20, 60, 20)])];
  }

  private static function home_page() {
    $hero_left = self::c([
      self::w('heading', ['title' => 'Get your ticket fix.', 'header_size' => 'h1', 'title_color' => '#FFFFFF', 'typography_typography' => 'custom', 'typography_font_family' => 'Anton', 'typography_font_weight' => '400', 'typography_text_transform' => 'uppercase', 'typography_font_size' => ['unit' => 'px', 'size' => 60], 'typography_font_size_tablet' => ['unit' => 'px', 'size' => 46], 'typography_font_size_mobile' => ['unit' => 'px', 'size' => 38], 'typography_line_height' => ['unit' => 'em', 'size' => 1.05]]),
      self::w('text-editor', ['editor' => '<p>Discover the hottest parties, concerts, comedy shows and festivals — or launch your own event and start selling tickets in minutes.</p>', 'text_color' => '#FFFFFF', 'typography_typography' => 'custom', 'typography_font_size' => ['unit' => 'px', 'size' => 18]]),
      self::w('tj_search', []),
      self::w('tj_cta', ['_element_custom_width' => ['unit' => '%', 'size' => 100]]),
    ], ['content_width' => 'full', 'width' => ['unit' => '%', 'size' => 100], 'width_tablet' => ['unit' => '%', 'size' => 100], 'flex_gap' => ['unit' => 'px', 'size' => 16, 'column' => '16', 'row' => '16'], 'padding' => self::px(0)], true);
    $hero = self::c([$hero_left], [
      'content_width' => 'full', 'flex_direction' => 'row', 'flex_direction_tablet' => 'column', 'flex_justify_content' => 'space-between', 'flex_align_items' => 'flex-start', 'flex_wrap' => 'nowrap',
      'flex_gap' => ['unit' => 'px', 'size' => 30, 'column' => '30', 'row' => '24'],
      'background_background' => 'gradient', 'background_color' => '#376EB8', 'background_color_b' => '#EB742A', 'background_gradient_angle' => ['unit' => 'deg', 'size' => 135],
      'background_color_stop' => ['unit' => '%', 'size' => 0], 'background_color_b_stop' => ['unit' => '%', 'size' => 100],
      'border_radius' => self::px(22), 'padding' => self::px(56, 48), 'padding_mobile' => self::px(30, 20), 'css_classes' => 'tj-hero-box',
    ], true);
    $promo = self::c([
      self::c([
        self::w('heading', ['title' => 'Throw events? Sell tickets here.', 'header_size' => 'h2', 'typography_typography' => 'custom', 'typography_font_family' => 'Anton', 'typography_font_weight' => '400', 'typography_text_transform' => 'uppercase', 'typography_font_size' => ['unit' => 'px', 'size' => 32]]),
        self::w('text-editor', ['editor' => '<p>Create your event page, set ticket types and prices, track sales and check guests in at the door.</p>']),
      ], ['content_width' => 'full', 'width' => ['unit' => '%', 'size' => 70], 'width_mobile' => ['unit' => '%', 'size' => 100], 'padding' => self::px(0), 'flex_gap' => ['unit' => 'px', 'size' => 4, 'column' => '4', 'row' => '4']], true),
      self::w('button', ['text' => "Start selling — it's free", 'link' => ['url' => tj_route_url('#/register?role=organizer'), 'is_external' => '', 'nofollow' => ''], 'background_color' => '#EB742A', 'border_radius' => self::px(12), 'typography_typography' => 'custom', 'typography_font_weight' => '700']),
    ], ['content_width' => 'full', 'flex_direction' => 'row', 'flex_direction_mobile' => 'column', 'flex_justify_content' => 'space-between', 'flex_align_items' => 'center', 'background_background' => 'classic', 'background_color' => '#FFFFFF', 'border_radius' => self::px(18), 'padding' => self::px(30, 34), 'margin' => self::px(30, 0, 0, 0), 'box_shadow_box_shadow_type' => 'yes', 'box_shadow_box_shadow' => ['horizontal' => 0, 'vertical' => 6, 'blur' => 24, 'spread' => 0, 'color' => 'rgba(20,30,60,0.08)']], true);
    return [self::c([
      self::w('tj_weather', []),
      $hero,
      self::w('tj_chips', []),
      self::w('tj_map', []),
      self::w('tj_calendar', []),
      self::w('tj_grid', []),
      $promo,
    ], ['content_width' => 'boxed', 'padding' => self::px(28, 20, 60, 20), 'flex_gap' => ['unit' => 'px', 'size' => 26, 'column' => '26', 'row' => '26']])];
  }

  public static function page_defs() {
    return [
      'home' => ['Home', 'home', 'home'], 'event' => ['Event', 'event', 'event'], 'checkout' => ['Checkout', 'checkout', 'checkout'],
      'tickets' => ['My Dashboard', 'my-tickets', 'tickets'], 'organizer' => ['Organizer Dashboard', 'organizer', 'organizer'],
      'admin' => ['Admin Panel', 'ticket-admin', 'admin'], 'login' => ['Log In', 'login', 'login'], 'register' => ['Create Account', 'register', 'register'],
      'ticket' => ['Your Tickets', 'ticket', 'ticket'], 'scanner' => ['Ticket Scanner', 'scanner', 'scanner'],
    ];
  }

  /** Creates the pages (built with Elementor when it is active) and Elementor site settings. Safe to run again. */
  public static function install($force = false, $only = null) {
    $pages = get_option('tj_pages', []);
    $elementor = did_action('elementor/loaded') || defined('ELEMENTOR_VERSION');
    foreach (self::page_defs() as $key => $def) {
      if ($only && $key !== $only) continue;
      list($title, $slug, $screen) = $def;
      if (!$force && !empty($pages[$key]) && get_post_status($pages[$key]) && get_post_status($pages[$key]) !== 'trash') continue;
      $fallback = $key === 'home'
        ? "[ticketjunkies widget=\"search\"]\n[ticketjunkies widget=\"weather\"]\n[ticketjunkies widget=\"chips\"]\n[ticketjunkies widget=\"map\"]\n[ticketjunkies widget=\"grid\"]"
        : '[ticketjunkies screen="' . $screen . '"]';
      $id = !empty($pages[$key]) && get_post_status($pages[$key]) && get_post_status($pages[$key]) !== 'trash' ? (int) $pages[$key] : 0;
      $post = ['post_title' => $title, 'post_name' => $slug, 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => $fallback, 'comment_status' => 'closed'];
      if ($id) { $post['ID'] = $id; wp_update_post($post); } else { $id = wp_insert_post($post); }
      if (!$id || is_wp_error($id)) continue;
      $pages[$key] = $id;
      update_post_meta($id, '_tj_page', $key);
      if ($elementor) {
        $data = $key === 'home' ? self::home_page() : self::screen_page($screen);
        update_post_meta($id, '_elementor_edit_mode', 'builder');
        update_post_meta($id, '_elementor_template_type', 'wp-page');
        update_post_meta($id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0');
        update_post_meta($id, '_elementor_data', wp_slash(wp_json_encode($data)));
        update_post_meta($id, '_wp_page_template', 'elementor_header_footer');
        delete_post_meta($id, '_elementor_css');
      }
    }
    update_option('tj_pages', $pages);
    if (!empty($pages['home']) && (get_option('show_on_front') !== 'page' || !get_option('page_on_front') || $force)) {
      update_option('show_on_front', 'page');
      update_option('page_on_front', (int) $pages['home']);
    }
    if ($elementor) self::elementor_site_settings();
  }

  /** Wide layout (1600px), brand Global Colors and fonts in Elementor's Site Settings */
  public static function elementor_site_settings() {
    update_option('elementor_disable_color_schemes', 'yes');
    update_option('elementor_disable_typography_schemes', 'yes');
    update_option('elementor_container_width', '1600');
    $kit = (int) get_option('elementor_active_kit');
    if (!$kit) return;
    $s = get_post_meta($kit, '_elementor_page_settings', true);
    if (!is_array($s)) $s = [];
    if (!empty($s['tj_configured'])) return;
    $s['container_width'] = ['unit' => 'px', 'size' => 1600, 'sizes' => []];
    $s['system_colors'] = [
      ['_id' => 'primary', 'title' => 'Primary (blue)', 'color' => '#376EB8'],
      ['_id' => 'secondary', 'title' => 'Secondary (green)', 'color' => '#84D65F'],
      ['_id' => 'text', 'title' => 'Text', 'color' => '#1B1F2A'],
      ['_id' => 'accent', 'title' => 'Accent (orange)', 'color' => '#EB742A'],
    ];
    $s['system_typography'] = [
      ['_id' => 'primary', 'title' => 'Headings', 'typography_typography' => 'custom', 'typography_font_family' => 'Anton', 'typography_font_weight' => '400'],
      ['_id' => 'secondary', 'title' => 'Secondary', 'typography_typography' => 'custom', 'typography_font_family' => 'Inter', 'typography_font_weight' => '600'],
      ['_id' => 'text', 'title' => 'Text', 'typography_typography' => 'custom', 'typography_font_family' => 'Inter', 'typography_font_weight' => '400'],
      ['_id' => 'accent', 'title' => 'Accent', 'typography_typography' => 'custom', 'typography_font_family' => 'Inter', 'typography_font_weight' => '700'],
    ];
    $s['tj_configured'] = 1;
    update_post_meta($kit, '_elementor_page_settings', $s);
    if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->files_manager)) \Elementor\Plugin::$instance->files_manager->clear_cache();
  }
}

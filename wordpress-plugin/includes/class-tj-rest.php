<?php if (!defined('ABSPATH')) exit;
/**
 * TicketJunkies REST API (namespace tj/v1): a 1:1 port of the /api routes in server.mjs.
 * Same methods, request JSON, response JSON, status codes and messages; errors are {error: message}.
 */
class TJ_Fail extends Exception {
  public $status;
  public function __construct($status, $message) { parent::__construct($message); $this->status = $status; }
}

class TJ_Rest {
  const NS = 'tj/v1';
  const SHARE_RULE = '^e/(evt_[0-9a-f]+)/?$';
  private static $routes = array();
  private static $export_sections = array('summary', 'days', 'events', 'cards', 'transactions', 'attendees');

  // =====================================================================
  // bootstrap
  // =====================================================================
  public static function init() {
    add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    add_action('init', array(__CLASS__, 'on_init'), 20);
    self::security_hooks();
    add_filter('query_vars', function ($v) { $v[] = 'tj_share'; return $v; });
    add_action('template_redirect', array(__CLASS__, 'share_redirect'), 1);
    // Suspended accounts can't log in anywhere (wp-login.php included).
    add_filter('authenticate', function ($user) {
      if ($user instanceof WP_User && get_user_meta($user->ID, 'tj_suspended', true)) {
        return new WP_Error('tj_suspended', 'This account has been suspended. Please contact support.');
      }
      return $user;
    }, 100);
    add_action('tj_locate_missing', array(__CLASS__, 'locate_missing'));
    // A stale nonce (expired/ended session) means "logged out" on tj/v1, like an expired session in the Node app,
    // instead of WordPress's 403 rest_cookie_invalid_nonce (which would block logging back in).
    add_filter('rest_authentication_errors', array(__CLASS__, 'stale_nonce_as_guest'), 101);
  }

  public static function stale_nonce_as_guest($result) {
    if (!is_wp_error($result) || $result->get_error_code() !== 'rest_cookie_invalid_nonce') return $result;
    $route = isset($GLOBALS['wp']->query_vars['rest_route']) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
    if ($route === '' && isset($_SERVER['REQUEST_URI'])) $route = (string) wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (strpos($route, '/' . self::NS . '/') === false) return $result;
    wp_set_current_user(0);
    return true;
  }

  public static function add_rewrite() {
    add_rewrite_rule(self::SHARE_RULE, 'index.php?tj_share=$matches[1]', 'top');
  }
  public static function on_init() {
    self::add_rewrite();
    $rules = get_option('rewrite_rules');
    if (get_option('tj_flush_rewrite') || (get_option('permalink_structure') && is_array($rules) && !isset($rules[self::SHARE_RULE]))) {
      delete_option('tj_flush_rewrite');
      flush_rewrite_rules(false);
    }
  }

  private static function route($method, $pattern, $handler) {
    self::$routes[] = array($method, $pattern, $handler);
  }

  public static function register_routes() {
    self::$routes = array();
    self::define_routes();
    foreach (self::$routes as $r) {
      list($method, $pattern, $handler) = $r;
      $path = preg_replace('/:(\w+)/', '(?P<$1>[^/]+)', $pattern);
      register_rest_route(self::NS, $path, array(
        'methods' => $method,
        'permission_callback' => '__return_true',
        'callback' => function ($request) use ($handler) { return TJ_Rest::dispatch($request, $handler); },
      ));
    }
  }

  public static function dispatch($request, $handler) {
    $body = array();
    if (in_array($request->get_method(), array('POST', 'PUT', 'PATCH'), true)) {
      $j = $request->get_json_params();
      if (!is_array($j)) $j = $request->get_body_params();
      $body = is_array($j) ? $j : array();
    }
    $ctx = array('req' => $request, 'body' => $body, 'params' => $request->get_url_params(), 'query' => $request->get_query_params());
    try {
      $result = call_user_func(array(__CLASS__, $handler), $ctx);
      $res = new WP_REST_Response($result, 200);
    } catch (TJ_Fail $e) {
      $res = new WP_REST_Response(array('error' => $e->getMessage()), $e->status);
    } catch (Throwable $e) {
      error_log('[tj rest] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
      $res = new WP_REST_Response(array('error' => 'Something went wrong'), 500);
    }
    $res->header('Cache-Control', 'no-store');
    return $res;
  }

  private static function define_routes() {
    self::route('GET', '/meta', 'r_meta');
    self::route('GET', '/search-options', 'r_search_options');
    self::route('GET', '/calendar', 'r_calendar');
    self::route('GET', '/weather', 'r_weather');
    self::route('GET', '/geocode', 'r_geocode');
    self::route('POST', '/auth/register', 'r_register');
    self::route('POST', '/auth/login', 'r_login');
    self::route('POST', '/auth/logout', 'r_logout');
    self::route('GET', '/auth/me', 'r_me');
    self::route('GET', '/events', 'r_events');
    self::route('POST', '/events', 'r_event_create');
    self::route('GET', '/events/:id', 'r_event_get');
    self::route('PUT', '/events/:id', 'r_event_update');
    self::route('DELETE', '/events/:id', 'r_event_delete');
    self::route('POST', '/events/:id/checkout', 'r_checkout');
    self::route('POST', '/cart/checkout', 'r_cart_checkout');
    self::route('POST', '/events/:id/comp', 'r_comp');
    self::route('GET', '/orders', 'r_orders');
    self::route('GET', '/orders/:id', 'r_order');
    self::route('GET', '/orders/:id/tickets.pdf', 'r_order_pdf');
    self::route('POST', '/orders/:id/resend', 'r_resend');
    self::route('GET', '/organizer/events', 'r_org_events');
    self::route('GET', '/organizer/events/:id', 'r_org_event');
    self::route('GET', '/organizer/events/:id/attendees', 'r_attendees');
    self::route('POST', '/organizer/checkin', 'r_checkin');
    self::route('GET', '/organizer/events/:id/guests', 'r_guests');
    self::route('POST', '/organizer/events/:id/guests', 'r_guest_add');
    self::route('DELETE', '/organizer/events/:id/guests/:gid', 'r_guest_delete');
    self::route('POST', '/organizer/events/:id/guests/:gid/checkin', 'r_guest_checkin');
    self::route('GET', '/organizer/reports', 'r_org_reports');
    self::route('GET', '/organizer/reports/export', 'r_org_export');
    self::route('POST', '/upload', 'r_upload');
    self::route('GET', '/admin/stats', 'r_admin_stats');
    self::route('GET', '/admin/users', 'r_admin_users');
    self::route('POST', '/admin/users', 'r_admin_user_create');
    self::route('GET', '/admin/users/:id', 'r_admin_user');
    self::route('PUT', '/admin/users/:id', 'r_admin_user_update');
    self::route('DELETE', '/admin/users/:id', 'r_admin_user_delete');
    self::route('GET', '/directory', 'r_directory');
    self::route('GET', '/admin/organizers', 'r_admin_organizers');
    self::route('POST', '/admin/organizers', 'r_admin_organizer_create');
    self::route('PUT', '/admin/organizers/:id', 'r_admin_organizer_update');
    self::route('DELETE', '/admin/organizers/:id', 'r_admin_organizer_delete');
    self::route('GET', '/admin/venues', 'r_admin_venues');
    self::route('POST', '/admin/venues', 'r_admin_venue_create');
    self::route('PUT', '/admin/venues/:id', 'r_admin_venue_update');
    self::route('DELETE', '/admin/venues/:id', 'r_admin_venue_delete');
    self::route('GET', '/admin/events', 'r_admin_events');
    self::route('GET', '/admin/orders', 'r_admin_orders');
    self::route('POST', '/admin/orders/:id/refund', 'r_admin_refund');
    self::route('GET', '/admin/settings', 'r_admin_settings');
    self::route('PUT', '/admin/settings', 'r_admin_settings_put');
    self::route('PUT', '/admin/captcha', 'r_admin_captcha');
    self::route('PUT', '/admin/maps', 'r_admin_maps');
    self::route('PUT', '/admin/preloader', 'r_admin_preloader');
    self::route('PUT', '/admin/security', 'r_admin_security');
    self::route('POST', '/admin/logout-everyone', 'r_admin_logout_everyone');
    self::route('POST', '/admin/users/:id/send-reset', 'r_admin_user_send_reset');
    self::route('POST', '/admin/users/:id/logout', 'r_admin_user_logout');
    self::route('POST', '/admin/maps/locate-all', 'r_admin_locate_all');
    self::route('PUT', '/admin/notifications', 'r_admin_notifications_put');
    self::route('POST', '/admin/notifications/test', 'r_admin_notifications_test');
    self::route('GET', '/admin/notifications', 'r_admin_notifications');
    self::route('GET', '/admin/notifications/:id', 'r_admin_notification');
    self::route('GET', '/admin/sandbox', 'r_sandbox');
    self::route('POST', '/admin/sandbox/sale', 'r_sandbox_sale');
    self::route('GET', '/admin/sandbox/orders/:id/messages', 'r_sandbox_messages');
    self::route('DELETE', '/admin/sandbox', 'r_sandbox_clear');
    self::route('GET', '/admin/reports', 'r_admin_reports');
    self::route('GET', '/admin/reports/export', 'r_admin_export');
    self::route('GET', '/tickets/:id', 'r_ticket_page');
    self::route('GET', '/tickets/:id/pdf', 'r_ticket_pdf');
    // QR images (served at /qr/... in the Node app)
    self::route('GET', '/qr/link.svg', 'r_qr_link');
    self::route('GET', '/qr/event.svg', 'r_qr_event');
    self::$routes[] = array('GET', '/qr/(?P<code>TJ-[0-9A-Fa-f]{8})\.(?P<ext>png|svg)', 'r_qr_code');
  }

  // =====================================================================
  // helpers
  // =====================================================================
  public static function fail($status, $msg) { throw new TJ_Fail($status, $msg); }

  private static function len($s) { return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s); }

  /** str() from server.mjs: trims strings, everything else becomes ''. */
  public static function str($v, $name, $max = 200, $required = false) {
    $s = is_string($v) ? trim($v) : '';
    if ($required && $s === '') self::fail(400, $name . ' is required');
    if (self::len($s) > $max) self::fail(400, $name . ' must be ' . $max . ' characters or fewer');
    return $s;
  }
  private static function g($arr, $k, $def = null) { return is_array($arr) && array_key_exists($k, $arr) ? $arr[$k] : $def; }
  private static function has($arr, $k) { return is_array($arr) && array_key_exists($k, $arr); }
  /** JS Number(); $missing => NaN (undefined). */
  public static function num($v, $missing = false) {
    if ($missing) return NAN;
    if ($v === null) return 0.0;
    if (is_bool($v)) return $v ? 1.0 : 0.0;
    if (is_int($v) || is_float($v)) return (float) $v;
    if (is_string($v)) {
      $t = trim($v);
      if ($t === '') return 0.0;
      if ($t === 'Infinity' || $t === '+Infinity') return INF;
      if ($t === '-Infinity') return -INF;
      if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $t)) return (float) $t;
      if (preg_match('/^0[xX][0-9a-fA-F]+$/', $t)) return (float) hexdec($t);
      return NAN;
    }
    return NAN;
  }
  private static function numk($arr, $k) { return self::num(self::g($arr, $k), !self::has($arr, $k)); }
  public static function finite($n) { return (is_int($n) || is_float($n)) && is_finite((float) $n); }
  private static function is_integer_num($n) { return self::finite($n) && floor($n) == $n; }
  public static function round2($n) { return floor($n * 100 + 0.5) / 100; }
  /** Integral floats become ints so JSON matches the Node output. */
  public static function n($x) { return (is_float($x) && is_finite($x) && floor($x) == $x && abs($x) < 1e15) ? (int) $x : $x; }
  private static function valid_email($e) { return (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/u', $e); }
  private static function q($ctx, $k) { $v = self::g($ctx['query'], $k); return is_string($v) ? $v : ($v === null ? null : ''); }
  private static function rand_hex($bytes) { return bin2hex(random_bytes($bytes)); }
  private static function ticket_code() { return 'TJ-' . strtoupper(self::rand_hex(4)); }
  private static function iso_ms($ms) {
    $s = (int) floor($ms / 1000);
    return gmdate('Y-m-d\TH:i:s', $s) . '.' . sprintf('%03d', (int) round($ms - $s * 1000)) . 'Z';
  }
  private static function now_ms() { return floor(microtime(true) * 1000); }
  private static function by_id($list, $id) { foreach ($list as $x) if ($x['id'] === $id) return $x; return null; }

  public static function settings() { return TJ_Store::settings(); }
  public static function ticket_fee($price) {
    $s = self::settings();
    return $price > 0 ? self::round2($price * $s['feePercent'] + $s['feeFixed']) : 0;
  }

  // ---------- users / auth ----------
  public static function current_user() {
    $id = get_current_user_id();
    if (!$id) return null;
    $u = TJ_Store::user_by_id($id);
    return $u && !$u['suspended'] ? $u : null;
  }
  public static function public_user($u) {
    if (!$u) return null;
    return array('id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'] ? $u['phone'] : '', 'role' => $u['role'], 'organization' => $u['organization'], 'suspended' => (bool) $u['suspended']);
  }
  private static function require_user() { $u = self::current_user(); if (!$u) self::fail(401, 'Please log in first'); return $u; }
  private static function require_organizer() {
    $u = self::require_user();
    if ($u['role'] !== 'organizer' && $u['role'] !== 'admin') self::fail(403, 'Only organizer accounts can do this');
    return $u;
  }
  private static function require_admin() {
    $u = self::require_user();
    if ($u['role'] !== 'admin') self::fail(403, 'Administrator access required');
    return $u;
  }
  /** Fresh wp_rest nonce for the session that was just started/ended in this request. */
  private static function fresh_nonce() { return wp_create_nonce('wp_rest'); }
  private static function capture_cookie() {
    add_action('set_logged_in_cookie', function ($cookie) { if (defined('LOGGED_IN_COOKIE')) $_COOKIE[LOGGED_IN_COOKIE] = $cookie; });
  }

  /** Creates a WP user. $role: user|organizer|admin. Returns Node-shaped user. */
  public static function create_wp_user($name, $email, $password, $role, $organization, $phone) {
    $login = sanitize_user($email, true);
    if ($login === '' || strlen($login) > 60 || username_exists($login)) {
      $base = substr(sanitize_user(strstr($email, '@', true), true), 0, 40);
      if ($base === '') $base = 'user';
      $login = $base;
      $i = 1;
      while (username_exists($login)) { $i++; $login = $base . $i; }
    }
    $parts = preg_split('/\s+/', trim($name));
    $last = count($parts) > 1 ? array_pop($parts) : '';
    $id = wp_insert_user(array(
      'user_login' => $login, 'user_email' => $email, 'user_pass' => $password, 'display_name' => $name, 'nickname' => $name,
      'first_name' => implode(' ', $parts), 'last_name' => $last, 'role' => TJ_Store::wp_role_for($role),
    ));
    if (is_wp_error($id)) self::fail(400, $id->get_error_message());
    update_user_meta($id, 'tj_phone', $phone);
    update_user_meta($id, 'tj_organization', $organization);
    update_user_meta($id, 'tj_suspended', 0);
    update_user_meta($id, 'tj_created_at', TJ_Store::now_iso());
    TJ_Store::flush_users();
    return TJ_Store::user_by_id($id);
  }

  // ---------- captcha ----------
  public static function captcha_public() {
    $c = self::settings()['captcha'];
    $on = !empty($c['enabled']) && !empty($c['siteKey']) && !empty($c['secretKey']);
    return array('siteKey' => $on ? $c['siteKey'] : '', 'register' => (bool) ($on && !empty($c['protectRegister'])), 'checkout' => (bool) ($on && !empty($c['protectCheckout'])), 'testKeys' => $c['siteKey'] === TJ_Store::RECAPTCHA_TEST_SITE);
  }
  /** Simple per-visitor speed limit for forms bots like to hammer (log in, sign up, uploads, checkout). */
  private static function rate_limit($rule, $max, $window) {
    $key = 'tj_rl_' . md5($rule . '|' . self::client_ip());
    $now = time();
    $hits = get_transient($key);
    $hits = is_array($hits) ? array_values(array_filter($hits, function ($t) use ($now, $window) { return $now - $t < $window; })) : array();
    if (count($hits) >= $max) self::fail(429, 'Too many attempts. Please wait ' . max(1, (int) ceil(($window - ($now - $hits[0])) / 60)) . ' minute(s) and try again.');
    $hits[] = $now;
    set_transient($key, $hits, $window);
  }
  /** Real image check: the file's first bytes must match its type (a renamed script or web page is refused). */
  private static function image_bytes_ok($buf, $type) {
    if ($type === 'png') return strlen($buf) > 8 && substr($buf, 0, 4) === "\x89PNG";
    if ($type === 'jpg' || $type === 'jpeg') return strlen($buf) > 3 && substr($buf, 0, 3) === "\xFF\xD8\xFF";
    if ($type === 'gif') return strlen($buf) > 6 && substr($buf, 0, 4) === 'GIF8';
    if ($type === 'webp') return strlen($buf) > 12 && substr($buf, 0, 4) === 'RIFF' && substr($buf, 8, 4) === 'WEBP';
    return false;
  }
  private static function client_ip() {
    $f = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]) : '';
    return $f !== '' ? $f : (isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '');
  }
  private static function require_captcha($body, $where) {
    $pub = self::captcha_public();
    if (empty($pub[$where])) return;
    $token = isset($body['captcha']) && is_string($body['captcha']) ? $body['captcha'] : '';
    if ($token === '' || strlen($token) > 4000) self::fail(400, 'Please tick "I\'m not a robot" to continue');
    $res = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', array(
      'timeout' => 8,
      'body' => array('secret' => self::settings()['captcha']['secretKey'], 'response' => $token, 'remoteip' => self::client_ip()),
    ));
    if (is_wp_error($res)) {
      // If Google can't be reached, don't block real customers — log it and continue.
      error_log('[tj captcha] verification service unreachable, allowing request: ' . $res->get_error_message());
      return;
    }
    $result = json_decode(wp_remote_retrieve_body($res), true);
    if (!is_array($result)) { error_log('[tj captcha] unreadable response, allowing request'); return; }
    if (empty($result['success'])) {
      $codes = isset($result['error-codes']) ? (array) $result['error-codes'] : array();
      if (in_array('invalid-input-secret', $codes, true)) error_log('[tj captcha] secret key rejected by Google — check Admin → Settings');
      self::fail(400, in_array('timeout-or-duplicate', $codes, true) ? 'The robot check expired. Please tick "I\'m not a robot" again.' : 'Robot check failed. Please try again.');
    }
  }

  // ---------- events ----------
  private static function is_public_tier($t) { return self::g($t, 'visibility') !== 'private'; }
  private static function geo_num($v) { return (is_int($v) || is_float($v)) && is_finite((float) $v) ? $v : null; }
  // ---------- ticket kinds: paid = fixed price · free = $0 · donation = buyer picks the amount (price = minimum) ----------
  public static function tier_kind($t) {
    $k = self::g($t, 'kind');
    if (in_array($k, array('paid', 'free', 'donation'), true)) return $k;
    return (float) $t['price'] > 0 ? 'paid' : 'free';
  }
  /** soon = sales not started · open = can be bought · closed = sales end has passed */
  public static function tier_sale_state($t) {
    $now = self::now_ms(); $s = self::g($t, 'salesStart'); $e = self::g($t, 'salesEnd');
    if ($s && TJ_Store::ts_ms($s) > $now) return 'soon';
    if ($e && TJ_Store::ts_ms($e) <= $now) return 'closed';
    return 'open';
  }
  public static function tier_on_sale($t) { return self::tier_sale_state($t) === 'open'; }
  /** Lowest open public price for "From $X" labels: priceFrom, priceKind, soldOut, salesStart (next opening when none on sale) */
  public static function lowest_price($tiers) {
    $best = null; $next = null; $avail = 0; $closed = 0;
    foreach ($tiers as $t) {
      if (self::g($t, 'visibility') === 'private' || $t['quantity'] - $t['sold'] <= 0) continue;
      $avail++;
      $st = self::tier_sale_state($t);
      if ($st === 'closed') { $closed++; continue; }
      if ($st === 'soon') { if ($next === null || strcmp($t['salesStart'], $next) < 0) $next = $t['salesStart']; continue; }
      if ($best === null || $t['price'] < $best['price'] || ($t['price'] == $best['price'] && self::tier_kind($t) === 'paid')) $best = $t;
    }
    return $best ? array('priceFrom' => $best['price'], 'priceKind' => self::tier_kind($best), 'soldOut' => false) : array('priceFrom' => null, 'priceKind' => null, 'soldOut' => $next === null && !($avail > 0 && $closed === $avail), 'salesStart' => $next, 'salesClosed' => $next === null && $avail > 0 && $closed === $avail);
  }

  /** Tickets of each type this person already bought for the event (refunded and complimentary orders don't count). */
  public static function bought_by($user, $event_id) {
    $out = array();
    if (!$user) return $out;
    foreach (TJ_Store::orders() as $o) {
      if ((int) $o['userId'] !== (int) $user['id'] || $o['eventId'] !== $event_id || $o['status'] === 'refunded' || !empty($o['comp'])) continue;
      foreach ($o['items'] as $i) $out[$i['tierId']] = (isset($out[$i['tierId']]) ? $out[$i['tierId']] : 0) + (int) $i['qty'];
    }
    return $out;
  }

  public static function public_event($ev, $viewer) {
    $org = TJ_Store::user_by_id($ev['organizerId']);
    $mine = self::bought_by($viewer, $ev['id']);
    $own = $viewer && ($viewer['id'] === (int) $ev['organizerId'] || $viewer['role'] === 'admin');
    $tiers = array();
    foreach ($ev['tiers'] as $t) {
      if (self::g($t, 'visibility') === 'private' && !$own) continue;
      $tiers[] = array('id' => $t['id'], 'name' => $t['name'], 'description' => (string) self::g($t, 'description', ''), 'visibility' => self::g($t, 'visibility') === 'private' ? 'private' : 'public',
        'kind' => self::tier_kind($t), 'price' => $t['price'], 'suggested' => self::g($t, 'suggested'), 'salesStart' => self::g($t, 'salesStart'), 'salesEnd' => self::g($t, 'salesEnd'), 'saleState' => self::tier_sale_state($t), 'onSale' => self::tier_on_sale($t), 'maxPerPerson' => self::g($t, 'maxPerPerson') ? (int) $t['maxPerPerson'] : null, 'boughtByYou' => isset($mine[$t['id']]) ? $mine[$t['id']] : 0, 'remaining' => max(0, $t['quantity'] - $t['sold']), 'fee' => self::n(self::ticket_fee($t['price'])));
    }
    return array(
      'id' => $ev['id'], 'title' => $ev['title'], 'category' => $ev['category'], 'description' => $ev['description'],
      'startsAt' => $ev['startsAt'], 'endsAt' => $ev['endsAt'], 'venue' => $ev['venue'], 'address' => $ev['address'], 'city' => $ev['city'],
      'imageUrl' => self::g($ev, 'imageUrl', ''), 'imageShareUrl' => (string) self::g($ev, 'imageShareUrl', ''), 'status' => $ev['status'],
      'lat' => self::geo_num(self::g($ev, 'lat')), 'lng' => self::geo_num(self::g($ev, 'lng')), 'geoPrecision' => self::g($ev, 'geoPrecision') ? $ev['geoPrecision'] : null,
      'organizer' => (!empty($ev['organizerName']) ? $ev['organizerName'] : ($org ? ($org['organization'] ? $org['organization'] : $org['name']) : 'Unknown')),
      'organizerName' => (!empty($ev['organizerName']) ? $ev['organizerName'] : ($org ? ($org['organization'] ? $org['organization'] : $org['name']) : '')),
      'organizers' => TJ_Directory::event_organizers($ev), 'extraVenues' => is_array(self::g($ev, 'extraVenues')) ? $ev['extraVenues'] : array(),
      'schedule' => self::g($ev, 'schedule') ? $ev['schedule'] : 'single', 'seriesId' => self::g($ev, 'seriesId') ? $ev['seriesId'] : null,
      'sessionLabel' => (string) self::g($ev, 'sessionLabel', ''), 'series' => self::g($ev, 'seriesId') ? self::series_dates($ev, $viewer) : array(),
      'isOwner' => (bool) $own,
      'tiers' => $tiers,
    );
  }

  /** Every date/time in a recurring series (owners and admins also see drafts and cancelled dates). */
  public static function series_dates($ev, $viewer) {
    $own = $viewer && ($viewer['id'] === (int) $ev['organizerId'] || $viewer['role'] === 'admin');
    $out = array();
    foreach (TJ_Store::events() as $e) {
      if (self::g($e, 'seriesId') !== $ev['seriesId']) continue;
      if (!$own && $e['status'] !== 'published') continue;
      $sold_out = true;
      foreach ($e['tiers'] as $t) { if (self::g($t, 'visibility') === 'private') continue; if ($t['quantity'] - $t['sold'] > 0) { $sold_out = false; break; } }
      $out[] = array('id' => $e['id'], 'startsAt' => $e['startsAt'], 'endsAt' => $e['endsAt'], 'status' => $e['status'], 'sessionLabel' => (string) self::g($e, 'sessionLabel', ''), 'soldOut' => $sold_out);
    }
    self::sort_starts($out);
    return $out;
  }

  private static function clean_image_url($v) {
    $s = self::str($v, 'Image URL', 500);
    if ($s === '') return '';
    if (!preg_match('~^(/uploads/[\w.-]+|https?://[^\s"\'()\\\\<>]+)$~', $s)) self::fail(400, 'Image URL is not valid');
    return $s;
  }

  public static function validate_event($b, $existing) {
    $title = self::str(self::g($b, 'title'), 'Title', 120, true);
    $category = self::g($b, 'category');
    if (!is_string($category) || !in_array($category, TJ_Store::$CATEGORIES, true)) self::fail(400, 'Choose a valid category');
    $description = self::str(self::g($b, 'description'), 'Description', 5000, true);
    $start = TJ_Store::ts_ms(self::g($b, 'startsAt'));
    if (is_nan($start)) self::fail(400, 'Start date/time is required');
    $end = self::g($b, 'endsAt') ? TJ_Store::ts_ms(self::g($b, 'endsAt')) : $start + 3 * 3600000;
    if (is_nan($end) || $end < $start) self::fail(400, 'End time must be after the start time');
    $orgIn = is_array(self::g($b, 'organizers')) && count($b['organizers']) ? $b['organizers'] : array(self::g($b, 'organizerName'));
    $organizers = array();
    foreach (array_slice(array_values($orgIn), 0, 20) as $raw) {
      $nm = preg_replace('/\s+/', ' ', trim(is_scalar($raw) ? (string) $raw : ''));
      if ($nm === '') continue;
      if (self::len($nm) > 100) self::fail(400, 'Each organizer name must be 100 characters or less');
      $dup = false; foreach ($organizers as $x) if (TJ_Directory::norm($x) === TJ_Directory::norm($nm)) $dup = true;
      if (!$dup) $organizers[] = $nm;
    }
    if (!count($organizers)) self::fail(400, 'Organizer is required');
    if (count($organizers) > 10) self::fail(400, 'An event can have at most 10 organizers');
    $organizerName = TJ_Directory::join_names($organizers);
    $extraVenues = array();
    $xvIn = self::g($b, 'extraVenues');
    if (is_array($xvIn)) {
      if (count($xvIn) > 9) self::fail(400, 'An event can have at most 10 venues');
      foreach (array_values($xvIn) as $i => $xv) {
        $xv = is_array($xv) ? $xv : array();
        $xn = self::str(self::g($xv, 'name'), 'Venue #' . ($i + 2) . ' name', 120, true);
        $xa = self::str(self::g($xv, 'address'), 'Venue #' . ($i + 2) . ' address', 200);
        $xc = self::str(self::g($xv, 'city'), 'Venue #' . ($i + 2) . ' city', 80);
        $xlat = self::g($xv, 'lat'); $xlng = self::g($xv, 'lng');
        $ok = $xlat !== null && $xlng !== null && is_numeric($xlat) && is_numeric($xlng) && TJ_Geo::valid_lat_lng((float) $xlat, (float) $xlng);
        $extraVenues[] = array('name' => $xn, 'address' => $xa, 'city' => $xc, 'lat' => $ok ? round((float) $xlat, 6) : null, 'lng' => $ok ? round((float) $xlng, 6) : null);
      }
    }
    $schedule = in_array(self::g($b, 'schedule'), array('single', 'multi', 'series'), true) ? $b['schedule'] : 'single';
    $sessionLabel = self::str(self::g($b, 'sessionLabel'), 'Session name', 60);
    $venue = self::str(self::g($b, 'venue'), 'Venue', 120, true);
    $address = self::str(self::g($b, 'address'), 'Address', 200);
    $city = self::str(self::g($b, 'city'), 'City', 80, true);
    $imageUrl = self::clean_image_url(self::g($b, 'imageUrl'));
    $imageShareUrl = $imageUrl ? self::clean_image_url(self::g($b, 'imageShareUrl')) : '';
    $allowed = $existing ? array('published', 'draft', 'cancelled') : array('published', 'draft');
    $status = in_array(self::g($b, 'status'), $allowed, true) ? $b['status'] : 'draft';

    $bt = self::g($b, 'tiers');
    if (!is_array($bt) || !wp_is_numeric_array($bt) || count($bt) < 1) self::fail(400, 'Add at least one ticket type');
    if (count($bt) > 10) self::fail(400, 'Maximum 10 ticket types');
    $oldTiers = $existing ? $existing['tiers'] : array();
    $tiers = array();
    foreach (array_values($bt) as $i => $t) {
      if (!is_array($t)) $t = array();
      $name = self::str(self::g($t, 'name'), 'Ticket type #' . ($i + 1) . ' name', 60, true);
      $kind = in_array(self::g($t, 'kind'), array('paid', 'free', 'donation'), true) ? $t['kind'] : (self::numk($t, 'price') > 0 ? 'paid' : 'free');
      $praw = self::g($t, 'price');
      $price = $kind === 'free' ? 0 : self::round2(($praw === null || $praw === '') ? 0 : self::numk($t, 'price'));
      if (!self::finite($price) || $price < 0 || $price > 10000) self::fail(400, ($kind === 'donation' ? 'Minimum donation' : 'Price') . ' for "' . $name . '" must be between 0 and 10,000');
      if ($kind === 'paid' && $price <= 0) self::fail(400, '"' . $name . '" is a paid ticket, so it needs a price above $0. Choose "Free" for free tickets.');
      $suggested = null;
      $sraw = self::g($t, 'suggested');
      if ($kind === 'donation' && $sraw !== null && trim((string) $sraw) !== '') {
        $suggested = self::round2(self::numk($t, 'suggested'));
        if (!self::finite($suggested) || $suggested < $price || $suggested > 10000) self::fail(400, 'Suggested donation for "' . $name . '" must be at least the minimum (' . TJ_Notify::money($price) . ') and at most 10,000');
      }
      $quantity = self::numk($t, 'quantity');
      if (!self::is_integer_num($quantity) || $quantity < 1 || $quantity > 100000) self::fail(400, 'Quantity for "' . $name . '" must be a whole number from 1 to 100,000');
      $old = null;
      if (self::g($t, 'id')) foreach ($oldTiers as $o) if ($o['id'] === $t['id']) { $old = $o; break; }
      if ($old && $quantity < $old['sold']) self::fail(400, '"' . $name . '" already sold ' . $old['sold'] . '; quantity can\'t be lower');
      if ($old && $old['sold'] - (int) self::g($old, 'comped', 0) > 0 && $kind !== self::tier_kind($old)) self::fail(400, '"' . $name . '" has sales; it can\'t be changed between paid, free and donation');
      if ($old && $old['sold'] - (int) self::g($old, 'comped', 0) > 0 && (float) $price !== (float) $old['price']) self::fail(400, '"' . $name . '" has sales; its ' . ($kind === 'donation' ? 'minimum donation' : 'price') . ' can\'t change');
      $tdesc = self::str(self::g($t, 'description'), 'Description for "' . $name . '"', 300);
      // optional: when this ticket type goes on sale (a countdown shows until then)
      $salesStart = null;
      $ssraw = self::g($t, 'salesStart');
      if ($ssraw !== null && is_scalar($ssraw) && trim((string) $ssraw) !== '') {
        $ssms = TJ_Store::ts_ms((string) $ssraw);
        if (!$ssms) self::fail(400, 'Sales start for "' . $name . '" is not a valid date and time');
        if ($ssms >= TJ_Store::ts_ms(self::iso_ms($end))) self::fail(400, 'Sales for "' . $name . '" must start before the event ends');
        $salesStart = self::iso_ms($ssms);
      }
      // optional: when this ticket type stops selling (a countdown shows while it is on sale)
      $salesEnd = null;
      $seraw = self::g($t, 'salesEnd');
      if ($seraw !== null && is_scalar($seraw) && trim((string) $seraw) !== '') {
        $sems = TJ_Store::ts_ms((string) $seraw);
        if (!$sems) self::fail(400, 'Sales end for "' . $name . '" is not a valid date and time');
        if ($salesStart && $sems <= TJ_Store::ts_ms($salesStart)) self::fail(400, 'Sales end for "' . $name . '" must be after its sales start');
        if ($sems > TJ_Store::ts_ms(self::iso_ms($end))) self::fail(400, 'Sales for "' . $name . '" must end by the time the event ends');
        $salesEnd = self::iso_ms($sems);
      }
      // optional: most tickets of this type one person (account) can buy, across all their orders
      $maxPerPerson = null;
      $mraw = self::g($t, 'maxPerPerson');
      if ($mraw !== null && is_scalar($mraw) && trim((string) $mraw) !== '') {
        $mv = self::numk($t, 'maxPerPerson');
        if (!self::is_integer_num($mv) || $mv < 1 || $mv > 100) self::fail(400, 'Max per person for "' . $name . '" must be a whole number from 1 to 100');
        $maxPerPerson = (int) $mv;
      }
      $tiers[] = array(
        'id' => $old ? $old['id'] : TJ_Store::new_id('tier'), 'name' => $name, 'description' => $tdesc,
        'visibility' => self::g($t, 'visibility') === 'private' ? 'private' : 'public', 'kind' => $kind, 'price' => self::n($price), 'suggested' => $kind === 'donation' && $suggested !== null ? self::n($suggested) : null, 'salesStart' => $salesStart, 'salesEnd' => $salesEnd, 'maxPerPerson' => $maxPerPerson, 'quantity' => (int) $quantity,
        'sold' => $old ? (int) $old['sold'] : 0, 'comped' => $old ? (int) self::g($old, 'comped', 0) : 0,
      );
    }
    foreach ($oldTiers as $o) {
      if ($o['sold'] > 0) {
        $kept = false;
        foreach ($tiers as $t) if ($t['id'] === $o['id']) $kept = true;
        if (!$kept) self::fail(400, '"' . $o['name'] . '" has sales and can\'t be removed');
      }
    }
    $bl = self::g($b, 'lat'); $bg = self::g($b, 'lng');
    $lat = $bl === null || $bl === '' ? NAN : self::num($bl);
    $lng = $bg === null || $bg === '' ? NAN : self::num($bg);
    if (TJ_Geo::valid_lat_lng($lat, $lng)) {
      $gp = self::g($b, 'geoPrecision');
      $geo = array('lat' => round($lat * 1e6) / 1e6, 'lng' => round($lng * 1e6) / 1e6, 'geoPrecision' => $gp === 'pin' ? 'pin' : (in_array($gp, array('venue', 'address', 'city'), true) ? $gp : 'pin'));
    } else $geo = array('lat' => null, 'lng' => null, 'geoPrecision' => null);
    return array_merge(array(
      'title' => $title, 'category' => $category, 'description' => $description, 'startsAt' => self::iso_ms($start), 'endsAt' => self::iso_ms($end),
      'organizerName' => $organizerName, 'organizers' => $organizers, 'extraVenues' => $extraVenues, 'schedule' => $schedule, 'sessionLabel' => $sessionLabel, 'venue' => $venue, 'address' => $address, 'city' => $city, 'imageUrl' => $imageUrl, 'imageShareUrl' => $imageShareUrl, 'status' => $status, 'tiers' => $tiers,
    ), $geo);
  }

  /** Looks up map coordinates for an event that has none (never overrides an organizer's pin). */
  public static function ensure_geo($ev, $budget = 10) {
    if (TJ_Geo::valid_lat_lng(self::g($ev, 'lat'), self::g($ev, 'lng'))) return $ev;
    $s = self::settings();
    $hit = TJ_Geo::geocode_place(array('venue' => $ev['venue'], 'address' => $ev['address'], 'city' => $ev['city'], 'country' => $s['maps']['country']), $budget ? microtime(true) + $budget : null);
    TJ_Store::flush_cache();
    $live = TJ_Store::get_event($ev['id']);
    if ($hit && $live && !TJ_Geo::valid_lat_lng(self::g($live, 'lat'), self::g($live, 'lng'))) {
      $live['lat'] = round($hit['lat'] * 1e6) / 1e6;
      $live['lng'] = round($hit['lng'] * 1e6) / 1e6;
      $live['geoPrecision'] = $hit['precision'];
      TJ_Store::save_event($live);
      return $live;
    }
    return $live ? $live : $ev;
  }
  public static function locate_missing() {
    foreach (TJ_Store::events() as $e) if (!TJ_Geo::valid_lat_lng(self::g($e, 'lat'), self::g($e, 'lng'))) self::ensure_geo($e);
  }

  private static function owned_event($user, $id) {
    $ev = TJ_Store::get_event((string) $id);
    if (!$ev || ($ev['organizerId'] !== $user['id'] && $user['role'] !== 'admin')) self::fail(404, 'Event not found');
    return $ev;
  }

  public static function event_stats($ev) {
    $orders = array();
    foreach (TJ_Store::orders() as $o) if ($o['eventId'] === $ev['id'] && $o['status'] !== 'refunded' && empty($o['test'])) $orders[] = $o;
    $sold = 0; $capacity = 0; $comped = 0; $revenue = 0;
    foreach ($ev['tiers'] as $t) {
      $c = (int) self::g($t, 'comped', 0);
      $sold += $t['sold']; $capacity += $t['quantity']; $comped += $c;
      if (self::tier_kind($t) === 'donation') {
        foreach ($orders as $o) { if (!empty($o['comp'])) continue; foreach ($o['items'] as $i) if ($i['tierId'] === $t['id']) $revenue += $i['price'] * $i['qty']; }
      } else $revenue += max(0, $t['sold'] - $c) * $t['price'];
    }
    $checked = 0; $paidOrders = 0; $compOrders = 0;
    foreach ($orders as $o) {
      foreach ($o['tickets'] as $t) if (!empty($t['checkedIn'])) $checked++;
      if (!empty($o['comp'])) $compOrders++; else $paidOrders++;
    }
    return array('sold' => $sold, 'capacity' => $capacity, 'comped' => $comped, 'paidSold' => $sold - $comped, 'revenue' => self::n(self::round2($revenue)),
      'orders' => $paidOrders, 'compOrders' => $compOrders, 'checkedIn' => $checked, 'shareViews' => (int) self::g($ev, 'shareViews', 0));
  }

  /** First/last name for any order (older orders only stored a full name) */
  public static function split_name($o) {
    if (self::g($o, 'buyerFirstName') || self::g($o, 'buyerLastName')) return array('buyerFirstName' => (string) self::g($o, 'buyerFirstName', ''), 'buyerLastName' => (string) self::g($o, 'buyerLastName', ''));
    $p = preg_split('/\s+/', trim((string) self::g($o, 'buyerName', '')));
    $n = count($p);
    return array('buyerFirstName' => $n > 1 ? implode(' ', array_slice($p, 0, -1)) : (string) $p[0], 'buyerLastName' => $n > 1 ? $p[$n - 1] : '');
  }
  public static function order_view($o) {
    $ev = TJ_Store::get_event($o['eventId']);
    unset($o['cardFingerprint']);
    $o['event'] = $ev ? array('id' => $ev['id'], 'title' => $ev['title'], 'startsAt' => $ev['startsAt'], 'venue' => $ev['venue'], 'address' => $ev['address'], 'city' => $ev['city'], 'imageUrl' => self::g($ev, 'imageUrl', ''), 'category' => $ev['category'], 'status' => $ev['status']) : null;
    return $o;
  }

  // ---------- card helpers ----------
  public static function luhn_ok($num) {
    $sum = 0; $alt = false;
    for ($i = strlen($num) - 1; $i >= 0; $i--) {
      $d = ord($num[$i]) - 48;
      if ($alt) { $d *= 2; if ($d > 9) $d -= 9; }
      $sum += $d; $alt = !$alt;
    }
    return $sum % 10 === 0;
  }
  public static function card_brand($num) {
    if (preg_match('/^4/', $num)) return 'Visa';
    if (preg_match('/^(5[1-5]|2(2[2-9][1-9]|2[3-9]\d|[3-6]\d\d|7[01]\d|720))/', $num)) return 'Mastercard';
    if (preg_match('/^3[47]/', $num)) return 'Amex';
    if (preg_match('/^(6011|65|64[4-9])/', $num)) return 'Discover';
    if (preg_match('/^35(2[89]|[3-8])/', $num)) return 'JCB';
    if (preg_match('/^3(0[0-5]|[68])/', $num)) return 'Diners';
    return 'Card';
  }
  /** Same card → same fingerprint, but the number can't be recovered from it. */
  public static function card_info($num, $holder) {
    return array('cardLast4' => substr($num, -4), 'cardBrand' => self::card_brand($num), 'cardHolder' => $holder ? $holder : '',
      'cardFingerprint' => 'fp_' . substr(hash_hmac('sha256', $num, self::settings()['cardHashKey']), 0, 20));
  }

  private static function admin_user($u) {
    $orders = array();
    foreach (self::paid_orders() as $o) if ($o['userId'] === $u['id']) $orders[] = $o;
    $tickets = 0; $spent = 0;
    foreach ($orders as $o) { $tickets += count($o['tickets']); $spent += $o['total']; }
    $events = 0;
    foreach (TJ_Store::events() as $e) if ($e['organizerId'] === $u['id']) $events++;
    return array_merge(self::public_user($u), array('createdAt' => $u['createdAt'], 'lastLoginAt' => $u['lastLoginAt'] ? $u['lastLoginAt'] : null,
      'orders' => count($orders), 'ticketsBought' => $tickets, 'spent' => self::n(self::round2($spent)), 'events' => $events));
  }
  private static function admin_order($o) {
    $u = $o['userId'] ? TJ_Store::user_by_id($o['userId']) : null;
    return array_merge(self::order_view($o), array('account' => $u ? array('id' => $u['id'], 'name' => $u['name'], 'email' => $u['email']) : null));
  }
  private static function admin_event($e) {
    $p = TJ_Store::user_by_id($e['organizerId']);
    return array_merge(self::public_event($e, null), array('organizerId' => $e['organizerId'], 'organizerEmail' => $p ? $p['email'] : '', 'stats' => self::event_stats($e), 'createdAt' => self::g($e, 'createdAt')));
  }
  private static function paid_orders() {
    $out = array();
    foreach (TJ_Store::orders() as $o) if ($o['status'] !== 'refunded' && empty($o['test'])) $out[] = $o;
    return $out;
  }
  private static function sort_desc_created(&$list) { usort($list, function ($a, $b) { return strcmp((string) $b['createdAt'], (string) $a['createdAt']); }); }
  private static function sort_starts(&$list, $desc = false) {
    usort($list, function ($a, $b) use ($desc) { $x = TJ_Store::ts_ms($a['startsAt']); $y = TJ_Store::ts_ms($b['startsAt']); $r = $x == $y ? 0 : ($x < $y ? -1 : 1); return $desc ? -$r : $r; });
  }
  private static function kill_sessions($user_id, $keep_token = null) {
    if (!class_exists('WP_Session_Tokens')) return;
    $m = WP_Session_Tokens::get_instance($user_id);
    if ($keep_token) $m->destroy_others($keep_token); else $m->destroy_all();
  }

  private static function send_binary($mime, $buf, $filename = null, $cache = 'no-store') {
    while (ob_get_level() > 0) @ob_end_clean();
    status_header(200);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($buf));
    if ($filename) header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: ' . $cache);
    header('X-Content-Type-Options: nosniff');
    echo $buf; // binary payload
    exit;
  }

  // =====================================================================
  // routes: meta, weather, geocode
  // =====================================================================
  public static function r_meta($c) {
    $s = self::settings();
    $m = $s['maps'];
    return array(
      'categories' => TJ_Store::$CATEGORIES, 'feePercent' => $s['feePercent'], 'feeFixed' => $s['feeFixed'],
      'maps' => array('googleMapsKey' => $m['googleMapsKey'], 'center' => array('lat' => $m['weatherLat'], 'lng' => $m['weatherLng']), 'country' => (string) self::g($m, 'country', '')),
      'captcha' => self::captcha_public(),
      'preloader' => $s['preloader'],
      'security' => array('idleMinutes' => (int) $s['security']['idleMinutes'], 'allowReset' => (bool) $s['security']['allowReset'], 'minPassword' => (int) $s['security']['minPassword']),
      'weatherPlace' => array('name' => $m['weatherPlace'], 'lat' => $m['weatherLat'], 'lng' => $m['weatherLng']),
    );
  }

  public static function r_weather($c) {
    $lat = self::num(self::q($c, 'lat')); $lng = self::num(self::q($c, 'lng'));
    if (!TJ_Geo::valid_lat_lng($lat, $lng)) self::fail(400, 'Invalid location');
    try { return array('forecast' => TJ_Geo::forecast($lat, $lng)); }
    catch (Exception $e) { self::fail(503, 'Weather is temporarily unavailable'); }
  }

  public static function r_geocode($c) {
    self::require_organizer();
    $s = self::settings();
    $hit = TJ_Geo::geocode_place(array('venue' => (string) self::q($c, 'venue'), 'address' => (string) self::q($c, 'address'), 'city' => (string) self::q($c, 'city'), 'country' => $s['maps']['country']));
    if (!$hit) self::fail(404, "Couldn't find that address — drag the pin to the venue instead");
    return array('result' => $hit);
  }

  // =====================================================================
  // auth
  // =====================================================================
  public static function r_register($c) {
    self::rate_limit('register', 10, HOUR_IN_SECONDS);
    $b = $c['body'];
    self::require_captcha($b, 'register');
    $name = self::str(self::g($b, 'name'), 'Name', 80, true);
    $email = strtolower(self::str(self::g($b, 'email'), 'Email', 160, true));
    if (!self::valid_email($email) || !is_email($email)) self::fail(400, 'Enter a valid email address');
    $password = is_string(self::g($b, 'password')) ? $b['password'] : '';
    self::check_password($password);
    $role = in_array(self::g($b, 'role'), array('organizer', 'promoter'), true) ? 'organizer' : 'user';
    $organization = self::str(self::g($b, 'organization'), 'Organizer name', 100, $role === 'organizer');
    if (TJ_Store::user_by_email($email) || email_exists($email)) self::fail(409, 'An account with this email already exists');
    $phone = TJ_Notify::normalize_phone(self::g($b, 'phone'));
    if ($phone === null) self::fail(400, 'Enter a valid mobile number, e.g. 242-555-1234 or +1 242 555 1234');
    if (!$phone) self::fail(400, 'Mobile number is required');
    $user = self::create_wp_user($name, $email, $password, $role, $organization, $phone);
    // Complimentary tickets sent to this email before the person had an account now appear in their dashboard.
    TJ_Store::link_orders_by_email($email, $user['id']);
    self::capture_cookie();
    wp_set_current_user($user['id']);
    wp_set_auth_cookie($user['id'], true);
    do_action('wp_login', get_userdata($user['id'])->user_login, get_userdata($user['id']));
    TJ_Notify::registered($user);
    return array('user' => self::public_user($user), 'nonce' => self::fresh_nonce());
  }

  public static function r_login($c) {
    self::rate_limit('login', 20, 10 * MINUTE_IN_SECONDS);
    $b = $c['body'];
    $email = strtolower(self::str(self::g($b, 'email'), 'Email', 160, true));
    $user = TJ_Store::user_by_email($email);
    $wpu = $user ? get_userdata($user['id']) : null;
    $pw = self::g($b, 'password');
    $pw = is_string($pw) || is_numeric($pw) ? (string) $pw : '';
    $sec = self::settings()['security'];
    $lk = 'tj_lf_' . md5($email);
    $lf = get_transient($lk);
    $lock_msg = function ($until) use ($sec) { return 'Too many wrong tries. Please wait ' . max(1, (int) ceil(($until - time()) / 60)) . ' minute(s) and try again' . (!empty($sec['allowReset']) ? ', or reset your password' : '') . '.'; };
    if (!empty($sec['maxAttempts']) && is_array($lf) && !empty($lf['until']) && $lf['until'] > time()) self::fail(429, $lock_msg($lf['until']));
    if (!$wpu || $pw === '' || !wp_check_password($pw, $wpu->user_pass, $wpu->ID)) {
      $max = (int) $sec['maxAttempts'];
      if ($max > 0) {
        $lf = is_array($lf) && empty($lf['until']) ? $lf : array('count' => 0, 'until' => 0);
        $lf['count']++;
        if ($lf['count'] >= $max) {
          $lf['until'] = time() + (int) $sec['lockMinutes'] * 60;
          set_transient($lk, $lf, (int) $sec['lockMinutes'] * 60);
          self::fail(429, $lock_msg($lf['until']));
        }
        set_transient($lk, $lf, DAY_IN_SECONDS);
        $left = $max - $lf['count'];
        if ($left <= 2) self::fail(401, 'Incorrect email or password (' . $left . ($left === 1 ? ' try' : ' tries') . ' left)');
      }
      self::fail(401, 'Incorrect email or password');
    }
    delete_transient($lk);
    if ($user['suspended']) self::fail(403, 'This account has been suspended. Please contact support.');
    self::capture_cookie();
    $signed = wp_signon(array('user_login' => $wpu->user_login, 'user_password' => $pw, 'remember' => true), is_ssl());
    if (is_wp_error($signed)) self::fail(401, 'Incorrect email or password');
    wp_set_current_user($signed->ID);
    update_user_meta($wpu->ID, 'tj_last_login', TJ_Store::now_iso());
    TJ_Store::link_orders_by_email($email, $wpu->ID);
    return array('user' => self::public_user(TJ_Store::user_by_id($wpu->ID)), 'nonce' => self::fresh_nonce());
  }

  public static function r_logout($c) {
    $uid = get_current_user_id();
    // Without a valid REST nonce the current user is 0 — still end the session held in the cookie.
    if (!$uid && defined('LOGGED_IN_COOKIE') && !empty($_COOKIE[LOGGED_IN_COOKIE])) {
      $cookie = wp_parse_auth_cookie($_COOKIE[LOGGED_IN_COOKIE], 'logged_in');
      $vid = wp_validate_auth_cookie($_COOKIE[LOGGED_IN_COOKIE], 'logged_in');
      if ($vid && $cookie && !empty($cookie['token'])) WP_Session_Tokens::get_instance($vid)->destroy($cookie['token']);
    }
    if ($uid) wp_logout(); else wp_clear_auth_cookie();
    wp_set_current_user(0);
    if (defined('LOGGED_IN_COOKIE')) unset($_COOKIE[LOGGED_IN_COOKIE]);
    return array('ok' => true, 'nonce' => self::fresh_nonce());
  }

  public static function r_me($c) { return array('user' => self::public_user(self::current_user())); }

  // =====================================================================
  // events
  // =====================================================================
  public static function r_events($c) {
    $viewer = self::current_user();
    $q = strtolower(trim((string) self::q($c, 'q')));
    $category = (string) self::q($c, 'category');
    $city = strtolower(trim((string) self::q($c, 'city')));
    $filters = self::search_filters($c);
    $now = self::now_ms();
    $list = array();
    foreach (TJ_Store::events() as $e) {
      if ($e['status'] !== 'published' || !(TJ_Store::ts_ms($e['endsAt']) > $now)) continue;
      if ($category !== '' && $e['category'] !== $category) continue;
      if (!self::matches_filters($e, $filters)) continue;
      if ($city !== '' && strpos(strtolower($e['city']), $city) === false) continue;
      if ($q !== '') {
        $p = TJ_Store::user_by_id($e['organizerId']);
        $hay = strtolower(implode(' ', array($e['title'], $e['description'], implode(' ', TJ_Directory::event_venue_names($e)), $e['city'], $e['category'], isset($e['organizerName']) ? $e['organizerName'] : '', $p ? $p['organization'] : '', $p ? $p['name'] : '')));
        if (strpos($hay, $q) === false) continue;
      }
      $list[] = $e;
    }
    self::sort_starts($list);
    $out = array(); $pos = array();
    foreach ($list as $e) {
      $sid = self::g($e, 'seriesId');
      if ($sid) {
        if (isset($pos[$sid])) { $out[$pos[$sid]]['moreDates']++; continue; }
        $pe = self::public_event($e, $viewer); $pe['moreDates'] = 0;
        $pos[$sid] = count($out); $out[] = $pe;
      } else $out[] = self::public_event($e, $viewer);
    }
    return array('events' => $out);
  }

  // ---------- homepage search filters (organizer, venue, date, year, time of day) ----------
  private static function site_parts($iso_or_ms) {
    $ms = is_numeric($iso_or_ms) ? (float) $iso_or_ms : TJ_Store::ts_ms($iso_or_ms);
    $d = new DateTime('@' . (int) floor($ms / 1000));
    $d->setTimezone(wp_timezone());
    return array('date' => $d->format('Y-m-d'), 'year' => $d->format('Y'), 'hour' => (int) $d->format('G'));
  }
  private static function time_slot_ok($slot, $h) {
    if ($slot === 'morning') return $h >= 5 && $h < 12;
    if ($slot === 'afternoon') return $h >= 12 && $h < 17;
    if ($slot === 'evening') return $h >= 17 && $h < 21;
    if ($slot === 'night') return $h >= 21 || $h < 5;
    return true;
  }
  private static function search_filters($c) {
    $date = (string) self::q($c, 'date'); $year = (string) self::q($c, 'year'); $time = (string) self::q($c, 'time');
    return array(
      'organizer' => TJ_Directory::norm(self::q($c, 'organizer')), 'venue' => TJ_Directory::norm(self::q($c, 'venue')),
      'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '',
      'year' => preg_match('/^\d{4}$/', $year) ? $year : '',
      'time' => in_array($time, array('morning', 'afternoon', 'evening', 'night'), true) ? $time : '',
    );
  }
  private static function matches_filters($e, $f) {
    if ($f['organizer'] !== '') { $ok = false; foreach (TJ_Directory::event_organizers($e) as $n) if (TJ_Directory::norm($n) === $f['organizer']) $ok = true; if (!$ok) return false; }
    if ($f['venue'] !== '') { $ok = false; foreach (TJ_Directory::event_venue_names($e) as $n) if (TJ_Directory::norm($n) === $f['venue']) $ok = true; if (!$ok) return false; }
    if ($f['date'] !== '' || $f['year'] !== '' || $f['time'] !== '') {
      $s = self::site_parts($e['startsAt']);
      if ($f['year'] !== '' && $s['year'] !== $f['year']) return false;
      if ($f['time'] !== '' && !self::time_slot_ok($f['time'], $s['hour'])) return false;
      if ($f['date'] !== '') { $end = self::site_parts(TJ_Store::ts_ms($e['endsAt']) - 1000); if ($f['date'] < $s['date'] || $f['date'] > $end['date']) return false; }
    }
    return true;
  }
  /** Calendar of events: published events on the days they take place (site time zone).
   *  An overnight party that ends before 6 AM only counts on the day it starts. */
  private static function short_text($s, $n = 170) {
    $t = trim(preg_replace('/\s+/u', ' ', (string) $s));
    if (function_exists('mb_strlen') ? mb_strlen($t) <= $n : strlen($t) <= $n) return $t;
    $cut = function_exists('mb_substr') ? mb_substr($t, 0, $n) : substr($t, 0, $n);
    return preg_replace('/\s+\S*$/u', '', $cut) . '…';
  }
  public static function r_calendar($c) {
    $viewer = self::current_user();
    $today = wp_date('Y-m-d');
    $month = (string) self::q($c, 'month');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = substr($today, 0, 7);
    $now = self::now_ms();
    $out = array();
    foreach (TJ_Store::events() as $e) {
      if ($e['status'] !== 'published') continue;
      $start = self::site_parts($e['startsAt']); $start = $start['date'];
      $end = self::site_parts(TJ_Store::ts_ms($e['endsAt']) - 6 * 3600 * 1000); $end = $end['date'];
      if ($end < $start) $end = $start;
      $days = array(); $d = new DateTime($start . ' 00:00:00', new DateTimeZone('UTC'));
      for ($i = 0; $i < 62; $i++) { $k = $d->format('Y-m-d'); if ($k > $end) break; if (substr($k, 0, 7) === $month) $days[] = $k; $d->modify('+1 day'); }
      if (!$days) continue;
      $out[] = array(
        'id' => $e['id'], 'title' => $e['title'], 'startsAt' => $e['startsAt'], 'endsAt' => $e['endsAt'],
        'venue' => (string) self::g($e, 'venue'), 'category' => (string) self::g($e, 'category'), 'sessionLabel' => (string) self::g($e, 'sessionLabel'),
        'city' => (string) self::g($e, 'city', ''), 'address' => (string) self::g($e, 'address', ''),
        'lat' => is_numeric(self::g($e, 'lat')) ? (float) $e['lat'] : null, 'lng' => is_numeric(self::g($e, 'lng')) ? (float) $e['lng'] : null,
        'time' => wp_date('g:i A', (int) floor(TJ_Store::ts_ms($e['startsAt']) / 1000)), 'endTime' => wp_date('g:i A', (int) floor(TJ_Store::ts_ms($e['endsAt']) / 1000)),
        'startDay' => $start, 'days' => $days, 'ended' => TJ_Store::ts_ms($e['endsAt']) < $now,
      );
      $pe = self::public_event($e, $viewer);
      $lp = self::lowest_price($e['tiers']);
      $i = count($out) - 1;
      $out[$i]['city'] = (string) self::g($e, 'city'); $out[$i]['imageUrl'] = (string) self::g($e, 'imageUrl');
      $out[$i]['summary'] = self::short_text(self::g($e, 'description')); $out[$i]['organizer'] = $pe['organizer'];
      $out[$i]['priceFrom'] = $lp['priceFrom']; $out[$i]['priceKind'] = $lp['priceKind']; $out[$i]['soldOut'] = $lp['soldOut']; $out[$i]['salesStart'] = isset($lp['salesStart']) ? $lp['salesStart'] : null; $out[$i]['salesClosed'] = !empty($lp['salesClosed']);
    }
    usort($out, function ($a, $b) { return TJ_Store::ts_ms($a['startsAt']) <=> TJ_Store::ts_ms($b['startsAt']); });
    $s = self::settings();
    $hol = TJ_Geo::public_holidays(isset($s['maps']['country']) ? $s['maps']['country'] : 'Bahamas', (int) substr($month, 0, 4));
    $hols = array(); foreach ($hol['holidays'] as $h) if (strpos($h['date'], $month) === 0) $hols[] = $h;
    return array('month' => $month, 'today' => $today, 'events' => $out, 'holidayCountry' => $hol['country'], 'holidays' => $hols);
  }

  /** Choices for the search dropdowns, from events people can buy tickets for right now. */
  public static function r_search_options($c) {
    $now = self::now_ms();
    $orgs = array(); $venues = array(); $years = array(wp_date('Y') => true);
    foreach (TJ_Store::events() as $e) {
      if ($e['status'] !== 'published' || !(TJ_Store::ts_ms($e['endsAt']) > $now)) continue;
      foreach (TJ_Directory::event_organizers($e) as $n) { $k = TJ_Directory::norm($n); if ($k !== '' && !isset($orgs[$k])) $orgs[$k] = $n; }
      foreach (TJ_Directory::event_venue_names($e) as $n) { $k = TJ_Directory::norm($n); if ($k !== '' && !isset($venues[$k])) $venues[$k] = $n; }
      $p = self::site_parts($e['startsAt']); $years[$p['year']] = true;
    }
    $o = array_values($orgs); natcasesort($o); $v = array_values($venues); natcasesort($v); $y = array_map('strval', array_keys($years)); sort($y);
    return array('organizers' => array_values($o), 'venues' => array_values($v), 'years' => $y);
  }

  public static function r_event_get($c) {
    $viewer = self::current_user();
    $ev = TJ_Store::get_event($c['params']['id']);
    if (!$ev || ($ev['status'] === 'draft' && (!$viewer || ($ev['organizerId'] !== $viewer['id'] && $viewer['role'] !== 'admin')))) self::fail(404, 'Event not found');
    return array('event' => self::public_event($ev, $viewer));
  }

  public static function r_event_create($c) {
    $user = self::require_organizer();
    $body = is_array($c['body']) ? $c['body'] : array();
    $on = trim((string) self::g($body, 'organizerName', ''));
    $body['organizerName'] = $on !== '' ? $on : ($user['organization'] ? $user['organization'] : $user['name']);
    if (self::g($body, 'schedule') === 'series') {
      $sessions = is_array(self::g($body, 'sessions')) ? array_values($body['sessions']) : array();
      if (count($sessions) < 2) self::fail(400, 'Add at least 2 dates/times for a recurring event (or choose One-day event)');
      if (count($sessions) > 60) self::fail(400, 'A recurring event can have at most 60 dates/times');
      $series_id = TJ_Store::new_id('ser'); $now = TJ_Store::now_iso(); $made = array(); $seen = array();
      foreach ($sessions as $i => $sn) {
        $label = 'Date #' . ($i + 1);
        $st = is_array($sn) ? TJ_Store::ts_ms(self::g($sn, 'startsAt')) : NAN;
        $en = is_array($sn) ? TJ_Store::ts_ms(self::g($sn, 'endsAt')) : NAN;
        if (is_nan($st)) self::fail(400, $label . ': start date/time is required');
        if (is_nan($en) || $en <= $st) self::fail(400, $label . ': end time must be after the start time');
        $b2 = $body; $b2['startsAt'] = $sn['startsAt']; $b2['endsAt'] = $sn['endsAt']; $b2['sessionLabel'] = (string) self::g($sn, 'label', '');
        $data = self::validate_event($b2, null);
        if (isset($seen[$data['startsAt']])) self::fail(400, 'Two dates/times start at exactly the same moment. Change or remove one.');
        $seen[$data['startsAt']] = true;
        $made[] = array_merge(array('id' => TJ_Store::new_id('evt'), 'organizerId' => $user['id']), $data, array('seriesId' => $series_id, 'createdAt' => $now));
      }
      self::sort_starts($made);
      $first = self::ensure_geo($made[0]);
      foreach ($made as $k => $e) {
        if ($k > 0 && TJ_Geo::valid_lat_lng(self::g($first, 'lat'), self::g($first, 'lng'))) { $e['lat'] = $first['lat']; $e['lng'] = $first['lng']; $e['geoPrecision'] = self::g($first, 'geoPrecision'); }
        TJ_Store::save_event($k === 0 ? $first : $e);
      }
      TJ_Directory::sync_event($first, $user);
      TJ_Notify::event_created($first, $user);
      return array('event' => self::public_event($first, $user), 'created' => count($made));
    }
    if (self::g($body, 'schedule') === 'series') $body['schedule'] = 'single';
    $data = self::validate_event($body, null);
    $ev = array_merge(array('id' => TJ_Store::new_id('evt'), 'organizerId' => $user['id']), $data, array('createdAt' => TJ_Store::now_iso()));
    TJ_Store::save_event($ev);
    $ev = self::ensure_geo($ev);
    TJ_Directory::sync_event($ev, $user);
    TJ_Notify::event_created($ev, $user);
    return array('event' => self::public_event($ev, $user), 'created' => 1);
  }

  public static function r_event_update($c) {
    $user = self::require_organizer();
    $ev = self::owned_event($user, $c['params']['id']);
    $body = $c['body'];
    $merged = array_merge(self::public_event($ev, $user), array('tiers' => $ev['tiers']), $body);
    if (self::has($body, 'organizerName') && !self::has($body, 'organizers')) $merged['organizers'] = array($body['organizerName']);
    $moved = false;
    foreach (array('venue', 'address', 'city') as $k) {
      if (self::has($body, $k) && trim(is_scalar($body[$k]) || $body[$k] === null ? (string) $body[$k] : '') !== (string) $ev[$k]) $moved = true;
    }
    if ($moved && !self::has($body, 'lat')) { $merged['lat'] = null; $merged['lng'] = null; }
    $before = array('status' => $ev['status']);
    $ev = array_merge($ev, self::validate_event($merged, $ev), array('updatedAt' => TJ_Store::now_iso()));
    TJ_Store::save_event($ev);
    $ev = self::ensure_geo($ev);
    TJ_Directory::sync_event($ev, $user);
    TJ_Notify::event_changed($ev, $before, $user);
    return array('event' => self::public_event($ev, $user));
  }

  public static function r_event_delete($c) {
    $user = self::require_organizer();
    $ev = self::owned_event($user, $c['params']['id']);
    $has = false;
    foreach (TJ_Store::orders() as $o) if ($o['eventId'] === $ev['id'] && empty($o['test'])) { $has = true; break; }
    if ($has) {
      $before = array('status' => $ev['status']);
      $ev['status'] = 'cancelled';
      TJ_Store::save_event($ev);
      if ($before['status'] !== 'cancelled') TJ_Notify::event_changed($ev, $before, $user);
      return array('ok' => true, 'cancelled' => true, 'message' => 'Event has ticket sales, so it was cancelled instead of deleted.');
    }
    TJ_Store::delete_event($ev['id']);
    delete_option('tj_guests_' . $ev['id']);
    TJ_Notify::event_deleted($ev, $user);
    return array('ok' => true, 'deleted' => true);
  }

  /** Optional name + email for each ticket ("Who's coming?"). Each person with an email gets their own ticket email + PDF. */
  private static function ticket_holders($list, $count) {
    $att = is_array($list) ? array_values($list) : array();
    $out = array();
    for ($i = 0; $i < $count; $i++) {
      $a = isset($att[$i]) && is_array($att[$i]) ? $att[$i] : array();
      $name = self::str(self::g($a, 'name'), 'Name for ticket ' . ($i + 1), 80);
      $email = strtolower(self::str(self::g($a, 'email'), 'Email for ticket ' . ($i + 1), 160));
      if ($email !== '' && (!self::valid_email($email) || !is_email($email))) self::fail(400, 'Enter a valid email for ticket ' . ($i + 1));
      if ($email !== '' && $name === '') self::fail(400, 'Enter a name for ticket ' . ($i + 1));
      $h = array();
      if ($name !== '') { $h['holderName'] = $name; if ($email !== '') $h['holderEmail'] = $email; }
      $out[] = $h;
    }
    return $out;
  }

  public static function r_checkout($c) {
    $user = self::require_user();
    self::rate_limit('checkout', 20, 10 * MINUTE_IN_SECONDS);
    $body = $c['body'];
    // Verified first; the stock check + order creation below then run under a lock, so ticket counts can't be oversold.
    self::require_captcha($body, 'checkout');
    $lock = TJ_Store::lock('event_' . $c['params']['id']);
    try {
      TJ_Store::flush_cache();
      return self::checkout_locked($user, $c['params']['id'], $body);
    } finally {
      TJ_Store::unlock($lock);
    }
  }
  /** Shopping cart: tickets for several events paid in one go. Every event is checked first (all locked), so either the whole cart is bought or nothing is. */
  public static function r_cart_checkout($c) {
    $user = self::require_user();
    self::rate_limit('checkout', 20, 10 * MINUTE_IN_SECONDS);
    $b = $c['body'];
    $carts = array();
    $list = is_array(self::g($b, 'carts')) && wp_is_numeric_array($b['carts']) ? $b['carts'] : array();
    foreach ($list as $x) if (is_array($x)) $carts[] = $x;
    if (!$carts) self::fail(400, 'Your cart is empty');
    if (count($carts) > 20) self::fail(400, 'Your cart can hold tickets for up to 20 events at a time');
    $ids = array();
    foreach ($carts as $x) $ids[] = is_scalar(self::g($x, 'eventId')) ? (string) $x['eventId'] : '';
    if (count(array_unique($ids)) !== count($ids)) self::fail(400, 'Each event can only be in your cart once');
    self::require_captcha($b, 'checkout');
    $shared = array();
    foreach (array('buyerFirstName', 'buyerLastName', 'buyerName', 'buyerEmail', 'buyerPhone', 'payment') as $k) if (array_key_exists($k, $b)) $shared[$k] = $b[$k];
    $sorted = $ids; sort($sorted);
    $locks = array(); $done = array(); $cartId = '';
    try {
      foreach ($sorted as $id) $locks[] = TJ_Store::lock('event_' . $id);
      TJ_Store::flush_cache();
      $bodies = array();
      foreach ($carts as $i => $x) {
        $ev = TJ_Store::get_event($ids[$i]);
        if (!$ev) self::fail(404, 'One of the events in your cart is no longer available. Please remove it and try again.');
        $bodies[$i] = array_merge($shared, array('items' => self::g($x, 'items'), 'attendees' => self::g($x, 'attendees')));
        try { self::checkout_locked($user, $ids[$i], $bodies[$i], true); }
        catch (TJ_Fail $e) { if (count($carts) > 1) throw new TJ_Fail($e->status, $ev['title'] . ': ' . $e->getMessage()); throw $e; }
      }
      $cartId = TJ_Store::new_id('cart');
      foreach ($carts as $i => $x) $done[] = self::checkout_locked($user, $ids[$i], $bodies[$i], false, $cartId);
    } finally {
      foreach ($locks as $l) TJ_Store::unlock($l);
    }
    foreach ($done as $d) TJ_Notify::order_placed($d['order'], $d['ev']);
    $views = array();
    foreach ($done as $d) $views[] = self::order_view($d['order']);
    return array('cartId' => $cartId, 'orders' => $views);
  }
  private static function checkout_locked($user, $id, $body, $dry = false, $cartId = '') {
    $ev = TJ_Store::get_event($id);
    if (!$ev || $ev['status'] !== 'published') self::fail(404, 'This event is not available for purchase');
    if (TJ_Store::ts_ms($ev['endsAt']) < self::now_ms()) self::fail(400, 'This event has already ended');
    $items = is_array(self::g($body, 'items')) && wp_is_numeric_array($body['items']) ? $body['items'] : array();
    $lines = array();
    foreach ($items as $it) {
      if (!is_array($it)) $it = array();
      $qty = self::numk($it, 'qty');
      if (is_nan($qty) || !$qty) continue;
      if (!self::is_integer_num($qty) || $qty < 0 || $qty > 100) self::fail(400, 'You can buy up to 100 of each ticket type');
      $ti = null;
      foreach ($ev['tiers'] as $k => $t) if ($t['id'] === self::g($it, 'tierId') && self::is_public_tier($t)) { $ti = $k; break; }
      if ($ti === null) self::fail(400, 'Unknown ticket type');
      $tier = $ev['tiers'][$ti];
      if ($tier['quantity'] - $tier['sold'] < $qty) self::fail(409, 'Only ' . ($tier['quantity'] - $tier['sold']) . ' "' . $tier['name'] . '" tickets left');
      $mpp = (int) self::g($tier, 'maxPerPerson', 0);
      if ($mpp) {
        $mine = self::bought_by($user, $ev['id']); $have = isset($mine[$tier['id']]) ? $mine[$tier['id']] : 0;
        if ($have + $qty > $mpp) self::fail(400, '"' . $tier['name'] . '" is limited to ' . $mpp . ' per person' . ($have ? ' and you already have ' . $have : ''));
      } elseif ($qty > 10) self::fail(400, 'You can buy up to 10 of each ticket type');
      if (self::tier_sale_state($tier) === 'soon') self::fail(400, '"' . $tier['name'] . '" tickets are not on sale yet');
      if (self::tier_sale_state($tier) === 'closed') self::fail(400, 'Sales for "' . $tier['name'] . '" have ended');
      // donation tickets: the buyer picks the amount per ticket, at least the organizer's minimum
      $kind = self::tier_kind($tier);
      $unit = $kind === 'free' ? 0 : (float) $tier['price'];
      if ($kind === 'donation') {
        $unit = self::round2(self::numk($it, 'amount'));
        if (!self::finite($unit) || $unit < (float) $tier['price']) self::fail(400, 'Enter a donation of at least ' . TJ_Notify::money($tier['price']) . ' for "' . $tier['name'] . '"');
        if ($unit > 10000) self::fail(400, 'Donations are limited to $10000.00 per ticket');
      }
      $lines[] = array('ti' => $ti, 'qty' => (int) $qty, 'unit' => $unit, 'donation' => $kind === 'donation');
    }
    if (!$lines) self::fail(400, 'Select at least one ticket');

    // Attendee first + last name are both required (older clients that only send buyerName are split on the last space)
    $legacy = preg_split('/\s+/', trim(is_scalar(self::g($body, 'buyerName')) ? (string) $body['buyerName'] : ''));
    $ln = count($legacy);
    $fn_in = self::g($body, 'buyerFirstName') !== null ? $body['buyerFirstName'] : ($ln > 1 ? implode(' ', array_slice($legacy, 0, -1)) : $legacy[0]);
    $ln_in = self::g($body, 'buyerLastName') !== null ? $body['buyerLastName'] : ($ln > 1 ? $legacy[$ln - 1] : '');
    $buyerFirstName = self::str($fn_in, 'Attendee first name', 40, true);
    $buyerLastName = self::str($ln_in, 'Attendee last name', 40, true);
    $buyerName = $buyerFirstName . ' ' . $buyerLastName;
    $buyerEmail = self::str(self::g($body, 'buyerEmail'), 'Email', 160, true);
    if (!self::valid_email($buyerEmail)) self::fail(400, 'Enter a valid email address');
    $buyerPhone = TJ_Notify::normalize_phone(self::g($body, 'buyerPhone'));
    if (!$buyerPhone) self::fail(400, 'Enter a valid mobile number so we can text your tickets');
    $tcount = 0; foreach ($lines as $l) $tcount += $l['qty'];
    $holders = self::ticket_holders(self::g($body, 'attendees'), $tcount);

    $subtotal = 0; $fees = 0;
    foreach ($lines as $l) { $subtotal += $l['unit'] * $l['qty']; $fees += self::ticket_fee($l['unit']) * $l['qty']; }
    $subtotal = self::round2($subtotal); $fees = self::round2($fees);
    $total = self::round2($subtotal + $fees);

    // DEMO PAYMENT: validates card format only. Replace with a real processor before going live.
    // Card data: only brand, last 4, name on card and a one-way fingerprint are kept (never full numbers or CVC).
    $card = array('cardLast4' => '', 'cardBrand' => '', 'cardHolder' => '', 'cardFingerprint' => '');
    if ($total > 0) {
      $p = is_array(self::g($body, 'payment')) ? $body['payment'] : array();
      $num = preg_replace('/\D/', '', is_scalar(self::g($p, 'cardNumber')) ? (string) $p['cardNumber'] : '');
      if (strlen($num) < 13 || strlen($num) > 19 || !self::luhn_ok($num)) self::fail(400, 'Enter a valid card number');
      $exp = trim(is_scalar(self::g($p, 'expiry')) ? (string) $p['expiry'] : '');
      if (!preg_match('~^(0[1-9]|1[0-2])/\d{2}$~', $exp)) self::fail(400, 'Expiry must be MM/YY');
      list($mm, $yy) = array_map('intval', explode('/', $exp));
      $now = new DateTime('now', wp_timezone());
      $cy = (int) $now->format('Y'); $cm = (int) $now->format('n');
      if (2000 + $yy < $cy || (2000 + $yy === $cy && $mm < $cm)) self::fail(400, 'This card has expired');
      if (!preg_match('/^\d{3,4}$/', trim(is_scalar(self::g($p, 'cvc')) ? (string) $p['cvc'] : ''))) self::fail(400, 'Enter a valid CVC');
      $cardHolder = self::str(self::g($p, 'cardHolder'), 'Name on card', 80, true);
      $card = self::card_info($num, $cardHolder);
    }

    if ($dry) return null;
    $tickets = array(); $oitems = array();
    foreach ($lines as $l) {
      $ev['tiers'][$l['ti']]['sold'] += $l['qty'];
      $t = $ev['tiers'][$l['ti']];
      for ($i = 0; $i < $l['qty']; $i++) $tickets[] = array_merge(array('code' => self::ticket_code(), 'tierId' => $t['id'], 'tierName' => $t['name'], 'price' => self::n($l['unit']), 'checkedIn' => false), $holders[count($tickets)]);
      $item = array('tierId' => $t['id'], 'tierName' => $t['name'], 'price' => self::n($l['unit']), 'qty' => $l['qty']);
      if ($l['donation']) $item['donation'] = true;
      $oitems[] = $item;
    }
    $order = array_merge(array(
      'id' => TJ_Store::new_id('ord'), 'userId' => $user['id'], 'eventId' => $ev['id'],
      'items' => $oitems, 'tickets' => $tickets, 'buyerName' => $buyerName, 'buyerFirstName' => $buyerFirstName, 'buyerLastName' => $buyerLastName,
      'buyerEmail' => $buyerEmail, 'buyerPhone' => $buyerPhone, 'subtotal' => self::n($subtotal), 'fees' => self::n($fees), 'total' => self::n($total),
    ), $card, array('status' => 'paid', 'key' => self::rand_hex(12), 'createdAt' => TJ_Store::now_iso()));
    if ($cartId !== '') $order['cartId'] = $cartId;
    TJ_Store::save_event($ev);
    TJ_Store::save_order($order);
    if (!$user['phone']) update_user_meta($user['id'], 'tj_phone', $buyerPhone);
    if ($cartId !== '') return array('order' => $order, 'ev' => $ev);
    TJ_Notify::order_placed($order, $ev);
    return array('order' => self::order_view($order));
  }

  /** Complimentary tickets: organizer (own events) or admin issues free tickets from any ticket type, public or private. */
  public static function r_comp($c) {
    $user = self::require_organizer();
    $body = $c['body'];
    $lock = TJ_Store::lock('event_' . $c['params']['id']);
    try {
      TJ_Store::flush_cache();
      $ev = self::owned_event($user, $c['params']['id']);
      if ($ev['status'] === 'cancelled') self::fail(400, 'This event is cancelled');
      $ti = null;
      foreach ($ev['tiers'] as $k => $t) if ($t['id'] === self::g($body, 'tierId')) { $ti = $k; break; }
      if ($ti === null) self::fail(400, 'Choose a ticket type');
      $tier = $ev['tiers'][$ti];
      $qty = self::numk($body, 'qty');
      if (!self::is_integer_num($qty) || $qty < 1 || $qty > 50) self::fail(400, 'Quantity must be 1–50');
      $qty = (int) $qty;
      if ($tier['quantity'] - $tier['sold'] < $qty) self::fail(409, 'Only ' . ($tier['quantity'] - $tier['sold']) . ' "' . $tier['name'] . '" tickets left — raise its quantity first');
      $buyerName = self::str(self::g($body, 'name'), 'Guest name', 80, true);
      $buyerEmail = self::str(self::g($body, 'email'), 'Guest email', 160, true);
      if (!self::valid_email($buyerEmail)) self::fail(400, 'Enter a valid email address');
      $buyerPhone = '';
      if (self::g($body, 'phone')) { $buyerPhone = TJ_Notify::normalize_phone($body['phone']); if (!$buyerPhone) self::fail(400, 'Enter a valid mobile number, or leave it blank'); }
      $compNote = self::str(self::g($body, 'note'), 'Message', 300);
      $guest = TJ_Store::user_by_email($buyerEmail);
      $ev['tiers'][$ti]['sold'] += $qty;
      $ev['tiers'][$ti]['comped'] = (int) self::g($tier, 'comped', 0) + $qty;
      $tickets = array();
      for ($i = 0; $i < $qty; $i++) $tickets[] = array('code' => self::ticket_code(), 'tierId' => $tier['id'], 'tierName' => $tier['name'], 'price' => 0, 'checkedIn' => false);
      $order = array(
        'id' => TJ_Store::new_id('ord'), 'userId' => $guest ? $guest['id'] : null, 'eventId' => $ev['id'], 'comp' => true, 'compNote' => $compNote,
        'issuedBy' => $user['organization'] ? $user['organization'] : $user['name'], 'issuedById' => $user['id'],
        'items' => array(array('tierId' => $tier['id'], 'tierName' => $tier['name'], 'price' => 0, 'qty' => $qty)),
        'tickets' => $tickets, 'buyerName' => $buyerName, 'buyerEmail' => $buyerEmail, 'buyerPhone' => $buyerPhone, 'subtotal' => 0, 'fees' => 0, 'total' => 0,
        'cardLast4' => '', 'cardBrand' => '', 'cardHolder' => '', 'cardFingerprint' => '', 'status' => 'paid',
        'key' => self::rand_hex(12), 'createdAt' => TJ_Store::now_iso(),
      );
      TJ_Store::save_event($ev);
      TJ_Store::save_order($order);
    } finally {
      TJ_Store::unlock($lock);
    }
    TJ_Notify::order_placed($order, $ev);
    return array('order' => array_merge(self::order_view($order), array('link' => '/#/t/' . $order['id'] . '/' . $order['key'])), 'stats' => self::event_stats($ev));
  }

  // =====================================================================
  // orders (attendee)
  // =====================================================================
  public static function r_orders($c) {
    $user = self::require_user();
    $list = array();
    foreach (TJ_Store::orders() as $o) if ($o['userId'] === $user['id']) $list[] = $o;
    self::sort_desc_created($list);
    return array('orders' => array_map(array(__CLASS__, 'order_view'), $list));
  }
  public static function r_order($c) {
    $user = self::require_user();
    $o = TJ_Store::get_order($c['params']['id']);
    if (!$o || $o['userId'] !== $user['id']) self::fail(404, 'Order not found');
    return array('order' => self::order_view($o));
  }

  private static function send_ticket_pdf($o, $suffix = '') {
    $ev = TJ_Store::get_event($o['eventId']);
    if (!$ev) $ev = array('title' => 'Event');
    if (!class_exists('TJ_Export')) self::fail(500, 'PDF export is not available');
    $buf = TJ_Export::tickets_pdf($o, $ev, TJ_Notify::site());
    self::send_binary('application/pdf', $buf, TJ_Notify::ticket_pdf_name($ev, $o, $suffix));
  }
  private static function can_see_order($user, $o) {
    if ($o['userId'] === $user['id'] || $user['role'] === 'admin') return true;
    if ($user['role'] === 'organizer') { $e = TJ_Store::get_event($o['eventId']); return $e && $e['organizerId'] === $user['id']; }
    return false;
  }
  public static function r_order_pdf($c) {
    $user = self::require_user();
    $o = TJ_Store::get_order($c['params']['id']);
    if (!$o || !self::can_see_order($user, $o)) self::fail(404, 'Order not found');
    self::send_ticket_pdf($o);
  }
  private static function key_ok($o, $k) { return $o && $k !== '' && strlen($k) === strlen($o['key']) && hash_equals($o['key'], $k); }
  public static function r_ticket_pdf($c) {
    $o = TJ_Store::get_order($c['params']['id']);
    // One person's ticket (link in an attendee's email): only that ticket, never the rest of the order
    $code = (string) self::q($c, 'code');
    if ($code !== '') {
      $tk = (string) self::q($c, 'tk');
      $one = null;
      if ($o) foreach ($o['tickets'] as $t) if ($t['code'] === $code) $one = $t;
      if (!$one || !hash_equals(TJ_Notify::ticket_key($o, $code), $tk)) self::fail(404, 'Ticket not found');
      $o['tickets'] = array($one);
      self::send_ticket_pdf($o, $code);
    }
    if (!self::key_ok($o, (string) self::q($c, 'k'))) self::fail(404, 'Tickets not found');
    self::send_ticket_pdf($o);
  }
  /** Public ticket page (link sent by text/email) — protected by the order's secret key */
  public static function r_ticket_page($c) {
    $o = TJ_Store::get_order($c['params']['id']);
    if (!self::key_ok($o, (string) self::q($c, 'k'))) self::fail(404, 'Tickets not found');
    $safe = self::order_view($o);
    unset($safe['userId'], $safe['cardLast4'], $safe['cardBrand'], $safe['cardHolder']);
    return array('order' => $safe);
  }

  public static function r_resend($c) {
    $user = self::require_user();
    $body = $c['body'];
    $o = TJ_Store::get_order($c['params']['id']);
    if (!$o || $o['userId'] !== $user['id']) self::fail(404, 'Order not found');
    if ($o['status'] === 'refunded') self::fail(400, 'This order was refunded, so its tickets are no longer valid');
    $ev = TJ_Store::get_event($o['eventId']);
    if (!$ev) self::fail(404, 'This event no longer exists');
    $channel = in_array(self::g($body, 'channel'), array('email', 'sms', 'both'), true) ? $body['channel'] : 'both';
    if ($channel !== 'email' && empty($o['buyerPhone'])) self::fail(400, 'No mobile number is saved on this order');
    $now = microtime(true);
    $key = 'tj_resend_' . md5($o['id']);
    $log = get_transient($key);
    $recent = array();
    foreach (is_array($log) ? $log : array() as $t) if ($now - $t < 15 * 60) $recent[] = $t;
    if (count($recent) >= 3) self::fail(429, 'You can resend tickets up to 3 times every 15 minutes. Please try again shortly, or download the PDF.');
    $recent[] = $now;
    set_transient($key, $recent, 15 * 60);
    $opts = array('buyerOnly' => true, 'email' => $channel !== 'sms', 'sms' => $channel !== 'email');
    $run = function () use ($o, $ev, $opts) { TJ_Notify::order_placed($o, $ev, $opts); };
    if (!empty($o['test'])) TJ_Notify::with_sandbox(array('orderId' => $o['id'], 'testerEmail' => $user['email']), $run); else $run();
    return array('ok' => true, 'sentTo' => array('email' => $channel !== 'sms' ? $o['buyerEmail'] : null, 'sms' => $channel !== 'email' ? $o['buyerPhone'] : null));
  }

  // =====================================================================
  // organizer
  // =====================================================================
  public static function r_org_events($c) {
    $user = self::require_organizer();
    $list = array();
    foreach (TJ_Store::events() as $e) if ($e['organizerId'] === $user['id']) $list[] = $e;
    self::sort_starts($list);
    $out = array();
    foreach ($list as $e) $out[] = array_merge(self::public_event($e, $user), array('tiers' => $e['tiers'], 'stats' => self::event_stats($e)));
    return array('events' => $out);
  }
  public static function r_org_event($c) {
    $user = self::require_organizer();
    $ev = self::owned_event($user, $c['params']['id']);
    return array('event' => array_merge(self::public_event($ev, $user), array('tiers' => $ev['tiers'], 'stats' => self::event_stats($ev))));
  }
  public static function r_attendees($c) {
    $user = self::require_organizer();
    $ev = self::owned_event($user, $c['params']['id']);
    $att = array();
    foreach (TJ_Store::orders() as $o) {
      if ($o['eventId'] !== $ev['id'] || $o['status'] === 'refunded') continue;
      foreach ($o['tickets'] as $t) {
        $att[] = array_merge(array('code' => $t['code'], 'tierName' => $t['tierName'], 'price' => $t['price'], 'checkedIn' => !empty($t['checkedIn']), 'checkedInAt' => self::g($t, 'checkedInAt') ? $t['checkedInAt'] : null, 'holderName' => (string) self::g($t, 'holderName', ''), 'holderEmail' => (string) self::g($t, 'holderEmail', ''), 'buyerName' => $o['buyerName']),
          self::split_name($o),
          array('buyerEmail' => $o['buyerEmail'], 'buyerPhone' => (string) self::g($o, 'buyerPhone', ''), 'orderId' => $o['id'], 'purchasedAt' => $o['createdAt'], 'test' => !empty($o['test']), 'comp' => !empty($o['comp']), 'compNote' => (string) self::g($o, 'compNote', ''), 'issuedBy' => (string) self::g($o, 'issuedBy', '')));
      }
    }
    return array('event' => array_merge(self::public_event($ev, $user), array('stats' => self::event_stats($ev))), 'attendees' => $att);
  }
  public static function r_checkin($c) {
    $user = self::require_organizer();
    $b = $c['body'];
    $code = strtoupper(self::str(self::g($b, 'code'), 'Ticket code', 20, true));
    $ev = self::owned_event($user, is_scalar(self::g($b, 'eventId')) ? (string) $b['eventId'] : '');
    foreach (TJ_Store::orders() as $o) {
      if ($o['eventId'] !== $ev['id']) continue;
      foreach ($o['tickets'] as $k => $t) {
        if ($t['code'] !== $code) continue;
        if ($o['status'] === 'refunded') self::fail(409, 'Ticket ' . $code . ' was refunded and is no longer valid');
        if (!empty($t['checkedIn'])) self::fail(409, 'Ticket ' . $code . ' was already checked in');
        $o['tickets'][$k]['checkedIn'] = true;
        $o['tickets'][$k]['checkedInAt'] = TJ_Store::now_iso();
        TJ_Store::save_order($o);
        return array('ok' => true, 'ticket' => array('code' => $code, 'tierName' => $t['tierName'], 'buyerName' => self::g($t, 'holderName') ? $t['holderName'] : $o['buyerName']));
      }
    }
    self::fail(404, 'No ticket ' . $code . ' for this event');
  }

  // ---------- guest list (names the organizer types in, checked in by tapping at the door) ----------
  private static function guests_load($event_id) {
    $g = get_option('tj_guests_' . $event_id, array());
    return is_array($g) ? array_values($g) : array();
  }
  private static function guests_save($event_id, $list) {
    update_option('tj_guests_' . $event_id, array_values($list), false);
  }
  private static function guest_out($g) {
    return array('id' => (string) $g['id'], 'name' => (string) $g['name'], 'email' => (string) self::g($g, 'email', ''), 'note' => (string) self::g($g, 'note', ''), 'plus' => (int) self::g($g, 'plus', 0), 'addedAt' => (string) self::g($g, 'addedAt', ''), 'addedBy' => (string) self::g($g, 'addedBy', ''), 'checkedIn' => !empty($g['checkedIn']), 'checkedInAt' => self::g($g, 'checkedInAt') ? $g['checkedInAt'] : null);
  }
  public static function r_guests($c) {
    $user = self::require_organizer();
    $ev = self::owned_event($user, $c['params']['id']);
    return array('guests' => array_map(array(__CLASS__, 'guest_out'), self::guests_load($ev['id'])));
  }
  public static function r_guest_add($c) {
    $user = self::require_organizer();
    $ev = self::owned_event($user, $c['params']['id']);
    $b = $c['body'];
    $name = self::str(self::g($b, 'name'), 'Name', 80, true);
    $email = strtolower(self::str(self::g($b, 'email'), 'Email', 160));
    if ($email !== '' && (!self::valid_email($email) || !is_email($email))) self::fail(400, 'Enter a valid email address (or leave it empty)');
    $note = self::str(self::g($b, 'note'), 'Note', 120);
    $plus = (int) round(is_numeric(self::g($b, 'plus')) ? (float) $b['plus'] : 0);
    if ($plus < 0 || $plus > 20) self::fail(400, 'Extra guests must be between 0 and 20');
    $list = self::guests_load($ev['id']);
    if (count($list) >= 2000) self::fail(400, 'The guest list is full (2,000 names)');
    $g = array('id' => 'gst_' . self::rand_hex(8), 'name' => $name, 'email' => $email, 'note' => $note, 'plus' => $plus, 'addedAt' => TJ_Store::now_iso(), 'addedBy' => (string) $user['name'], 'checkedIn' => false, 'checkedInAt' => null);
    $list[] = $g;
    self::guests_save($ev['id'], $list);
    return array('guest' => self::guest_out($g));
  }
  private static function guest_index($list, $gid) {
    foreach ($list as $i => $g) if ($g['id'] === $gid) return $i;
    self::fail(404, 'That name is not on the guest list');
  }
  public static function r_guest_delete($c) {
    $user = self::require_organizer();
    $ev = self::owned_event($user, $c['params']['id']);
    $list = self::guests_load($ev['id']);
    $i = self::guest_index($list, (string) $c['params']['gid']);
    array_splice($list, $i, 1);
    self::guests_save($ev['id'], $list);
    return array('ok' => true);
  }
  public static function r_guest_checkin($c) {
    $user = self::require_organizer();
    $ev = self::owned_event($user, $c['params']['id']);
    $list = self::guests_load($ev['id']);
    $i = self::guest_index($list, (string) $c['params']['gid']);
    if (self::g($c['body'], 'undo')) { $list[$i]['checkedIn'] = false; $list[$i]['checkedInAt'] = null; }
    else {
      if (!empty($list[$i]['checkedIn'])) self::fail(409, $list[$i]['name'] . ' was already checked in');
      $list[$i]['checkedIn'] = true; $list[$i]['checkedInAt'] = TJ_Store::now_iso();
    }
    self::guests_save($ev['id'], $list);
    return array('guest' => self::guest_out($list[$i]));
  }

  public static function r_upload($c) {
    self::require_organizer();
    $data = self::g($c['body'], 'dataUrl');
    if (!is_string($data) || !preg_match('~^data:image/(png|jpe?g|webp|gif);base64,([A-Za-z0-9+/=]+)$~', $data, $m)) self::fail(400, 'Upload a PNG, JPG, WEBP or GIF image');
    self::rate_limit('upload', 60, HOUR_IN_SECONDS);
    $buf = base64_decode($m[2], true);
    if ($buf === false) self::fail(400, 'Upload a PNG, JPG, WEBP or GIF image');
    if (strlen($buf) > 5 * 1024 * 1024) self::fail(413, 'Image must be 5MB or smaller');
    if (!self::image_bytes_ok($buf, $m[1])) self::fail(400, "That file isn't a real image. Please upload a PNG, JPG, WEBP or GIF.");
    $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
    $url = self::save_upload(self::rand_hex(10) . '.' . $ext, $buf);
    if (!$url) self::fail(500, 'Could not save the image');
    return array('url' => $url);
  }
  /** Saves image bytes to the WP uploads folder and the Media Library. Returns the public URL or ''. */
  public static function save_upload($name, $buf, $title = '') {
    $up = wp_upload_bits($name, null, $buf);
    if (!empty($up['error'])) { error_log('[tj upload] ' . $up['error']); return ''; }
    $ft = wp_check_filetype($up['file']);
    $att = wp_insert_attachment(array(
      'post_mime_type' => $ft['type'], 'post_title' => $title ? $title : preg_replace('/\.[^.]+$/', '', basename($up['file'])),
      'post_content' => '', 'post_status' => 'inherit', 'post_author' => get_current_user_id(),
    ), $up['file']);
    if ($att && !is_wp_error($att)) {
      require_once ABSPATH . 'wp-admin/includes/image.php';
      wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $up['file']));
      update_post_meta($att, '_tj_upload', 1);
    }
    return $up['url'];
  }

  // =====================================================================
  // administrator
  // =====================================================================
  public static function r_admin_stats($c) {
    self::require_admin();
    $paid = self::paid_orders();
    $now = self::now_ms();
    $users = TJ_Store::users();
    $events = TJ_Store::events();
    $all = TJ_Store::orders();
    $sum = function ($k) use ($paid) { $s = 0; foreach ($paid as $o) $s += $o[$k]; return TJ_Rest::n(TJ_Rest::round2($s)); };
    $by_role = function ($r) use ($users) { $n = 0; foreach ($users as $u) if ($u['role'] === $r) $n++; return $n; };
    $susp = 0; foreach ($users as $u) if ($u['suspended']) $susp++;
    $live = 0; foreach ($events as $e) if ($e['status'] === 'published' && TJ_Store::ts_ms($e['endsAt']) > $now) $live++;
    $refunds = 0; $tests = 0; foreach ($all as $o) { if ($o['status'] === 'refunded' && empty($o['test'])) $refunds++; if (!empty($o['test'])) $tests++; }
    $tickets = 0; foreach ($paid as $o) $tickets += count($o['tickets']);
    $ro = $all; self::sort_desc_created($ro);
    $ru = $users; self::sort_desc_created($ru);
    return array(
      'users' => count($users), 'attendees' => $by_role('user'), 'organizers' => $by_role('organizer'), 'admins' => $by_role('admin'),
      'suspended' => $susp, 'events' => count($events), 'liveEvents' => $live,
      'orders' => count($paid), 'refunds' => $refunds, 'testOrders' => $tests, 'ticketsSold' => $tickets,
      'grossSales' => $sum('subtotal'), 'platformFees' => $sum('fees'), 'totalCollected' => $sum('total'),
      'recentOrders' => array_map(array(__CLASS__, 'admin_order'), array_slice($ro, 0, 8)),
      'recentUsers' => array_map(array(__CLASS__, 'admin_user'), array_slice($ru, 0, 6)),
    );
  }

  public static function r_admin_users($c) {
    self::require_admin();
    $q = strtolower(trim((string) self::q($c, 'q')));
    $role = (string) self::q($c, 'role');
    $list = array();
    foreach (TJ_Store::users() as $u) {
      if ($role !== '' && ($role === 'suspended' ? !$u['suspended'] : $u['role'] !== $role)) continue;
      if ($q !== '' && strpos(strtolower(implode(' ', array($u['name'], $u['email'], $u['organization']))), $q) === false) continue;
      $list[] = $u;
    }
    self::sort_desc_created($list);
    return array('users' => array_map(array(__CLASS__, 'admin_user'), $list));
  }

  private static function find_user($id) {
    $u = is_numeric($id) ? TJ_Store::user_by_id((int) $id) : null;
    if (!$u) self::fail(404, 'User not found');
    return $u;
  }
  public static function r_admin_user($c) {
    self::require_admin();
    $u = self::find_user($c['params']['id']);
    $orders = array();
    foreach (TJ_Store::orders() as $o) if ($o['userId'] === $u['id']) $orders[] = $o;
    self::sort_desc_created($orders);
    $events = array();
    foreach (TJ_Store::events() as $e) if ($e['organizerId'] === $u['id']) $events[] = self::admin_event($e);
    return array('user' => self::admin_user($u), 'orders' => array_map(array(__CLASS__, 'admin_order'), $orders), 'events' => $events);
  }

  public static function r_admin_user_create($c) {
    self::require_admin();
    $b = $c['body'];
    $name = self::str(self::g($b, 'name'), 'Name', 80, true);
    $email = strtolower(self::str(self::g($b, 'email'), 'Email', 160, true));
    if (!self::valid_email($email) || !is_email($email)) self::fail(400, 'Enter a valid email address');
    if (TJ_Store::user_by_email($email) || email_exists($email)) self::fail(409, 'An account with this email already exists');
    $password = is_scalar(self::g($b, 'password')) ? (string) $b['password'] : '';
    self::check_password($password);
    $role = in_array(self::g($b, 'role'), array('user', 'organizer', 'admin'), true) ? $b['role'] : 'user';
    $organization = self::str(self::g($b, 'organization'), 'Organizer name', 100);
    $phone = TJ_Notify::normalize_phone(self::g($b, 'phone'));
    if ($phone === null) self::fail(400, 'Enter a valid mobile number');
    $user = self::create_wp_user($name, $email, $password, $role, $organization, $phone);
    return array('user' => self::admin_user($user));
  }

  public static function r_admin_user_update($c) {
    $admin = self::require_admin();
    $u = self::find_user($c['params']['id']);
    $b = $c['body'];
    $self = $u['id'] === $admin['id'];
    $next = array(
      'name' => self::has($b, 'name') ? self::str($b['name'], 'Name', 80, true) : $u['name'],
      'email' => self::has($b, 'email') ? strtolower(self::str($b['email'], 'Email', 160, true)) : $u['email'],
      'role' => $u['role'],
      'organization' => self::has($b, 'organization') ? self::str($b['organization'], 'Organizer name', 100) : $u['organization'],
      'suspended' => self::has($b, 'suspended') ? (bool) $b['suspended'] : (bool) $u['suspended'],
      'phone' => self::has($b, 'phone') ? TJ_Notify::normalize_phone($b['phone']) : ($u['phone'] ? $u['phone'] : ''),
    );
    if (self::has($b, 'role')) { if (!in_array($b['role'], array('user', 'organizer', 'admin'), true)) self::fail(400, 'Invalid role'); $next['role'] = $b['role']; }
    if ($next['phone'] === null) self::fail(400, 'Enter a valid mobile number');
    if (!self::valid_email($next['email'])) self::fail(400, 'Enter a valid email address');
    $other = TJ_Store::user_by_email($next['email']);
    if ($other && $other['id'] !== $u['id']) self::fail(409, 'Another account already uses this email');
    if ($self && $next['role'] !== 'admin') self::fail(400, "You can't remove your own administrator role");
    if ($self && $next['suspended']) self::fail(400, "You can't suspend your own account");
    if ($u['role'] === 'admin' && ($next['role'] !== 'admin' || $next['suspended']) && count(TJ_Notify::active_admins()) <= 1) self::fail(400, 'At least one active administrator is required');
    $pw = self::g($b, 'password');
    if ($pw) self::check_password((string) $pw, 'New password');

    add_filter('send_email_change_email', '__return_false');
    add_filter('send_password_change_email', '__return_false');
    $upd = array('ID' => $u['id'], 'display_name' => $next['name']);
    if ($next['email'] !== $u['email']) $upd['user_email'] = $next['email'];
    if ($pw) $upd['user_pass'] = (string) $pw;   // keeps the current admin's own session (WP re-issues the cookie with the same token)
    $r = wp_update_user($upd);
    if (is_wp_error($r)) self::fail(400, $r->get_error_message());
    if ($pw) {
      $token = $self ? wp_get_session_token() : null;
      self::kill_sessions($u['id'], $token ? $token : null);
    }
    if ($next['role'] !== $u['role']) { $wpu = get_userdata($u['id']); $wpu->set_role(TJ_Store::wp_role_for($next['role'])); }
    update_user_meta($u['id'], 'tj_organization', $next['organization']);
    update_user_meta($u['id'], 'tj_phone', $next['phone']);
    update_user_meta($u['id'], 'tj_suspended', $next['suspended'] ? 1 : 0);
    update_user_meta($u['id'], 'tj_updated_at', TJ_Store::now_iso());
    clean_user_cache($u['id']);
    TJ_Store::flush_users();
    if ($next['suspended']) self::kill_sessions($u['id']);
    return array('user' => self::admin_user(TJ_Store::user_by_id($u['id'])));
  }

  public static function r_admin_user_delete($c) {
    $admin = self::require_admin();
    $u = self::find_user($c['params']['id']);
    if ($u['id'] === $admin['id']) self::fail(400, "You can't delete your own account");
    if ($u['role'] === 'admin') {
      $others = 0;
      foreach (TJ_Notify::active_admins() as $a) if ($a['id'] !== $u['id']) $others++;
      if ($others < 1) self::fail(400, 'At least one active administrator is required');
    }
    $theirs = array();
    foreach (TJ_Store::events() as $e) if ($e['organizerId'] === $u['id']) $theirs[$e['id']] = true;
    foreach (TJ_Store::orders() as $o) {
      if ($o['userId'] === $u['id'] || isset($theirs[$o['eventId']])) self::fail(409, 'This account has orders or event sales on record, so it cannot be deleted. Suspend it instead.');
    }
    foreach (array_keys($theirs) as $id) TJ_Store::delete_event($id);
    self::kill_sessions($u['id']);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user($u['id']);
    TJ_Store::flush_users();
    return array('ok' => true);
  }

  // ---------- saved organizers & venues ----------
  public static function r_directory($c) {
    self::require_organizer();
    $by = function ($a, $b) { return strcasecmp($a['name'], $b['name']); };
    $o = array_map(function ($x) { return array('id' => $x['id'], 'name' => $x['name']); }, TJ_Directory::organizers()); usort($o, $by);
    $v = array_map(function ($x) { return array('id' => $x['id'], 'name' => $x['name'], 'address' => $x['address'], 'city' => $x['city'], 'lat' => $x['lat'], 'lng' => $x['lng'], 'geoPrecision' => $x['geoPrecision']); }, TJ_Directory::venues()); usort($v, $by);
    return array('organizers' => $o, 'venues' => $v);
  }
  private static function organizer_view($o) {
    $evs = array(); $now = self::now_ms();
    foreach (TJ_Store::events() as $e) foreach (TJ_Directory::event_organizers($e) as $n) if (TJ_Directory::norm($n) === TJ_Directory::norm($o['name'])) { $evs[] = $e; break; }
    $up = 0; $sold = 0;
    foreach ($evs as $e) { if ($e['status'] === 'published' && TJ_Store::ts_ms($e['endsAt']) > $now) $up++; foreach ($e['tiers'] as $t) $sold += $t['sold'] - (int) self::g($t, 'comped', 0); }
    $acct = !empty($o['userId']) ? TJ_Store::user_by_id($o['userId']) : null;
    return array_merge($o, array('account' => $acct ? array('id' => $acct['id'], 'name' => $acct['name'], 'email' => $acct['email']) : null, 'events' => count($evs), 'upcoming' => $up, 'ticketsSold' => $sold));
  }
  private static function venue_view($v) {
    $n = 0; $up = 0; $now = self::now_ms();
    foreach (TJ_Store::events() as $e) foreach (TJ_Directory::event_venue_names($e) as $x) if (TJ_Directory::norm($x) === TJ_Directory::norm($v['name'])) { $n++; if ($e['status'] === 'published' && TJ_Store::ts_ms($e['endsAt']) > $now) $up++; break; }
    return array_merge($v, array('events' => $n, 'upcoming' => $up));
  }
  private static function organizer_input($b) {
    $name = self::str(self::g($b, 'name'), 'Organizer name', 100, true);
    $email = strtolower(self::str(self::g($b, 'email'), 'Email', 160));
    if ($email !== '' && !is_email($email)) self::fail(400, 'Enter a valid email address');
    $phone = self::g($b, 'phone') ? TJ_Notify::normalize_phone($b['phone']) : '';
    if ($phone === null) self::fail(400, 'Enter a valid phone number');
    $website = self::str(self::g($b, 'website'), 'Website', 200);
    $notes = self::str(self::g($b, 'notes'), 'Notes', 1000);
    $uid = self::g($b, 'userId') ? (int) $b['userId'] : null;
    if ($uid && !TJ_Store::user_by_id($uid)) self::fail(400, 'Linked account not found');
    return array('name' => $name, 'email' => $email, 'phone' => $phone ? $phone : '', 'website' => $website, 'notes' => $notes, 'userId' => $uid);
  }
  private static function venue_input($b) {
    $name = self::str(self::g($b, 'name'), 'Venue name', 120, true);
    $address = self::str(self::g($b, 'address'), 'Address', 200);
    $city = self::str(self::g($b, 'city'), 'City', 80, true);
    $phone = self::g($b, 'phone') ? TJ_Notify::normalize_phone($b['phone']) : '';
    if ($phone === null) self::fail(400, 'Enter a valid phone number');
    $cap = self::g($b, 'capacity');
    $capacity = ($cap === '' || $cap === null) ? null : (is_numeric($cap) ? (int) $cap : -1);
    if ($capacity !== null && ($capacity < 1 || $capacity > 1000000 || (string) $capacity !== (string) $cap && !is_int($cap))) self::fail(400, 'Capacity must be a whole number');
    $notes = self::str(self::g($b, 'notes'), 'Notes', 1000);
    return array('name' => $name, 'address' => $address, 'city' => $city, 'phone' => $phone ? $phone : '', 'capacity' => $capacity, 'notes' => $notes);
  }
  private static function locate_venue($v) {
    if (TJ_Geo::valid_lat_lng($v['lat'], $v['lng'])) return $v;
    $s = self::settings();
    $hit = TJ_Geo::geocode_place(array('venue' => $v['name'], 'address' => $v['address'], 'city' => $v['city'], 'country' => $s['maps']['country']), microtime(true) + 8);
    if ($hit) { $v['lat'] = $hit['lat']; $v['lng'] = $hit['lng']; $v['geoPrecision'] = $hit['precision']; }
    return $v;
  }
  public static function r_admin_organizers($c) {
    self::require_admin();
    $q = TJ_Directory::norm(self::q($c, 'q'));
    $list = array();
    foreach (TJ_Directory::organizers() as $o) { if ($q !== '' && strpos(TJ_Directory::norm($o['name'] . ' ' . $o['email'] . ' ' . $o['phone'] . ' ' . $o['website']), $q) === false) continue; $list[] = self::organizer_view($o); }
    usort($list, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    $accounts = array();
    foreach (TJ_Store::users() as $u) if ($u['role'] === 'organizer' || $u['role'] === 'admin') $accounts[] = array('id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'organization' => $u['organization']);
    return array('organizers' => $list, 'accounts' => $accounts);
  }
  public static function r_admin_organizer_create($c) {
    $admin = self::require_admin();
    $data = self::organizer_input($c['body']);
    $all = TJ_Directory::organizers();
    if (TJ_Directory::find_org($all, $data['name'])) self::fail(409, 'An organizer with this name already exists');
    $o = array_merge(array('id' => TJ_Store::new_id('org')), $data, array('createdById' => $admin['id'], 'createdAt' => TJ_Store::now_iso()));
    $all[] = $o; TJ_Directory::save_organizers($all);
    return array('organizer' => self::organizer_view($o));
  }
  public static function r_admin_organizer_update($c) {
    self::require_admin();
    $all = TJ_Directory::organizers(); $i = -1;
    foreach ($all as $k => $x) if ($x['id'] === $c['params']['id']) $i = $k;
    if ($i < 0) self::fail(404, 'Organizer not found');
    $data = self::organizer_input(array_merge($all[$i], is_array($c['body']) ? $c['body'] : array()));
    foreach ($all as $k => $x) if ($k !== $i && TJ_Directory::norm($x['name']) === TJ_Directory::norm($data['name'])) self::fail(409, 'Another organizer already has this name');
    $old = $all[$i]['name'];
    $all[$i] = array_merge($all[$i], $data, array('updatedAt' => TJ_Store::now_iso()));
    TJ_Directory::save_organizers($all);
    $n = $old !== $all[$i]['name'] ? TJ_Directory::rename_on_events('organizer', $old, $all[$i]) : 0;
    return array('organizer' => self::organizer_view($all[$i]), 'eventsUpdated' => $n);
  }
  public static function r_admin_organizer_delete($c) {
    self::require_admin();
    $all = TJ_Directory::organizers(); $keep = array(); $found = false;
    foreach ($all as $x) { if ($x['id'] === $c['params']['id']) { $found = true; continue; } $keep[] = $x; }
    if (!$found) self::fail(404, 'Organizer not found');
    TJ_Directory::save_organizers($keep);
    return array('ok' => true);
  }
  public static function r_admin_venues($c) {
    self::require_admin();
    $q = TJ_Directory::norm(self::q($c, 'q'));
    $list = array();
    foreach (TJ_Directory::venues() as $v) { if ($q !== '' && strpos(TJ_Directory::norm($v['name'] . ' ' . $v['address'] . ' ' . $v['city']), $q) === false) continue; $list[] = self::venue_view($v); }
    usort($list, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    return array('venues' => $list);
  }
  public static function r_admin_venue_create($c) {
    $admin = self::require_admin();
    $b = is_array($c['body']) ? $c['body'] : array();
    $data = self::venue_input($b);
    $all = TJ_Directory::venues();
    foreach ($all as $x) if (TJ_Directory::norm($x['name']) === TJ_Directory::norm($data['name']) && TJ_Directory::norm($x['city']) === TJ_Directory::norm($data['city'])) self::fail(409, 'This venue is already saved');
    $v = array_merge(array('id' => TJ_Store::new_id('ven')), $data, array('lat' => null, 'lng' => null, 'geoPrecision' => null, 'createdById' => $admin['id'], 'createdAt' => TJ_Store::now_iso()));
    $lat = self::g($b, 'lat'); $lng = self::g($b, 'lng');
    if ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng) && TJ_Geo::valid_lat_lng((float) $lat, (float) $lng)) { $v['lat'] = (float) $lat; $v['lng'] = (float) $lng; $v['geoPrecision'] = 'pin'; }
    else $v = self::locate_venue($v);
    $all[] = $v; TJ_Directory::save_venues($all);
    return array('venue' => self::venue_view($v));
  }
  public static function r_admin_venue_update($c) {
    self::require_admin();
    $b = is_array($c['body']) ? $c['body'] : array();
    $all = TJ_Directory::venues(); $i = -1;
    foreach ($all as $k => $x) if ($x['id'] === $c['params']['id']) $i = $k;
    if ($i < 0) self::fail(404, 'Venue not found');
    $data = self::venue_input(array_merge($all[$i], $b));
    foreach ($all as $k => $x) if ($k !== $i && TJ_Directory::norm($x['name']) === TJ_Directory::norm($data['name']) && TJ_Directory::norm($x['city']) === TJ_Directory::norm($data['city'])) self::fail(409, 'Another saved venue already has this name and city');
    $old = $all[$i]['name'];
    $moved = TJ_Directory::norm($data['address']) !== TJ_Directory::norm($all[$i]['address']) || TJ_Directory::norm($data['city']) !== TJ_Directory::norm($all[$i]['city']);
    $v = array_merge($all[$i], $data, array('updatedAt' => TJ_Store::now_iso()));
    $lat = self::g($b, 'lat'); $lng = self::g($b, 'lng');
    if ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng) && TJ_Geo::valid_lat_lng((float) $lat, (float) $lng)) { $v['lat'] = (float) $lat; $v['lng'] = (float) $lng; $v['geoPrecision'] = 'pin'; }
    elseif ($moved) { $v['lat'] = null; $v['lng'] = null; $v['geoPrecision'] = null; $v = self::locate_venue($v); }
    $all[$i] = $v; TJ_Directory::save_venues($all);
    $n = TJ_Directory::rename_on_events('venue', $old, $v);
    return array('venue' => self::venue_view($v), 'eventsUpdated' => $n);
  }
  public static function r_admin_venue_delete($c) {
    self::require_admin();
    $all = TJ_Directory::venues(); $keep = array(); $found = false;
    foreach ($all as $x) { if ($x['id'] === $c['params']['id']) { $found = true; continue; } $keep[] = $x; }
    if (!$found) self::fail(404, 'Venue not found');
    TJ_Directory::save_venues($keep);
    return array('ok' => true);
  }

  public static function r_admin_events($c) {
    self::require_admin();
    $q = strtolower(trim((string) self::q($c, 'q')));
    $status = (string) self::q($c, 'status');
    $list = array();
    foreach (TJ_Store::events() as $e) {
      if ($status !== '' && $e['status'] !== $status) continue;
      if ($q !== '') {
        $p = TJ_Store::user_by_id($e['organizerId']);
        $hay = strtolower(implode(' ', array($e['title'], implode(' ', TJ_Directory::event_venue_names($e)), $e['city'], $e['category'], isset($e['organizerName']) ? $e['organizerName'] : '', $p ? $p['name'] : '', $p ? $p['email'] : '', $p ? $p['organization'] : '')));
        if (strpos($hay, $q) === false) continue;
      }
      $list[] = $e;
    }
    self::sort_starts($list, true);
    return array('events' => array_map(array(__CLASS__, 'admin_event'), $list));
  }

  public static function r_admin_orders($c) {
    self::require_admin();
    $q = strtolower(trim((string) self::q($c, 'q')));
    $status = (string) self::q($c, 'status');
    $list = array();
    foreach (TJ_Store::orders() as $o) {
      if ($status !== '' && $o['status'] !== $status) continue;
      if ($q !== '') {
        $ev = TJ_Store::get_event($o['eventId']);
        $parts = array($o['id'], $o['buyerName'], $o['buyerEmail'], $ev ? $ev['title'] : '');
        foreach ($o['tickets'] as $t) $parts[] = $t['code'];
        if (strpos(strtolower(implode(' ', $parts)), $q) === false) continue;
      }
      $list[] = $o;
    }
    self::sort_desc_created($list);
    return array('orders' => array_map(array(__CLASS__, 'admin_order'), $list));
  }

  public static function r_admin_refund($c) {
    $admin = self::require_admin();
    $o = TJ_Store::get_order($c['params']['id']);
    if (!$o) self::fail(404, 'Order not found');
    if ($o['status'] === 'refunded') self::fail(409, 'This order was already refunded');
    $ev = TJ_Store::get_event($o['eventId']);
    if ($ev && empty($o['test'])) {
      foreach ($o['items'] as $it) {
        foreach ($ev['tiers'] as $k => $t) {
          if ($t['id'] !== $it['tierId']) continue;
          $ev['tiers'][$k]['sold'] = max(0, $t['sold'] - $it['qty']);
          if (!empty($o['comp'])) $ev['tiers'][$k]['comped'] = max(0, (int) self::g($t, 'comped', 0) - $it['qty']);
        }
      }
      TJ_Store::save_event($ev);
    }
    // DEMO: when real payments are connected, issue the processor refund here.
    $o['status'] = 'refunded';
    $o['refundedAt'] = TJ_Store::now_iso();
    TJ_Store::save_order($o);
    TJ_Notify::with_sandbox(!empty($o['test']) ? array('orderId' => $o['id'], 'testerEmail' => $admin['email']) : null, function () use ($o) { TJ_Notify::order_refunded($o); });
    return array('order' => self::admin_order($o));
  }

  // ---------- settings ----------
  private static $SECRET_KEYS = array('resendApiKey', 'twilioToken');
  public static function settings_view() {
    $s = self::settings();
    $n = $s['notifications'];
    foreach (self::$SECRET_KEYS as $k) $n[$k] = !empty($n[$k]) ? '••••' . substr($n[$k], -4) : '';
    $cap = $s['captcha'];
    $cap['secretKey'] = !empty($cap['secretKey']) ? '••••' . substr($cap['secretKey'], -4) : '';
    $cap['testKeys'] = $s['captcha']['siteKey'] === TJ_Store::RECAPTCHA_TEST_SITE;
    return array('feePercent' => $s['feePercent'], 'feeFixed' => $s['feeFixed'], 'notifications' => $n, 'maps' => $s['maps'], 'preloader' => $s['preloader'], 'security' => $s['security'], 'captcha' => $cap,
      'defaultAdminRecipients' => array_map(function ($u) { return $u['email']; }, TJ_Notify::active_admins()));
  }
  public static function r_admin_settings($c) { self::require_admin(); return array('settings' => self::settings_view()); }

  public static function r_admin_settings_put($c) {
    self::require_admin();
    $b = $c['body'];
    $pct = self::numk($b, 'feePercent');
    $fixed = self::numk($b, 'feeFixed');
    if (!self::finite($pct) || $pct < 0 || $pct > 30) self::fail(400, 'Fee percent must be between 0 and 30');
    if (!self::finite($fixed) || $fixed < 0 || $fixed > 20) self::fail(400, 'Fixed fee must be between $0 and $20');
    $s = self::settings();
    $s['feePercent'] = self::round2($pct) / 100;
    $s['feeFixed'] = self::round2($fixed);
    TJ_Store::save_settings($s);
    return array('settings' => self::settings_view());
  }

  public static function r_admin_captcha($c) {
    self::require_admin();
    $b = $c['body'];
    $s = self::settings();
    $cap = $s['captcha'];
    if (!empty($b['useTestKeys'])) { $cap['siteKey'] = TJ_Store::RECAPTCHA_TEST_SITE; $cap['secretKey'] = TJ_Store::RECAPTCHA_TEST_SECRET; }
    else {
      if (self::has($b, 'siteKey')) {
        $k = self::str($b['siteKey'], 'Site key', 100);
        if ($k !== '' && !preg_match('/^[\w-]{30,60}$/', $k)) self::fail(400, 'That does not look like a reCAPTCHA site key');
        $cap['siteKey'] = $k;
      }
      $sk = self::g($b, 'secretKey');
      if (is_string($sk) && $sk !== '' && strpos($sk, '••••') !== 0) {
        if (!preg_match('/^[\w-]{30,60}$/', trim($sk))) self::fail(400, 'That does not look like a reCAPTCHA secret key');
        $cap['secretKey'] = trim($sk);
      }
    }
    foreach (array('enabled', 'protectRegister', 'protectCheckout') as $k) if (self::has($b, $k)) $cap[$k] = (bool) $b[$k];
    if (!empty($cap['enabled']) && (empty($cap['siteKey']) || empty($cap['secretKey']))) self::fail(400, 'Enter both the site key and the secret key, or use the test keys');
    $s['captcha'] = $cap;
    TJ_Store::save_settings($s);
    return array('settings' => self::settings_view());
  }

  public static function r_admin_maps($c) {
    self::require_admin();
    $b = $c['body'];
    $s = self::settings();
    $m = $s['maps'];
    if (self::has($b, 'googleMapsKey')) {
      $k = self::str($b['googleMapsKey'], 'Google Maps key', 100);
      if ($k !== '' && !preg_match('/^[\w-]{20,}$/', $k)) self::fail(400, 'That does not look like a Google Maps API key');
      $m['googleMapsKey'] = $k;
    }
    if (self::has($b, 'country')) $m['country'] = self::str($b['country'], 'Country', 60);
    if (self::has($b, 'weatherPlace')) {
      $place = self::str($b['weatherPlace'], 'Weather location', 100, true);
      if ($place !== $m['weatherPlace']) {
        $hit = TJ_Geo::geocode_place(array('city' => $place));
        if (!$hit) self::fail(400, "Couldn't find \"" . $place . "\" — try a city name like \"Nassau, Bahamas\"");
        $m['weatherPlace'] = $place;
        $m['weatherLat'] = round($hit['lat'] * 1e4) / 1e4;
        $m['weatherLng'] = round($hit['lng'] * 1e4) / 1e4;
      }
    }
    $s['maps'] = $m;
    TJ_Store::save_settings($s);
    return array('settings' => self::settings_view());
  }

  // ---------- log in & security ----------
  private static $admin_reset = false;
  public static function security_hooks() {
    // How long people stay logged in (admins can have a shorter time)
    add_filter('auth_cookie_expiration', function ($len, $uid) {
      $s = TJ_Store::settings()['security'];
      $u = TJ_Store::user_by_id($uid);
      $h = ($u && $u['role'] === 'admin') ? $s['adminSessionHours'] : $s['sessionHours'];
      return max(3600, (int) $h * 3600);
    }, 20, 2);
    add_filter('allow_password_reset', function ($allow) {
      if (self::$admin_reset) return $allow;
      $s = TJ_Store::settings()['security'];
      return empty($s['allowReset']) ? new WP_Error('no_password_reset', 'Password resets are turned off. Please contact support.') : $allow;
    }, 20);
    add_filter('password_reset_expiration', function () { return max(600, (int) TJ_Store::settings()['security']['resetMinutes'] * 60); });
    add_action('wp_login', function ($login, $user) { update_user_meta($user->ID, 'tj_last_seen', time()); }, 10, 2);
    // Log out after no activity
    add_action('init', function () {
      if (!is_user_logged_in() || wp_doing_cron()) return;
      $uid = get_current_user_id();
      $min = (int) TJ_Store::settings()['security']['idleMinutes'];
      $now = time();
      $last = (int) get_user_meta($uid, 'tj_last_seen', true);
      if ($min > 0 && $last && $now - $last > $min * 60) {
        wp_logout();
        wp_set_current_user(0);
        return;
      }
      if (!$last || $now - $last > 60) update_user_meta($uid, 'tj_last_seen', $now);
    }, 1);
  }
  public static function check_password($pw, $label = 'Password') {
    $n = (int) self::settings()['security']['minPassword'];
    if (strlen((string) $pw) < $n) self::fail(400, $label . ' must be at least ' . $n . ' characters');
    if (strlen((string) $pw) > 200) self::fail(400, $label . ' is too long');
  }
  public static function r_admin_security($c) {
    self::require_admin();
    $b = $c['body'];
    $s = self::settings();
    $sec = $s['security'];
    $rules = array(
      'sessionHours' => array(1, 2160, 'Pick how long people stay logged in'), 'adminSessionHours' => array(1, 2160, 'Pick how long administrators stay logged in'),
      'idleMinutes' => array(0, 1440, 'Pick an inactivity time'), 'maxAttempts' => array(0, 20, 'Wrong tries must be between 0 (off) and 20'),
      'lockMinutes' => array(1, 1440, 'Lock time must be between 1 minute and 1 day'), 'resetMinutes' => array(10, 1440, 'Reset links must work for between 10 minutes and 1 day'),
      'minPassword' => array(8, 64, 'Minimum password length must be between 8 and 64'),
    );
    foreach ($rules as $k => $r) {
      if (!self::has($b, $k)) continue;
      $n = self::numk($b, $k);
      if (!self::finite($n) || floor($n) != $n || $n < $r[0] || $n > $r[1]) self::fail(400, $r[2]);
      $sec[$k] = (int) $n;
    }
    if (self::has($b, 'allowReset')) $sec['allowReset'] = (bool) $b['allowReset'];
    $s['security'] = $sec;
    TJ_Store::save_settings($s);
    return array('settings' => self::settings_view());
  }
  public static function r_admin_logout_everyone($c) {
    $admin = self::require_admin();
    $mine = wp_get_session_token();
    $n = 0;
    foreach (get_users(array('fields' => 'ID')) as $id) {
      $m = WP_Session_Tokens::get_instance((int) $id);
      if ((int) $id === (int) $admin['id']) { if ($mine) $m->destroy_others($mine); continue; }
      if (count($m->get_all())) $n++;
      $m->destroy_all();
    }
    return array('ok' => true, 'count' => $n);
  }
  public static function r_admin_user_send_reset($c) {
    self::require_admin();
    $u = TJ_Store::user_by_id((int) $c['params']['id']);
    if (!$u) self::fail(404, 'User not found');
    if ($u['suspended']) self::fail(400, 'This account is suspended. Make it active first.');
    $wpu = get_userdata($u['id']);
    if (!function_exists('retrieve_password')) self::fail(500, 'Please update WordPress to send reset links');
    self::$admin_reset = true;
    $r = retrieve_password($wpu->user_login);
    self::$admin_reset = false;
    if (is_wp_error($r)) self::fail(500, 'The reset email could not be sent: ' . wp_strip_all_tags($r->get_error_message()));
    $min = (int) self::settings()['security']['resetMinutes'];
    $valid = $min % 1440 === 0 ? ($min / 1440) . ' day' . ($min === 1440 ? '' : 's') : ($min % 60 === 0 ? ($min / 60) . ' hour' . ($min === 60 ? '' : 's') : $min . ' minutes');
    return array('ok' => true, 'email' => $u['email'], 'valid' => $valid);
  }
  public static function r_admin_user_logout($c) {
    $admin = self::require_admin();
    $u = TJ_Store::user_by_id((int) $c['params']['id']);
    if (!$u) self::fail(404, 'User not found');
    $self = (int) $u['id'] === (int) $admin['id'];
    self::kill_sessions($u['id'], $self ? wp_get_session_token() : null);
    return array('ok' => true);
  }

  // ---------- preloader ----------
  public static $PRE_ICONS = array('logo', 'ticket', 'vinyl', 'ring', 'music', 'star', 'bolt', 'heart', 'custom');
  public static $PRE_ANIMS = array('spin', 'pulse', 'rumble', 'flash', 'bounce', 'flip', 'still');
  public static $PRE_SPEEDS = array('slow', 'normal', 'fast');
  public static $PRE_BGS = array('page', 'dim', 'clear');
  public static function r_admin_preloader($c) {
    self::require_admin();
    $b = $c['body'];
    $s = self::settings();
    $p = $s['preloader'];
    if (self::has($b, 'enabled')) $p['enabled'] = (bool) $b['enabled'];
    if (self::has($b, 'icon')) { if (!in_array($b['icon'], self::$PRE_ICONS, true)) self::fail(400, 'Pick one of the preloader icons'); $p['icon'] = $b['icon']; }
    if (self::has($b, 'customUrl')) {
      $u = trim((string) $b['customUrl']);
      if ($u !== '') {
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $uh = (string) wp_parse_url($u, PHP_URL_HOST);
        $path = (string) wp_parse_url($u, PHP_URL_PATH);
        if (!preg_match('~^https?://~i', $u) || strcasecmp($uh, $host) !== 0 || !preg_match('~\.(png|jpe?g|webp|gif)$~i', $path)) self::fail(400, 'Upload your preloader icon with the Upload button');
        $u = esc_url_raw($u);
      }
      $p['customUrl'] = $u;
    }
    if (self::has($b, 'size')) {
      $n = self::numk($b, 'size');
      if (!self::finite($n) || floor($n) != $n || $n < 24 || $n > 160) self::fail(400, 'Icon size must be between 24 and 160 pixels');
      $p['size'] = (int) $n;
    }
    $rules = array('animation' => array(self::$PRE_ANIMS, 'Pick one of the animations'), 'speed' => array(self::$PRE_SPEEDS, 'Pick a speed'), 'background' => array(self::$PRE_BGS, 'Pick a background'));
    foreach ($rules as $k => $rule) if (self::has($b, $k)) { if (!in_array($b[$k], $rule[0], true)) self::fail(400, $rule[1]); $p[$k] = $b[$k]; }
    if ($p['icon'] === 'custom' && $p['customUrl'] === '') self::fail(400, 'Upload your own icon first, or pick one of the built-in icons');
    $s['preloader'] = $p;
    TJ_Store::save_settings($s);
    do_action('litespeed_purge_all'); // page caches would otherwise keep showing the old preloader
    return array('settings' => self::settings_view());
  }

  public static function r_admin_locate_all($c) {
    self::require_admin();
    $missing = array();
    foreach (TJ_Store::events() as $e) if (!TJ_Geo::valid_lat_lng(self::g($e, 'lat'), self::g($e, 'lng'))) $missing[] = $e;
    $located = 0;
    foreach ($missing as $e) {
      $x = self::ensure_geo($e);
      if ($x && TJ_Geo::valid_lat_lng(self::g($x, 'lat'), self::g($x, 'lng'))) $located++;
    }
    return array('checked' => count($missing), 'located' => $located);
  }

  public static function r_admin_notifications_put($c) {
    self::require_admin();
    $b = $c['body'];
    $s = self::settings();
    $n = $s['notifications'];
    $set = function ($k, $max = 300) use ($b, &$n) { if (array_key_exists($k, $b)) $n[$k] = TJ_Rest::str($b[$k], $k, $max); };
    $set('siteUrl'); $set('adminEmail', 500); $set('emailFrom'); $set('replyTo'); $set('twilioSid', 64); $set('twilioFrom', 32);
    if ($n['siteUrl'] && !preg_match('~^https?://[^\s]+$~', $n['siteUrl'])) self::fail(400, 'Website address must start with http:// or https://');
    $n['siteUrl'] = rtrim($n['siteUrl'] ? $n['siteUrl'] : home_url(), '/');
    if ($n['adminEmail']) {
      foreach (array_filter(preg_split('/[,;\s]+/', $n['adminEmail'])) as $e) if (!self::valid_email($e)) self::fail(400, 'Admin notification email is not valid');
    }
    if ($n['twilioFrom']) { $p = TJ_Notify::normalize_phone($n['twilioFrom']); if (!$p) self::fail(400, 'Twilio phone number is not valid'); $n['twilioFrom'] = $p; }
    foreach (self::$SECRET_KEYS as $k) {
      $v = self::g($b, $k);
      if (is_string($v) && $v !== '' && strpos($v, '••••') !== 0) $n[$k] = trim($v);
      if ($v === '') $n[$k] = '';
    }
    if (self::has($b, 'emailEnabled')) $n['emailEnabled'] = (bool) $b['emailEnabled'];
    if (self::has($b, 'smsEnabled')) $n['smsEnabled'] = (bool) $b['smsEnabled'];
    if (is_array(self::g($b, 'adminAlerts'))) foreach (array_keys($n['adminAlerts']) as $k) if (array_key_exists($k, $b['adminAlerts'])) $n['adminAlerts'][$k] = (bool) $b['adminAlerts'][$k];
    $s['notifications'] = $n;
    TJ_Store::save_settings($s);
    return array('settings' => self::settings_view());
  }

  public static function r_admin_notifications_test($c) {
    $admin = self::require_admin();
    $b = $c['body'];
    if (self::g($b, 'channel') === 'sms') {
      $to = TJ_Notify::normalize_phone(self::g($b, 'to') ? $b['to'] : $admin['phone']);
      if (!$to) self::fail(400, 'Enter a mobile number to send the test text to');
      return array('result' => TJ_Notify::test_sms($to));
    }
    $to = self::str(self::g($b, 'to') ? $b['to'] : $admin['email'], 'Email', 160);
    return array('result' => TJ_Notify::test_email($to));
  }

  public static function r_admin_notifications($c) {
    self::require_admin();
    $channel = (string) self::q($c, 'channel');
    $q = strtolower((string) self::q($c, 'q'));
    $out = array();
    foreach (TJ_Store::messages() as $m) {
      if ($channel !== '' && $m['channel'] !== $channel) continue;
      if ($q !== '' && strpos(strtolower(implode(' ', array(self::g($m, 'to', ''), self::g($m, 'subject', ''), self::g($m, 'tag', '')))), $q) === false) continue;
      unset($m['html']);
      $out[] = $m;
      if (count($out) >= 200) break;
    }
    return array('notifications' => $out);
  }
  public static function r_admin_notification($c) {
    self::require_admin();
    $m = TJ_Store::get_message($c['params']['id']);
    if (!$m) self::fail(404, 'Message not found');
    return array('notification' => $m);
  }

  // ---------- sandbox (simulated sales for testing) ----------
  private static function lan_urls() {
    // Phones on the same Wi-Fi can open a locally hosted site through the server's private address.
    $addr = isset($_SERVER['SERVER_ADDR']) ? (string) $_SERVER['SERVER_ADDR'] : '';
    $port = isset($_SERVER['SERVER_PORT']) ? (int) $_SERVER['SERVER_PORT'] : 80;
    if (preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $addr)) return array('http://' . $addr . ($port && $port !== 80 ? ':' . $port : ''));
    return array();
  }
  private static function provider_status() {
    $n = TJ_Notify::N();
    return array('email' => !empty($n['emailEnabled']), 'sms' => !empty($n['smsEnabled']) && !empty($n['twilioSid']) && !empty($n['twilioToken']) && !empty($n['twilioFrom']), 'siteUrl' => TJ_Notify::site());
  }
  public static function r_sandbox($c) {
    self::require_admin();
    $evs = array();
    foreach (TJ_Store::events() as $e) if ($e['status'] !== 'cancelled') $evs[] = $e;
    self::sort_starts($evs);
    $events = array();
    foreach ($evs as $e) {
      $p = TJ_Store::user_by_id($e['organizerId']);
      $events[] = array('id' => $e['id'], 'title' => $e['title'], 'startsAt' => $e['startsAt'], 'status' => $e['status'], 'organizer' => (!empty($e['organizerName']) ? $e['organizerName'] : ($p ? ($p['organization'] ? $p['organization'] : $p['name']) : '')),
        'tiers' => array_map(function ($t) { return array('id' => $t['id'], 'name' => $t['name'], 'price' => $t['price']); }, $e['tiers']));
    }
    $tests = array();
    foreach (TJ_Store::orders() as $o) if (!empty($o['test'])) $tests[] = $o;
    self::sort_desc_created($tests);
    $testOrders = array();
    foreach ($tests as $o) $testOrders[] = array_merge(self::order_view($o), array('link' => '/#/t/' . $o['id'] . '/' . $o['key']));
    return array('events' => $events, 'testOrders' => $testOrders, 'providers' => self::provider_status(), 'lanUrls' => self::lan_urls());
  }

  public static function r_sandbox_sale($c) {
    $admin = self::require_admin();
    $b = $c['body'];
    $ev = TJ_Store::get_event((string) self::g($b, 'eventId'));
    if (!$ev) self::fail(400, 'Choose an event');
    $tier = null;
    foreach ($ev['tiers'] as $t) if ($t['id'] === self::g($b, 'tierId')) $tier = $t;
    if (!$tier) self::fail(400, 'Choose a ticket type');
    $qty = self::numk($b, 'qty');
    if (!self::is_integer_num($qty) || $qty < 1 || $qty > 10) self::fail(400, 'Quantity must be 1–10');
    $qty = (int) $qty;
    $buyerName = self::str(self::g($b, 'buyerName'), 'Buyer name', 80, true);
    $buyerEmail = self::str(self::g($b, 'buyerEmail'), 'Buyer email', 160, true);
    if (!self::valid_email($buyerEmail)) self::fail(400, 'Enter a valid email address');
    $buyerPhone = TJ_Notify::normalize_phone(self::g($b, 'buyerPhone'));
    if (!$buyerPhone) self::fail(400, 'Enter a valid mobile number for the test text');
    $subtotal = self::round2($tier['price'] * $qty);
    $fees = self::round2(self::ticket_fee($tier['price']) * $qty);
    $tickets = array();
    for ($i = 0; $i < $qty; $i++) $tickets[] = array('code' => self::ticket_code(), 'tierId' => $tier['id'], 'tierName' => $tier['name'], 'price' => $tier['price'], 'checkedIn' => false);
    $order = array_merge(array(
      'id' => TJ_Store::new_id('ord'), 'userId' => $admin['id'], 'eventId' => $ev['id'], 'test' => true,
      'items' => array(array('tierId' => $tier['id'], 'tierName' => $tier['name'], 'price' => $tier['price'], 'qty' => $qty)),
      'tickets' => $tickets, 'buyerName' => $buyerName, 'buyerEmail' => $buyerEmail, 'buyerPhone' => $buyerPhone,
      'subtotal' => self::n($subtotal), 'fees' => self::n($fees), 'total' => self::n(self::round2($subtotal + $fees)),
    ), $subtotal + $fees > 0 ? self::card_info('4242424242424242', $buyerName) : array('cardLast4' => '', 'cardBrand' => '', 'cardHolder' => '', 'cardFingerprint' => ''),
      array('status' => 'paid', 'key' => self::rand_hex(12), 'createdAt' => TJ_Store::now_iso()));
    // Test orders never change ticket inventory, sales totals or payouts.
    TJ_Store::save_order($order);
    TJ_Notify::with_sandbox(array('orderId' => $order['id'], 'testerEmail' => $admin['email']), function () use ($order, $ev) { TJ_Notify::order_placed($order, $ev); });
    return array('order' => array_merge(self::order_view($order), array('link' => '/#/t/' . $order['id'] . '/' . $order['key'])), 'providers' => self::provider_status(), 'lanUrls' => self::lan_urls());
  }

  public static function r_sandbox_messages($c) {
    self::require_admin();
    return array('messages' => TJ_Store::messages($c['params']['id']));
  }

  public static function r_sandbox_clear($c) {
    self::require_admin();
    return array('removedOrders' => TJ_Store::delete_test_orders(), 'removedMessages' => TJ_Store::delete_test_messages());
  }

  // =====================================================================
  // sales reports
  // =====================================================================
  private static function local_day($ms) { return wp_date('Y-m-d', (int) floor($ms / 1000)); }
  public static function build_report($events, $query, $withCards) {
    $qg = function ($k) use ($query) { return isset($query[$k]) && is_string($query[$k]) ? $query[$k] : null; };
    $evIds = array();
    foreach ($events as $e) $evIds[$e['id']] = true;
    $eventId = $qg('eventId') ? $qg('eventId') : '';
    $status = $qg('status') ? $qg('status') : 'paid';          // paid | refunded | all
    $includeTests = $qg('tests') === '1';
    $fromD = $qg('from') ? TJ_Store::ts_ms($qg('from') . 'T00:00:00') : null;
    $toD = $qg('to') ? TJ_Store::ts_ms($qg('to') . 'T23:59:59.999') : null;
    if ($fromD !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $qg('from'))) $fromD = NAN;
    if ($toD !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $qg('to'))) $toD = NAN;
    $orders = array();
    foreach (TJ_Store::orders() as $o) {
      if (!isset($evIds[$o['eventId']])) continue;
      if ($eventId !== '' && $o['eventId'] !== $eventId) continue;
      if (!($status === 'all' || ($status === 'refunded' ? $o['status'] === 'refunded' : $o['status'] !== 'refunded'))) continue;
      if (!$includeTests && !empty($o['test'])) continue;
      $t = TJ_Store::ts_ms($o['createdAt']);
      if ($fromD !== null && !($t >= $fromD)) continue;
      if ($toD !== null && !($t <= $toD)) continue;
      $orders[] = $o;
    }
    self::sort_desc_created($orders);
    $evById = array();
    foreach (TJ_Store::events() as $e) $evById[$e['id']] = $e;
    $sum = function ($list, $f) { $s = 0; foreach ($list as $x) $s += $f($x); return TJ_Rest::n(TJ_Rest::round2($s)); };
    $paid = array_values(array_filter($orders, function ($o) { return $o['status'] !== 'refunded'; }));
    $refunded = array_values(array_filter($orders, function ($o) { return $o['status'] === 'refunded'; }));
    $cardKey = function ($o) { return !empty($o['cardFingerprint']) ? $o['cardFingerprint'] : (!empty($o['cardLast4']) ? 'legacy_' . $o['cardLast4'] : ''); };
    $sales = array_values(array_filter($paid, function ($o) { return empty($o['comp']); }));
    $comps = array_values(array_filter($paid, function ($o) { return !empty($o['comp']); }));
    $nt = function ($list) { $n = 0; foreach ($list as $o) $n += count($o['tickets']); return $n; };
    $nci = function ($list) { $n = 0; foreach ($list as $o) foreach ($o['tickets'] as $t) if (!empty($t['checkedIn'])) $n++; return $n; };
    $buyers = array(); foreach ($sales as $o) $buyers[strtolower($o['buyerEmail'])] = true;
    $cards = array(); foreach ($paid as $o) { $k = $cardKey($o); if ($k) $cards[$k] = true; }
    $salesTotal = 0; foreach ($sales as $o) $salesTotal += $o['total'];
    $summary = array(
      'orders' => count($sales), 'tickets' => $nt($sales),
      'compOrders' => count($comps), 'compTickets' => $nt($comps),
      'grossSales' => $sum($paid, function ($o) { return $o['subtotal']; }), 'fees' => $sum($paid, function ($o) { return $o['fees']; }), 'totalCollected' => $sum($paid, function ($o) { return $o['total']; }),
      'refundedOrders' => count($refunded), 'refundedAmount' => $sum($refunded, function ($o) { return $o['total']; }),
      'buyers' => count($buyers), 'cards' => count($cards),
      'avgOrder' => count($sales) ? self::n(self::round2(self::round2($salesTotal) / count($sales))) : 0,
      'checkedIn' => $nci($paid),
    );

    // per event
    $byEvent = array();
    foreach ($events as $e) {
      if ($eventId !== '' && $e['id'] !== $eventId) continue;
      $os = array_values(array_filter($orders, function ($o) use ($e) { return $o['eventId'] === $e['id']; }));
      $pAll = array_values(array_filter($os, function ($o) { return $o['status'] !== 'refunded'; }));
      $p = array_values(array_filter($pAll, function ($o) { return empty($o['comp']); }));
      $pc = array_values(array_filter($pAll, function ($o) { return !empty($o['comp']); }));
      $prom = TJ_Store::user_by_id($e['organizerId']);
      $cap = 0; $soldAll = 0;
      foreach ($e['tiers'] as $t) { $cap += $t['quantity']; $soldAll += $t['sold']; }
      $tiers = array();
      foreach ($e['tiers'] as $t) {
        $q = 0; $cq = 0; $rev = 0;
        foreach ($p as $o) foreach ($o['items'] as $i) if ($i['tierId'] === $t['id']) { $q += $i['qty']; $rev += $i['price'] * $i['qty']; }
        foreach ($pc as $o) foreach ($o['items'] as $i) if ($i['tierId'] === $t['id']) $cq += $i['qty'];
        $tiers[] = array('name' => $t['name'] . (self::g($t, 'visibility') === 'private' ? ' (private)' : '') . (self::tier_kind($t) === 'donation' ? ' (donation)' : ''), 'price' => $t['price'], 'quantity' => $t['quantity'], 'sold' => $q, 'comps' => $cq, 'revenue' => self::n(self::round2($rev)));
      }
      $r = array(
        'id' => $e['id'], 'title' => $e['title'], 'startsAt' => $e['startsAt'], 'venue' => $e['venue'], 'status' => $e['status'], 'organizer' => (!empty($e['organizerName']) ? $e['organizerName'] : ($prom ? ($prom['organization'] ? $prom['organization'] : $prom['name']) : '')),
        'capacity' => $cap, 'soldAllTime' => $soldAll,
        'orders' => count($p), 'tickets' => $nt($p),
        'gross' => $sum($p, function ($o) { return $o['subtotal']; }), 'fees' => $sum($p, function ($o) { return $o['fees']; }), 'total' => $sum($p, function ($o) { return $o['total']; }),
        'comps' => $nt($pc),
        'refunds' => count($os) - count($pAll), 'checkedIn' => $nci($pAll),
        'tiers' => $tiers,
      );
      if ($eventId !== '' || $r['orders'] || $r['comps'] || $r['refunds'] || $r['soldAllTime'] || (isset($evById[$r['id']]) && $evById[$r['id']]['status'] === 'published')) $byEvent[] = $r;
    }

    // per day (sales date)
    $dayMap = array();
    foreach ($sales as $o) {
      $k = self::local_day(TJ_Store::ts_ms($o['createdAt']));
      if (!isset($dayMap[$k])) $dayMap[$k] = array('date' => $k, 'orders' => 0, 'tickets' => 0, 'total' => 0);
      $dayMap[$k]['orders']++;
      $dayMap[$k]['tickets'] += count($o['tickets']);
      $dayMap[$k]['total'] = self::n(self::round2($dayMap[$k]['total'] + $o['total']));
    }
    ksort($dayMap, SORT_STRING);
    $byDay = array_values($dayMap);

    // per card (admin only)
    $byCard = array();
    if ($withCards) {
      $m = array();
      foreach ($orders as $o) {
        $k = $cardKey($o);
        if (!$k) continue;
        if (!isset($m[$k])) $m[$k] = array('fingerprint' => $k, 'brand' => !empty($o['cardBrand']) ? $o['cardBrand'] : 'Card', 'last4' => self::g($o, 'cardLast4'), 'holders' => array(), 'emails' => array(), 'events' => array(), 'orders' => 0, 'tickets' => 0, 'total' => 0, 'refunds' => 0, 'first' => $o['createdAt'], 'last' => $o['createdAt']);
        $r = &$m[$k];
        if ($o['status'] === 'refunded') $r['refunds']++;
        else { $r['orders']++; $r['tickets'] += count($o['tickets']); $r['total'] = self::n(self::round2($r['total'] + $o['total'])); }
        if (!empty($o['cardHolder'])) $r['holders'][$o['cardHolder']] = true;
        $r['emails'][$o['buyerEmail']] = true;
        $r['events'][isset($evById[$o['eventId']]) ? $evById[$o['eventId']]['title'] : 'Deleted event'] = true;
        if (strcmp($o['createdAt'], $r['first']) < 0) $r['first'] = $o['createdAt'];
        if (strcmp($o['createdAt'], $r['last']) > 0) $r['last'] = $o['createdAt'];
        unset($r);
      }
      foreach ($m as $r) {
        $flags = array();
        if ($r['tickets'] >= 12) $flags[] = 'Many tickets';
        if (count($r['emails']) > 1) $flags[] = 'Used by several emails';
        if (count($r['holders']) > 1) $flags[] = 'Different names';
        if ($r['refunds']) $flags[] = 'Has refunds';
        $r['holders'] = array_map('strval', array_keys($r['holders']));
        $r['emails'] = array_map('strval', array_keys($r['emails']));
        $r['events'] = array_map('strval', array_keys($r['events']));
        $r['flags'] = $flags;
        $byCard[] = $r;
      }
      usort($byCard, function ($a, $b) { if ($b['tickets'] != $a['tickets']) return $b['tickets'] - $a['tickets']; return $b['total'] == $a['total'] ? 0 : ($b['total'] > $a['total'] ? 1 : -1); });
    }

    // transactions
    $transactions = array();
    foreach ($orders as $o) {
      $e = isset($evById[$o['eventId']]) ? $evById[$o['eventId']] : null;
      $ci = 0; foreach ($o['tickets'] as $t) if (!empty($t['checkedIn'])) $ci++;
      $row = array_merge(array(
        'id' => $o['id'], 'ref' => strtoupper(substr($o['id'], -8)), 'createdAt' => $o['createdAt'], 'status' => $o['status'], 'test' => !empty($o['test']), 'comp' => !empty($o['comp']), 'compNote' => (string) self::g($o, 'compNote', ''), 'issuedBy' => (string) self::g($o, 'issuedBy', ''),
        'eventId' => $o['eventId'], 'event' => $e ? $e['title'] : 'Deleted event', 'eventDate' => $e ? $e['startsAt'] : null,
        'buyerName' => $o['buyerName'],
      ), self::split_name($o), array(
        'buyerEmail' => $o['buyerEmail'], 'buyerPhone' => (string) self::g($o, 'buyerPhone', ''),
        'items' => array_map(function ($i) { return array('name' => $i['tierName'], 'qty' => $i['qty'], 'price' => $i['price']); }, $o['items']),
        'qty' => count($o['tickets']), 'codes' => array_map(function ($t) { return $t['code']; }, $o['tickets']), 'checkedIn' => $ci,
        'subtotal' => $o['subtotal'], 'fees' => $o['fees'], 'total' => $o['total'], 'refundedAt' => self::g($o, 'refundedAt') ? $o['refundedAt'] : null,
      ));
      if ($withCards) $row = array_merge($row, array('cardBrand' => (string) self::g($o, 'cardBrand', ''), 'cardLast4' => (string) self::g($o, 'cardLast4', ''), 'cardHolder' => (string) self::g($o, 'cardHolder', ''), 'cardFingerprint' => $cardKey($o)));
      $transactions[] = $row;
    }

    $evList = array_map(function ($e) { return array('id' => $e['id'], 'title' => $e['title'], 'startsAt' => $e['startsAt']); }, $events);
    self::sort_starts($evList, true);
    return array(
      'generatedAt' => TJ_Store::now_iso(), 'filters' => array('eventId' => $eventId, 'status' => $status, 'from' => $qg('from') ? $qg('from') : '', 'to' => $qg('to') ? $qg('to') : '', 'tests' => $includeTests),
      'events' => $evList, 'summary' => $summary, 'byEvent' => $byEvent, 'byDay' => $byDay, 'byCard' => $byCard, 'transactions' => $transactions, 'withCards' => (bool) $withCards,
    );
  }

  public static function r_admin_reports($c) {
    self::require_admin();
    return array('report' => self::build_report(TJ_Store::events(), $c['query'], true));
  }
  private static function my_events($user) {
    $out = array();
    foreach (TJ_Store::events() as $e) if ($e['organizerId'] === $user['id']) $out[] = $e;
    return $out;
  }
  /** Organizers see reports for their own events (buyer details, no card data) */
  public static function r_org_reports($c) {
    $user = self::require_organizer();
    return array('report' => self::build_report(self::my_events($user), $c['query'], false));
  }

  // ---------- report exports (PDF / Word / Excel / CSV) ----------
  private static function col($label, $width, $type = null) { $c = array('label' => $label, 'width' => $width); if ($type) $c['type'] = $type; return $c; }
  public static function report_model($r, $sections, $who) {
    $want = function ($k) use ($sections) { return in_array($k, $sections, true); };
    $single = $r['filters']['eventId'] ? (isset($r['byEvent'][0]) ? $r['byEvent'][0] : null) : null;
    $day = function ($s) { $ms = TJ_Store::ts_ms($s); return is_nan($ms) ? 'Invalid Date' : wp_date('M j, Y', (int) floor($ms / 1000)); };
    $f = $r['filters'];
    $period = ($f['from'] || $f['to']) ? ($f['from'] ? $day($f['from'] . 'T12:00') : 'Start') . ' – ' . ($f['to'] ? $day($f['to'] . 'T12:00') : 'Today') : 'All time';
    $statusTxt = $f['status'] === 'all' ? 'Paid + refunded orders' : ($f['status'] === 'refunded' ? 'Refunded orders' : 'Paid orders');
    $s = $r['summary'];
    $wc = $r['withCards'];
    $gen = TJ_Store::ts_ms($r['generatedAt']);
    $model = array(
      'title' => 'Sales Report — ' . ($single ? $single['title'] : 'All Events'),
      'subtitle' => $period . ' · ' . $statusTxt . ($f['tests'] ? ' · incl. sandbox tests' : '') . ' · ' . $who . ' · generated ' . wp_date('M j, Y, g:i A', (int) floor($gen / 1000)),
      'meta' => array(), 'sections' => array(),
    );
    if ($want('summary')) {
      $meta = array(array('Tickets sold', $s['tickets']), array('Orders', $s['orders']), array('Ticket sales', $s['grossSales']), array($wc ? 'Platform fees' : 'Service fees (buyers)', $s['fees']),
        array('Total collected', $s['totalCollected']), array('Avg order', $s['avgOrder']), array('Unique buyers', $s['buyers']));
      if ($wc) $meta[] = array('Unique cards', $s['cards']);
      $meta = array_merge($meta, array(array('Complimentary tickets', $s['compTickets']), array('Refunded orders', $s['refundedOrders']), array('Refunded amount', $s['refundedAmount']), array('Tickets checked in', $s['checkedIn'])));
      $model['meta'] = $meta;
    }
    if ($want('days') && $r['byDay']) {
      $model['sections'][] = array('title' => 'Sales by Day', 'sheet' => 'By Day', 'columns' => array(self::col('Date', 16), self::col('Orders', 9, 'int'), self::col('Tickets', 9, 'int'), self::col('Collected', 12, 'money')),
        'rows' => array_map(function ($d) use ($day) { return array($day($d['date'] . 'T12:00'), $d['orders'], $d['tickets'], $d['total']); }, $r['byDay']), 'totals' => array('Total', $s['orders'], $s['tickets'], $s['totalCollected']));
    }
    if ($want('events')) {
      if ($single) {
        $model['sections'][] = array('title' => 'Ticket Types — ' . $single['title'], 'sheet' => 'Ticket Types', 'columns' => array(self::col('Ticket type', 24), self::col('Price', 10, 'money'), self::col('Sold', 8, 'int'), self::col('Comps', 8, 'int'), self::col('Capacity', 9, 'int'), self::col('Revenue', 12, 'money')),
          'rows' => array_map(function ($t) { return array($t['name'], $t['price'], $t['sold'], $t['comps'] ? $t['comps'] : 0, $t['quantity'], $t['revenue']); }, $single['tiers']),
          'totals' => array('Total', '', $single['tickets'], $single['comps'] ? $single['comps'] : 0, $single['capacity'], $single['gross']));
      } else {
        $cols = array(self::col('Event', 26), self::col('Event date', 15, 'date'));
        if ($wc) $cols[] = self::col('Organizer', 18);
        $cols = array_merge($cols, array(self::col('Orders', 8, 'int'), self::col('Tickets', 8, 'int'), self::col('Sold (all time)', 10, 'int'), self::col('Capacity', 9, 'int'), self::col('Ticket sales', 12, 'money'), self::col('Fees', 10, 'money'), self::col('Refunds', 8, 'int'), self::col('Checked in', 9, 'int')));
        $rows = array();
        foreach ($r['byEvent'] as $e) $rows[] = array_merge(array($e['title'], $e['startsAt']), $wc ? array($e['organizer']) : array(), array($e['orders'], $e['tickets'], $e['soldAllTime'], $e['capacity'], $e['gross'], $e['fees'], $e['refunds'], $e['checkedIn']));
        $model['sections'][] = array('title' => 'Sales by Event', 'sheet' => 'By Event', 'columns' => $cols, 'rows' => $rows,
          'totals' => array_merge(array('Total', ''), $wc ? array('') : array(), array($s['orders'], $s['tickets'], '', '', $s['grossSales'], $s['fees'], $s['refundedOrders'], $s['checkedIn'])));
      }
    }
    if ($want('cards') && $wc) {
      $model['sections'][] = array('title' => 'Tickets Purchased per Card', 'sheet' => 'Per Card', 'columns' => array(
        self::col('Card', 15), self::col('Name(s) on card', 16), self::col('Buyer email(s)', 22), self::col('Orders', 7, 'int'), self::col('Refunds', 8, 'int'),
        self::col('Tickets', 7, 'int'), self::col('Total spent', 11, 'money'), self::col('Events', 22), self::col('First purchase', 14, 'date'), self::col('Last purchase', 14, 'date'), self::col('Flags', 16)),
        'rows' => array_map(function ($c) { return array($c['brand'] . ' •••• ' . $c['last4'], implode(', ', $c['holders']), implode(', ', $c['emails']), $c['orders'], $c['refunds'], $c['tickets'], $c['total'], implode(', ', $c['events']), $c['first'], $c['last'], implode(', ', $c['flags'])); }, $r['byCard']));
    }
    if ($want('transactions')) {
      $cols = array(self::col('Sale date', 14, 'date'), self::col('Order #', 9));
      if (!$single) $cols[] = self::col('Event', 18);
      $cols = array_merge($cols, array(self::col('Buyer', 14), self::col('Email', 20), self::col('Phone', 12)));
      if ($wc) $cols = array_merge($cols, array(self::col('Card', 13), self::col('Name on card', 13)));
      $cols = array_merge($cols, array(self::col('Qty', 5, 'int'), self::col('Ticket types', 15), self::col('Ticket codes', 16), self::col('Subtotal', 9, 'money'), self::col('Fees', 8, 'money'), self::col('Total', 9, 'money'), self::col('Status', 9)));
      $rows = array(); $tq = 0; $ts = 0; $tf = 0; $tt = 0;
      foreach ($r['transactions'] as $t) {
        $row = array($t['createdAt'], $t['ref'] . ($t['test'] ? ' (test)' : ''));
        if (!$single) $row[] = $t['event'];
        $row = array_merge($row, array($t['buyerName'], $t['buyerEmail'], $t['buyerPhone']));
        if ($wc) $row = array_merge($row, array($t['cardLast4'] ? ($t['cardBrand'] ? $t['cardBrand'] : 'Card') . ' •••• ' . $t['cardLast4'] : 'Free', $t['cardHolder']));
        $row = array_merge($row, array($t['qty'], implode(', ', array_map(function ($i) { return $i['qty'] . '× ' . $i['name']; }, $t['items'])), implode(', ', $t['codes']),
          $t['subtotal'], $t['fees'], $t['total'], $t['status'] === 'refunded' ? 'Refunded' : ($t['comp'] ? 'Complimentary' : 'Paid')));
        $rows[] = $row;
        $tq += $t['qty']; $ts += $t['subtotal']; $tf += $t['fees']; $tt += $t['total'];
      }
      $totals = array('Total', '');
      if (!$single) $totals[] = '';
      $totals = array_merge($totals, array('', '', ''));
      if ($wc) $totals = array_merge($totals, array('', ''));
      $totals = array_merge($totals, array($tq, '', '', self::n(self::round2($ts)), self::n(self::round2($tf)), self::n(self::round2($tt)), ''));
      $model['sections'][] = array('title' => 'Transactions', 'sheet' => 'Transactions', 'columns' => $cols, 'rows' => $rows, 'totals' => $totals);
    }
    if ($want('attendees')) {
      $rows = array();
      foreach ($r['transactions'] as $t) {
        $types = array();
        foreach ($t['items'] as $i) for ($k = 0; $k < $i['qty']; $k++) $types[] = $i;
        foreach ($t['codes'] as $k => $code) {
          $row = array($code);
          if (!$single) $row[] = $t['event'];
          $rows[] = array_merge($row, array(isset($types[$k]) ? $types[$k]['name'] : '', isset($types[$k]) ? $types[$k]['price'] : 0, $t['buyerFirstName'], $t['buyerLastName'], $t['buyerEmail'], $t['buyerPhone'], $t['ref'], $t['createdAt'],
            $t['status'] === 'refunded' ? 'Refunded (void)' : ($t['comp'] ? 'Valid (comp)' : 'Valid')));
        }
      }
      $cols = array(self::col('Ticket code', 12));
      if (!$single) $cols[] = self::col('Event', 20);
      $cols = array_merge($cols, array(self::col('Ticket type', 14), self::col('Price', 8, 'money'), self::col('First name', 11), self::col('Last name', 11),
        self::col('Email', 22), self::col('Phone', 13), self::col('Order #', 9), self::col('Purchased', 14, 'date'), self::col('Ticket status', 11)));
      $model['sections'][] = array('title' => 'Attendee List (one row per ticket)', 'sheet' => 'Attendees', 'columns' => $cols, 'rows' => $rows);
    }
    return $model;
  }

  private static function send_export($r, $query, $who) {
    $types = array(
      'pdf' => array('application/pdf', 'pdf'),
      'docx' => array('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx'),
      'xlsx' => array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'),
      'csv' => array('text/csv; charset=utf-8', 'csv'),
    );
    $fmt = isset($query['format']) && is_string($query['format']) ? $query['format'] : '';
    if (!isset($types[$fmt])) self::fail(400, 'Choose PDF, Word, Excel or CSV');
    $raw = isset($query['sections']) && is_string($query['sections']) && $query['sections'] !== '' ? $query['sections'] : implode(',', self::$export_sections);
    $picked = array_values(array_filter(explode(',', $raw), function ($x) { return in_array($x, TJ_Rest::export_sections(), true); }));
    if (!$picked) self::fail(400, 'Choose at least one section to export');
    if (!class_exists('TJ_Export')) self::fail(500, 'Exports are not available');
    $model = self::report_model($r, $picked, $who);
    $buf = call_user_func(array('TJ_Export', $fmt), $model);
    $single = $r['filters']['eventId'] ? (isset($r['byEvent'][0]) ? $r['byEvent'][0]['title'] : null) : 'all-events';
    $slug = substr(trim(preg_replace('/[^\w]+/', '-', (string) ($single ? $single : 'event')), '-'), 0, 50);
    $name = 'TicketJunkies-report-' . $slug . '-' . gmdate('Y-m-d') . '.' . $types[$fmt][1];
    self::send_binary($types[$fmt][0], $buf, $name);
  }
  public static function export_sections() { return self::$export_sections; }
  public static function r_admin_export($c) {
    self::require_admin();
    self::send_export(self::build_report(TJ_Store::events(), $c['query'], true), $c['query'], 'Administrator report');
  }
  public static function r_org_export($c) {
    $user = self::require_organizer();
    self::send_export(self::build_report(self::my_events($user), $c['query'], false), $c['query'], $user['organization'] ? $user['organization'] : $user['name']);
  }

  // =====================================================================
  // QR images
  // =====================================================================
  private static function qr_plain($status, $msg) {
    while (ob_get_level() > 0) @ob_end_clean();
    status_header($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo esc_html($msg);
    exit;
  }
  public static function r_qr_code($c) {
    if (!class_exists('TJ_QR')) self::fail(500, 'QR codes are not available');
    $code = strtoupper($c['params']['code']);
    $png = strtolower($c['params']['ext']) === 'png';
    self::send_binary($png ? 'image/png' : 'image/svg+xml', $png ? TJ_QR::png($code, 10) : TJ_QR::svg($code), null, 'public, max-age=31536000, immutable');
  }
  /** QR of a ticket-page link (used by the sandbox to open tickets on a phone) */
  public static function r_qr_link($c) {
    $u = (string) self::q($c, 'u');
    if (strlen($u) > 250 || !preg_match('~^https?://[\w.:-]+(/[\w./-]*)?#/t/ord_[0-9a-f]+/[0-9a-f]+$~', $u)) self::qr_plain(400, 'Bad link');
    if (!class_exists('TJ_QR')) self::fail(500, 'QR codes are not available');
    self::send_binary('image/svg+xml', TJ_QR::svg($u), null, 'no-store');
  }
  /** QR code of an event's share link (for posters / flyers) */
  public static function r_qr_event($c) {
    $id = (string) self::q($c, 'id');
    if (!preg_match('/^evt_[0-9a-f]+$/', $id) || !TJ_Store::get_event($id)) self::qr_plain(404, 'Not found');
    if (!class_exists('TJ_QR')) self::fail(500, 'QR codes are not available');
    self::send_binary('image/svg+xml', TJ_QR::svg(self::share_url($id)), null, 'public, max-age=3600');
  }

  // =====================================================================
  // share pages (link previews for WhatsApp, Facebook, X, iMessage…)
  // =====================================================================
  public static function share_url($id) {
    return get_option('permalink_structure') ? home_url('/e/' . $id) : add_query_arg('tj_share', $id, home_url('/'));
  }
  private static function origin() {
    $p = wp_parse_url(home_url());
    return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
  }
  private static function asset_url($candidates) {
    if (!defined('TJ_DIR')) return '';
    foreach ($candidates as $p) if (file_exists(TJ_DIR . $p)) return TJ_URL . $p;
    return '';
  }
  public static function share_redirect() {
    $id = get_query_var('tj_share');
    if (!$id && isset($_GET['tj_share'])) $id = sanitize_text_field(wp_unslash($_GET['tj_share']));
    if (!$id) {
      // fallback when rewrite rules have not been flushed yet
      $path = wp_parse_url(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH);
      $home = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
      if ($path && preg_match('~^' . preg_quote($home, '~') . '/e/(evt_[0-9a-f]+)/?$~', $path, $m)) $id = $m[1];
    }
    if (!$id || !preg_match('/^evt_[0-9a-f]+$/', $id)) return;
    self::share_page($id);
    exit;
  }
  public static function share_page($id) {
    $ev = TJ_Store::get_event($id);
    if ($ev && $ev['status'] === 'draft') $ev = null;
    $h = array('TJ_Notify', 'h');
    $origin = self::origin();
    $target = $ev ? (function_exists('tj_route_url') ? tj_route_url('#/event/' . $ev['id']) : home_url('/#/event/' . $ev['id'])) : home_url('/');
    $title = $ev ? $ev['title'] : 'TicketJunkies.com';
    $price = '';
    if ($ev) {
      $open = array();
      foreach ($ev['tiers'] as $t) if (self::is_public_tier($t) && $t['quantity'] > $t['sold']) $open[] = $t['price'];
      if (!$open) $price = 'Sold out';
      else { $min = min($open); $price = $min == 0 ? 'Free entry' : 'Tickets from $' . number_format($min, 2, '.', ''); }
    }
    if ($ev) {
      $descTxt = preg_replace('/\s+/u', ' ', (string) $ev['description']);
      $descTxt = function_exists('mb_substr') ? mb_substr($descTxt, 0, 140) : substr($descTxt, 0, 140);
      $desc = '📅 ' . TJ_Notify::when($ev['startsAt']) . ' · 📍 ' . implode(', ', array_filter(array($ev['venue'], $ev['city']), 'strlen')) . ($price ? ' · 🎟️ ' . $price : '') . ($ev['status'] === 'cancelled' ? ' · CANCELLED' : '') . '. ' . $descTxt;
    } else $desc = 'Discover events and buy tickets on TicketJunkies.com';
    $og_cat = array('Music' => 'music', 'Nightlife' => 'nightlife', 'Comedy' => 'comedy', 'Food & Drink' => 'food', 'Sports' => 'sports', 'Arts' => 'arts', 'Business' => 'business', 'Community' => 'community');
    $flyer = $ev ? (self::g($ev, 'imageShareUrl') ? $ev['imageShareUrl'] : self::g($ev, 'imageUrl', '')) : '';
    if ($flyer) $img = strpos($flyer, '/') === 0 ? $origin . $flyer : $flyer;
    else {
      $cat = $ev && isset($og_cat[$ev['category']]) ? $og_cat[$ev['category']] : 'default';
      $img = self::asset_url(array('assets/og/' . $cat . '.png', 'assets/img/og/' . $cat . '.png', 'assets/app/og/' . $cat . '.png', 'assets/og/default.png'));
      if (!$img) $img = TJ_Notify::logo_url();
    }
    $url = $ev ? self::share_url($ev['id']) : home_url('/e/');
    $logo = TJ_Notify::logo_url();
    $icon = function_exists('get_site_icon_url') && get_site_icon_url() ? get_site_icon_url() : self::asset_url(array('assets/favicon.png', 'assets/img/favicon.png', 'assets/app/favicon.png'));
    $e = function ($s) use ($h) { return call_user_func($h, $s); };
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">
<title>' . $e($title) . ' | TicketJunkies.com</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="' . $e($desc) . '">
<link rel="canonical" href="' . $e($url) . '">
<meta property="og:type" content="website"><meta property="og:site_name" content="TicketJunkies.com">
<meta property="og:title" content="' . $e($title) . '"><meta property="og:description" content="' . $e($desc) . '">
<meta property="og:url" content="' . $e($url) . '"><meta property="og:image" content="' . $e($img) . '"><meta property="og:image:alt" content="' . $e($title) . '">
' . ($ev && self::g($ev, 'imageUrl') ? '' : '<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">') . '
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="' . $e($title) . '"><meta name="twitter:description" content="' . $e($desc) . '"><meta name="twitter:image" content="' . $e($img) . '">
' . ($icon ? '<link rel="icon" href="' . $e($icon) . '">' : '') . '
<meta http-equiv="refresh" content="0;url=' . $e($target) . '">
<script>location.replace(' . wp_json_encode($target) . ');</script>
</head><body style="font-family:system-ui,sans-serif;text-align:center;padding:60px 20px"><img src="' . $e($logo) . '" alt="TicketJunkies.com" style="max-width:320px"><p>Opening <a href="' . $e($target) . '">' . $e($title) . '</a>…</p></body></html>';
    if ($ev) { $ev['shareViews'] = (int) self::g($ev, 'shareViews', 0) + 1; TJ_Store::save_event($ev); }
    status_header($ev ? 200 : 404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    echo $html; // all dynamic values escaped above
  }
}

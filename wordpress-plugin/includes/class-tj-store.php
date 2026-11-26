<?php if (!defined('ABSPATH')) exit;
/**
 * TicketJunkies data layer: custom tables (events, orders, message log), settings option, roles, seed data.
 * Every row stores the full Node-shaped object as JSON in `data`, plus a few indexed columns.
 */
class TJ_Store {
  const DB_VERSION = '1.0.1';
  const RECAPTCHA_TEST_SITE = '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI';   // Google's public test keys
  const RECAPTCHA_TEST_SECRET = '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe';
  const LOG_LIMIT = 300;

  public static $CATEGORIES = array('Music', 'Nightlife', 'Comedy', 'Food & Drink', 'Sports', 'Arts', 'Business', 'Community');

  private static $events_cache = null;
  private static $orders_cache = null;
  private static $settings_cache = null;

  // ---------- tables ----------
  public static function table($name) {
    global $wpdb;
    return $wpdb->prefix . 'tj_' . $name;
  }

  public static function install() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $cc = $wpdb->get_charset_collate();
    $ev = self::table('events');
    $or = self::table('orders');
    $ms = self::table('messages');
    dbDelta("CREATE TABLE $ev (
  id varchar(40) NOT NULL,
  organizer_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'draft',
  starts_at varchar(40) NOT NULL DEFAULT '',
  ends_at varchar(40) NOT NULL DEFAULT '',
  created_at varchar(40) NOT NULL DEFAULT '',
  data longtext NOT NULL,
  PRIMARY KEY  (id),
  KEY organizer_id (organizer_id),
  KEY status (status)
) $cc;");
    dbDelta("CREATE TABLE $or (
  id varchar(40) NOT NULL,
  event_id varchar(40) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NULL,
  status varchar(20) NOT NULL DEFAULT 'paid',
  test tinyint(1) NOT NULL DEFAULT 0,
  buyer_email varchar(190) NOT NULL DEFAULT '',
  created_at varchar(40) NOT NULL DEFAULT '',
  data longtext NOT NULL,
  PRIMARY KEY  (id),
  KEY event_id (event_id),
  KEY user_id (user_id),
  KEY buyer_email (buyer_email)
) $cc;");
    dbDelta("CREATE TABLE $ms (
  seq bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  id varchar(40) NOT NULL,
  created_at varchar(40) NOT NULL DEFAULT '',
  channel varchar(10) NOT NULL DEFAULT '',
  tag varchar(60) NOT NULL DEFAULT '',
  test tinyint(1) NOT NULL DEFAULT 0,
  ref varchar(40) NOT NULL DEFAULT '',
  data longtext NOT NULL,
  PRIMARY KEY  (seq),
  UNIQUE KEY id (id),
  KEY ref (ref)
) $cc;");

    // roles
    if (!get_role('tj_organizer')) add_role('tj_organizer', 'Event Organizer', array('read' => true, 'upload_files' => true));
    else { $r = get_role('tj_organizer'); $r->add_cap('read'); $r->add_cap('upload_files'); }
    if (!get_role('tj_attendee')) add_role('tj_attendee', 'Event Attendee', array('read' => true));

    // settings (fills in missing keys, keeps existing values)
    self::$settings_cache = null;
    self::save_settings(self::settings());

    self::seed();
    update_option('tj_db_version', self::DB_VERSION);
    // share-page rewrite rule must exist before flush_rewrite_rules() runs in the activation hook
    if (class_exists('TJ_Rest')) TJ_Rest::add_rewrite();
    update_option('tj_flush_rewrite', 1);
  }

  public static function maybe_upgrade() {
    if (get_option('tj_db_version') !== self::DB_VERSION) self::install();
  }

  // ---------- settings ----------
  public static function defaults() {
    return array(
      'feePercent' => 0.035,
      'feeFixed' => 0.99,
      'notifications' => array(
        'siteUrl' => '' /* empty = follow home_url() */, 'adminEmail' => '', 'emailFrom' => 'TicketJunkies <tickets@ticketjunkies.com>', 'replyTo' => '',
        'emailEnabled' => false, 'resendApiKey' => '', 'smsEnabled' => false, 'twilioSid' => '', 'twilioToken' => '', 'twilioFrom' => '',
        'adminAlerts' => array('userSignup' => true, 'organizerSignup' => true, 'ticketSale' => true, 'eventCreated' => true),
      ),
      'cardHashKey' => '',
      'captcha' => array(
        'enabled' => true, 'protectRegister' => true, 'protectCheckout' => true,
        'siteKey' => self::RECAPTCHA_TEST_SITE, 'secretKey' => self::RECAPTCHA_TEST_SECRET,
      ),
      'maps' => array(
        'googleMapsKey' => '', 'country' => 'Bahamas',
        'weatherPlace' => 'Nassau, Bahamas', 'weatherLat' => 25.0582, 'weatherLng' => -77.3431,
      ),
      'security' => array('sessionHours' => 720, 'adminSessionHours' => 720, 'idleMinutes' => 0, 'maxAttempts' => 5, 'lockMinutes' => 15, 'allowReset' => true, 'resetMinutes' => 60, 'minPassword' => 8),
      'preloader' => array('enabled' => true, 'icon' => 'logo', 'customUrl' => '', 'size' => 56, 'animation' => 'pulse', 'speed' => 'normal', 'background' => 'page'),
    );
  }

  public static function settings() {
    if (self::$settings_cache !== null) return self::$settings_cache;
    $s = get_option('tj_settings');
    if (!is_array($s)) $s = array();
    $d = self::defaults();
    $out = $d;
    foreach (array('feePercent', 'feeFixed', 'cardHashKey') as $k) if (isset($s[$k])) $out[$k] = $s[$k];
    foreach (array('notifications', 'captcha', 'maps', 'preloader', 'security') as $k) {
      if (isset($s[$k]) && is_array($s[$k])) $out[$k] = array_merge($d[$k], $s[$k]);
    }
    $out['notifications']['adminAlerts'] = array_merge($d['notifications']['adminAlerts'], isset($s['notifications']['adminAlerts']) && is_array($s['notifications']['adminAlerts']) ? $s['notifications']['adminAlerts'] : array());
    if (!$out['cardHashKey']) {
      $out['cardHashKey'] = bin2hex(random_bytes(32));   // secret for card fingerprints
      $s['cardHashKey'] = $out['cardHashKey'];
      update_option('tj_settings', $out, false);
    }
    if (empty($out['notifications']['siteUrl'])) $out['notifications']['siteUrl'] = untrailingslashit(home_url());
    $out['feePercent'] = (float) $out['feePercent'];
    $out['feeFixed'] = (float) $out['feeFixed'];
    self::$settings_cache = $out;
    return $out;
  }

  public static function save_settings($s) {
    self::$settings_cache = null;
    update_option('tj_settings', $s, false);
    self::$settings_cache = null;
    return self::settings();
  }

  // ---------- helpers ----------
  public static function new_id($p) { return $p . '_' . bin2hex(random_bytes(8)); }
  public static function now_iso() {
    $t = microtime(true);
    return gmdate('Y-m-d\TH:i:s', (int) floor($t)) . '.' . sprintf('%03d', (int) floor(($t - floor($t)) * 1000)) . 'Z';
  }
  public static function iso_from_ts($ts) { return gmdate('Y-m-d\TH:i:s', (int) $ts) . '.000Z'; }
  /** Parses an ISO / date string like JS Date(); returns float ms or NAN. */
  public static function ts_ms($s) {
    if (is_int($s) || is_float($s)) return (float) $s;
    if (!is_string($s) || trim($s) === '') return NAN;
    try {
      $d = new DateTime(trim($s), wp_timezone());
      return (float) $d->format('U') * 1000 + (int) $d->format('v');
    } catch (Exception $e) { return NAN; }
  }
  private static function decode($json) {
    $d = json_decode((string) $json, true);
    return is_array($d) ? $d : null;
  }
  private static function encode($obj) { return wp_json_encode($obj); }

  // ---------- events ----------
  public static function events() {
    if (self::$events_cache !== null) return self::$events_cache;
    global $wpdb;
    $rows = $wpdb->get_col('SELECT data FROM ' . self::table('events') . ' ORDER BY created_at ASC, id ASC');
    $out = array();
    foreach ((array) $rows as $r) { $e = self::decode($r); if ($e) { $e['organizerId'] = (int) $e['organizerId']; $out[] = $e; } }
    self::$events_cache = $out;
    return $out;
  }
  public static function get_event($id) {
    foreach (self::events() as $e) if ($e['id'] === $id) return $e;
    return null;
  }
  public static function event_exists($id) {
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('events') . ' WHERE id = %s', $id));
  }
  public static function save_event($ev) {
    global $wpdb;
    $ev['organizerId'] = (int) $ev['organizerId'];
    $row = array(
      'organizer_id' => $ev['organizerId'], 'status' => (string) $ev['status'],
      'starts_at' => (string) $ev['startsAt'], 'ends_at' => (string) $ev['endsAt'],
      'created_at' => isset($ev['createdAt']) ? (string) $ev['createdAt'] : '', 'data' => self::encode($ev),
    );
    if (self::event_exists($ev['id'])) $wpdb->update(self::table('events'), $row, array('id' => $ev['id']));
    else { $row['id'] = $ev['id']; $wpdb->insert(self::table('events'), $row); }
    self::$events_cache = null;
    return $ev;
  }
  public static function delete_event($id) {
    global $wpdb;
    $wpdb->delete(self::table('events'), array('id' => $id));
    self::$events_cache = null;
  }

  // ---------- orders ----------
  public static function orders() {
    if (self::$orders_cache !== null) return self::$orders_cache;
    global $wpdb;
    $rows = $wpdb->get_col('SELECT data FROM ' . self::table('orders') . ' ORDER BY created_at ASC, id ASC');
    $out = array();
    foreach ((array) $rows as $r) {
      $o = self::decode($r);
      if (!$o) continue;
      $o['userId'] = isset($o['userId']) && $o['userId'] !== null && $o['userId'] !== '' ? (int) $o['userId'] : null;
      $out[] = $o;
    }
    self::$orders_cache = $out;
    return $out;
  }
  public static function get_order($id) {
    foreach (self::orders() as $o) if ($o['id'] === $id) return $o;
    return null;
  }
  public static function order_exists($id) {
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('orders') . ' WHERE id = %s', $id));
  }
  public static function save_order($o) {
    global $wpdb;
    $o['userId'] = isset($o['userId']) && $o['userId'] !== null && $o['userId'] !== '' ? (int) $o['userId'] : null;
    $row = array(
      'event_id' => (string) $o['eventId'], 'user_id' => $o['userId'], 'status' => (string) $o['status'],
      'test' => empty($o['test']) ? 0 : 1, 'buyer_email' => strtolower((string) (isset($o['buyerEmail']) ? $o['buyerEmail'] : '')),
      'created_at' => (string) $o['createdAt'], 'data' => self::encode($o),
    );
    if (self::order_exists($o['id'])) $wpdb->update(self::table('orders'), $row, array('id' => $o['id']));
    else { $row['id'] = $o['id']; $wpdb->insert(self::table('orders'), $row); }
    self::$orders_cache = null;
    return $o;
  }
  public static function delete_test_orders() {
    global $wpdb;
    $n = (int) $wpdb->query('DELETE FROM ' . self::table('orders') . ' WHERE test = 1');
    self::$orders_cache = null;
    return $n;
  }
  /** Comp tickets sent to an email before the person had an account now belong to that account. */
  public static function link_orders_by_email($email, $user_id) {
    $email = strtolower((string) $email);
    foreach (self::orders() as $o) {
      if (empty($o['userId']) && strtolower((string) (isset($o['buyerEmail']) ? $o['buyerEmail'] : '')) === $email) {
        $o['userId'] = (int) $user_id;
        self::save_order($o);
      }
    }
  }

  // ---------- message log ----------
  public static function add_message($entry) {
    global $wpdb;
    $t = self::table('messages');
    $wpdb->insert($t, array(
      'id' => $entry['id'], 'created_at' => $entry['createdAt'], 'channel' => (string) $entry['channel'], 'tag' => substr((string) $entry['tag'], 0, 60),
      'test' => empty($entry['test']) ? 0 : 1, 'ref' => (string) (isset($entry['ref']) ? $entry['ref'] : ''), 'data' => self::encode($entry),
    ));
    // keep the newest LOG_LIMIT messages (no LIMIT inside IN-subqueries: works on MySQL and SQLite)
    $old = $wpdb->get_col($wpdb->prepare("SELECT seq FROM $t ORDER BY created_at DESC, seq DESC LIMIT 100000 OFFSET %d", self::LOG_LIMIT));
    if ($old) {
      $old = array_map('intval', $old);
      $wpdb->query("DELETE FROM $t WHERE seq IN (" . implode(',', $old) . ')');
    }
    return $entry;
  }
  public static function update_message($entry) {
    global $wpdb;
    $wpdb->update(self::table('messages'), array('data' => self::encode($entry)), array('id' => $entry['id']));
    return $entry;
  }
  public static function message_exists($id) {
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('messages') . ' WHERE id = %s', $id));
  }
  /** Newest first, like db.notifications. */
  public static function messages($ref = null) {
    global $wpdb;
    $t = self::table('messages');
    $rows = $ref === null
      ? $wpdb->get_col("SELECT data FROM $t ORDER BY created_at DESC, seq DESC")
      : $wpdb->get_col($wpdb->prepare("SELECT data FROM $t WHERE ref = %s ORDER BY created_at DESC, seq DESC", $ref));
    $out = array();
    foreach ((array) $rows as $r) { $m = self::decode($r); if ($m) $out[] = $m; }
    return $out;
  }
  public static function get_message($id) {
    global $wpdb;
    $r = $wpdb->get_var($wpdb->prepare('SELECT data FROM ' . self::table('messages') . ' WHERE id = %s', $id));
    return $r ? self::decode($r) : null;
  }
  public static function delete_test_messages() {
    global $wpdb;
    return (int) $wpdb->query('DELETE FROM ' . self::table('messages') . ' WHERE test = 1');
  }

  // ---------- simple cross-request lock (prevents overselling between concurrent checkouts) ----------
  public static function lock($name, $wait_seconds = 10) {
    $key = 'tj_lock_' . md5($name);
    $deadline = microtime(true) + $wait_seconds;
    do {
      if (add_option($key, time() + 30, '', 'no')) return $key;
      wp_cache_delete($key, 'options');
      wp_cache_delete('notoptions', 'options');
      $exp = (int) get_option($key);
      if ($exp && $exp < time()) { delete_option($key); continue; }
      usleep(100000);
    } while (microtime(true) < $deadline);
    return false;
  }
  public static function unlock($key) {
    if ($key) delete_option($key);
  }
  public static function flush_cache() { self::$events_cache = null; self::$orders_cache = null; self::$settings_cache = null; }

  // ---------- users ----------
  public static function role_of($wp_user) {
    if (!$wp_user || empty($wp_user->ID)) return 'user';
    $roles = (array) $wp_user->roles;
    if (in_array('administrator', $roles, true)) return 'admin';
    if (in_array('tj_organizer', $roles, true)) return 'organizer';
    return 'user';
  }
  public static function wp_role_for($role) {
    if ($role === 'admin') return 'administrator';
    if ($role === 'organizer') return 'tj_organizer';
    return 'tj_attendee';
  }
  /** Node-shaped user array (publicUser + createdAt/lastLoginAt) for a WP_User. */
  public static function user($wp_user) {
    if (!$wp_user || empty($wp_user->ID)) return null;
    $id = (int) $wp_user->ID;
    $reg = $wp_user->user_registered ? strtotime($wp_user->user_registered . ' UTC') : time();
    $ll = get_user_meta($id, 'tj_last_login', true);
    $created = get_user_meta($id, 'tj_created_at', true);
    return array(
      'id' => $id,
      'name' => (string) ($wp_user->display_name !== '' ? $wp_user->display_name : $wp_user->user_login),
      'email' => strtolower((string) $wp_user->user_email),
      'phone' => (string) get_user_meta($id, 'tj_phone', true),
      'role' => self::role_of($wp_user),
      'organization' => (string) get_user_meta($id, 'tj_organization', true),
      'suspended' => (bool) get_user_meta($id, 'tj_suspended', true),
      'createdAt' => $created ? (string) $created : self::iso_from_ts($reg),
      'lastLoginAt' => $ll ? (string) $ll : null,
    );
  }
  public static function user_by_id($id) {
    if (!$id) return null;
    $u = get_userdata((int) $id);
    return $u ? self::user($u) : null;
  }
  public static function user_by_email($email) {
    $email = strtolower(trim((string) $email));
    if ($email === '') return null;
    $u = get_user_by('email', $email);
    if (!$u) {
      global $wpdb;  // case-insensitive fallback (SQLite compares case-sensitively)
      $id = $wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->users WHERE LOWER(user_email) = %s", $email));
      if ($id) $u = get_userdata((int) $id);
    }
    return $u ? self::user($u) : null;
  }
  private static $users_cache = null;
  public static function users() {
    if (self::$users_cache !== null) return self::$users_cache;
    $out = array();
    foreach (get_users(array('blog_id' => get_current_blog_id(), 'orderby' => 'ID')) as $u) $out[] = self::user($u);
    self::$users_cache = $out;
    return $out;
  }
  public static function flush_users() { self::$users_cache = null; }
  public static function first_admin_id() {
    $ids = get_users(array('role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1, 'fields' => 'ID'));
    return $ids ? (int) $ids[0] : 0;
  }

  // ---------- owner's events shipped with the plugin (assets/seed/events.json) ----------
  // Each bundled event is added once if it is missing. If you delete it later it stays deleted.
  /** Ready-made flyers for the demo events (assets/seed/flyers). Added once per event, only when it has no picture yet. */
  public static $BUNDLED_FLYERS = array(
    'Friday Night Afrobeats Takeover' => 'friday-night-afrobeats-takeover',
    'Island Vibes Summer Fest' => 'island-vibes-summer-fest',
    'Startup Pitch Night' => 'startup-pitch-night',
    'Laugh Out Loud Comedy Night' => 'laugh-out-loud-comedy-night',
    'Taste of the Islands Food Festival' => 'taste-of-the-islands-food-festival',
    'Junkanoo Rush Experience' => 'junkanoo-rush-experience',
  );
  public static function apply_bundled_flyers() {
    if (!class_exists('TJ_Rest')) return;
    $done = get_option('tj_bundled_flyers');
    if (!is_array($done)) $done = array();
    $todo = array();
    foreach (self::events() as $e) {
      $t = trim((string) $e['title']);
      if (!isset(self::$BUNDLED_FLYERS[$t]) || !empty($e['imageUrl']) || !empty($done[$e['id']])) continue;
      $todo[] = $e;
    }
    if (!$todo || get_transient('tj_applying_flyers')) return;
    set_transient('tj_applying_flyers', 1, 120);
    foreach ($todo as $e) {
      $slug = self::$BUNDLED_FLYERS[trim((string) $e['title'])];
      $src = TJ_DIR . 'assets/seed/flyers/' . $slug . '.jpg';
      $share = TJ_DIR . 'assets/seed/flyers/' . $slug . '-share.jpg';
      $done[$e['id']] = $slug;
      if (!file_exists($src)) continue;
      $url = TJ_Rest::save_upload($slug . '.jpg', file_get_contents($src), $e['title']);
      if (!$url) continue;
      $e['imageUrl'] = $url;
      $e['imageShareUrl'] = file_exists($share) ? TJ_Rest::save_upload($slug . '-share.jpg', file_get_contents($share), $e['title'] . ' (share)') : '';
      self::save_event($e);
    }
    update_option('tj_bundled_flyers', $done, false);
    self::flush_cache();
    delete_transient('tj_applying_flyers');
  }

  public static function restore_bundled_events() {
    $file = TJ_DIR . 'assets/seed/events.json';
    if (!file_exists($file)) return;
    $done = get_option('tj_bundled_events');
    if (!is_array($done)) $done = array();
    $list = json_decode((string) file_get_contents($file), true);
    if (!is_array($list)) return;
    $todo = array();
    foreach ($list as $e) if (!empty($e['id']) && empty($done[$e['id']])) $todo[] = $e;
    if (!$todo) return;
    if (get_transient('tj_restoring_events')) return;
    set_transient('tj_restoring_events', 1, 120);
    $owner = 0;
    foreach (array('djpayne242@gmail.com') as $em) { $u = get_user_by('email', $em); if ($u) { $owner = (int) $u->ID; break; } }
    if (!$owner) $owner = (int) self::first_admin_id();
    if (!$owner) { delete_transient('tj_restoring_events'); return; }
    foreach ($todo as $e) {
      $id = $e['id'];
      $same_title = false;
      foreach (self::events() as $x) if (strcasecmp(trim($x['title']), trim($e['title'])) === 0) $same_title = true;
      if (!self::event_exists($id) && !$same_title) {
        $e['organizerId'] = $owner;
        foreach (array('imageUrl', 'imageShareUrl') as $k) {
          if (empty($e[$k])) continue;
          $src = TJ_DIR . 'assets/seed/' . basename($e[$k]);
          $e[$k] = file_exists($src) && class_exists('TJ_Rest') ? TJ_Rest::save_upload(basename($e[$k]), file_get_contents($src), $e['title']) : '';
        }
        if (empty($e['imageUrl'])) $e['imageShareUrl'] = '';
        foreach ($e['tiers'] as &$t) $t['sold'] = 0;
        unset($t);
        $e['updatedAt'] = gmdate('Y-m-d\TH:i:s') . '.000Z';
        self::save_event($e);
        if (class_exists('TJ_Directory')) TJ_Directory::sync_event($e, self::user_by_id($owner));
      }
      $done[$id] = gmdate('c');
    }
    update_option('tj_bundled_events', $done, false);
    delete_transient('tj_restoring_events');
  }

  // ---------- sample events (only for an empty site) ----------
  public static function seed() {
    global $wpdb;
    $count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::table('events'));
    if ($count > 0 || get_option('tj_seeded') !== false) return;
    $owner = self::first_admin_id();
    if (!$owner) return;
    $tz = wp_timezone();
    $at = function ($d, $h) use ($tz) {
      $x = new DateTime('now', $tz);
      $x->modify(($d >= 0 ? '+' : '') . $d . ' days');
      $x->setTime($h, 0, 0);
      return gmdate('Y-m-d\TH:i:s', $x->getTimestamp()) . '.000Z';
    };
    $tier = function ($name, $price, $quantity) { return array('id' => TJ_Store::new_id('tier'), 'name' => $name, 'price' => $price, 'quantity' => $quantity, 'sold' => 0); };
    $samples = array(
      array('Island Vibes Summer Fest', 'Music', 'An all-day open-air festival with live bands, DJs, food vendors and island vibes until sunset. Bring your crew!', 9, 14, 23, 'Paradise Beach Grounds', '1 Ocean Drive', 'Nassau', array($tier('General Admission', 45, 800), $tier('VIP Lounge', 120, 120))),
      array('Friday Night Afrobeats Takeover', 'Nightlife', 'The hottest Afrobeats, Amapiano and Dancehall all night long with special guest DJs.', 4, 22, 4, 'Club Velocity', '88 Bay Street', 'Nassau', array($tier('Early Bird', 20, 150), $tier('Regular', 30, 300), $tier('VIP Table (6 ppl)', 400, 10))),
      array('Laugh Out Loud Comedy Night', 'Comedy', 'Five of the funniest comedians in the Caribbean on one stage. 18+ show.', 15, 20, 23, 'The Grand Theatre', '12 Shirley Street', 'Nassau', array($tier('Standard Seat', 35, 250), $tier('Front Row', 65, 30))),
      array('Taste of the Islands Food Festival', 'Food & Drink', 'Sample dishes from 40+ local chefs, rum tastings, cooking demos and live music.', 21, 12, 20, 'Arawak Cay', 'West Bay Street', 'Nassau', array($tier('Day Pass', 25, 1000), $tier('Tasting Pass + Drinks', 60, 200))),
      array('Startup Pitch Night', 'Business', 'Local founders pitch to investors. Networking, drinks and inspiration.', 12, 18, 21, 'Innovation Hub', '45 East Street', 'Nassau', array($tier('Free RSVP', 0, 120))),
      array('Junkanoo Rush Experience', 'Community', 'Feel the rhythm of cowbells and goatskin drums. A family-friendly celebration of culture.', 35, 16, 22, 'Rawson Square', 'Bay Street', 'Nassau', array($tier('Bleacher Seat', 15, 2000), $tier('Premium Stand', 50, 300))),
    );
    $ids = array();
    foreach ($samples as $s) {
      list($title, $category, $description, $d, $h1, $h2, $venue, $address, $city, $tiers) = $s;
      $ev = array(
        'id' => self::new_id('evt'), 'organizerId' => $owner, 'title' => $title, 'category' => $category, 'description' => $description,
        'startsAt' => $at($d, $h1), 'endsAt' => $at($h2 < $h1 ? $d + 1 : $d, $h2),
        'venue' => $venue, 'address' => $address, 'city' => $city, 'imageUrl' => '', 'status' => 'published', 'tiers' => $tiers,
        'createdAt' => self::now_iso(),
      );
      self::save_event($ev);
      $ids[] = $ev['id'];
    }
    update_option('tj_seeded', $ids, false);
  }
}

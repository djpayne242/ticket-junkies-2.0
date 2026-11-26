<?php if (!defined('ABSPATH')) exit;
/**
 * Imports data from the old Node.js TicketJunkies site.
 * Bundle: { "version": 1, "db": <data/db.json>, "images": { "/uploads/<file>": "data:image/...;base64,..." } }
 * Idempotent: users are matched by email, events/orders/messages that already exist (same id) are skipped.
 *
 * WP-CLI:  wp tj import <bundle.json | db.json | node-app-folder> [--skip-settings] [--keep-samples]
 */
class TJ_Import {
  public static function init() {
    if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
      WP_CLI::add_command('tj import', array(__CLASS__, 'cli'), array(
        'shortdesc' => 'Import users, events, orders, settings and message log from the old TicketJunkies (Node) site.',
        'synopsis' => array(
          array('type' => 'positional', 'name' => 'source', 'description' => 'Bundle JSON, a raw data/db.json, or the Node app folder.'),
          array('type' => 'flag', 'name' => 'skip-settings', 'optional' => true, 'description' => 'Do not import fees / notification / captcha / maps settings.'),
          array('type' => 'flag', 'name' => 'keep-samples', 'optional' => true, 'description' => 'Keep the sample events created on activation.'),
        ),
      ));
    }
  }

  public static function cli($args, $assoc) {
    $src = $args[0];
    try { $bundle = self::load_source($src); }
    catch (Exception $e) { WP_CLI::error($e->getMessage()); return; }
    $summary = self::run($bundle, array('settings' => empty($assoc['skip-settings']), 'remove_samples' => empty($assoc['keep-samples'])));
    foreach ($summary['warnings'] as $w) WP_CLI::warning($w);
    unset($summary['warnings']);
    foreach ($summary as $k => $v) WP_CLI::log(str_pad($k, 18) . (is_array($v) ? wp_json_encode($v) : $v));
    WP_CLI::success('Import finished.');
  }

  /** Builds a bundle from a bundle file, a raw db.json (images read from ../public/uploads) or the Node app folder. */
  public static function load_source($src) {
    $dir = null;
    if (is_dir($src)) {
      $dir = rtrim($src, '/');
      $file = $dir . '/data/db.json';
    } else $file = $src;
    if (!is_readable($file)) throw new Exception('Cannot read ' . $file);
    $json = json_decode(file_get_contents($file), true);
    if (!is_array($json)) throw new Exception('Not a valid JSON file: ' . $file);
    if (isset($json['db']) && is_array($json['db'])) return $json;
    if (!isset($json['users']) || !isset($json['events'])) throw new Exception('Unrecognized file: expected a TicketJunkies bundle or db.json');
    $uploads = $dir ? $dir . '/public/uploads' : dirname(dirname(realpath($file))) . '/public/uploads';
    $images = array();
    foreach ($json['events'] as $e) {
      foreach (array('imageUrl', 'imageShareUrl') as $k) {
        $u = isset($e[$k]) ? (string) $e[$k] : '';
        if (strpos($u, '/uploads/') !== 0 || isset($images[$u])) continue;
        $p = $uploads . '/' . basename($u);
        if (!is_readable($p)) continue;
        $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
        $mime = $ext === 'jpg' || $ext === 'jpeg' ? 'jpeg' : $ext;
        $images[$u] = 'data:image/' . $mime . ';base64,' . base64_encode(file_get_contents($p));
      }
    }
    return array('version' => 1, 'db' => $json, 'images' => $images);
  }

  /**
   * @param array $bundle see class doc
   * @param array $opts   settings (bool, default true), remove_samples (bool, default true)
   * @return array summary counts + warnings
   */
  public static function run(array $bundle, array $opts = array()) {
    $opts = array_merge(array('settings' => true, 'remove_samples' => true), $opts);
    $db = isset($bundle['db']) && is_array($bundle['db']) ? $bundle['db'] : $bundle;
    $images = isset($bundle['images']) && is_array($bundle['images']) ? $bundle['images'] : array();
    $sum = array('usersCreated' => 0, 'usersMatched' => 0, 'eventsImported' => 0, 'eventsSkipped' => 0, 'ordersImported' => 0, 'ordersSkipped' => 0,
      'messagesImported' => 0, 'messagesSkipped' => 0, 'imagesUploaded' => 0, 'samplesRemoved' => 0, 'settings' => false, 'warnings' => array());
    if (function_exists('set_time_limit')) @set_time_limit(0);
    TJ_Store::flush_cache();

    // ---------- users ----------
    $map = array();          // old id -> WP user id
    foreach ((array) (isset($db['users']) ? $db['users'] : array()) as $u) {
      if (empty($u['email']) || empty($u['id'])) continue;
      $email = strtolower(trim($u['email']));
      $existing = TJ_Store::user_by_email($email);
      if (!$existing) {
        $wp = get_users(array('meta_key' => 'tj_legacy_id', 'meta_value' => $u['id'], 'number' => 1));
        if ($wp) $existing = TJ_Store::user($wp[0]);
      }
      if ($existing) {
        $map[$u['id']] = $existing['id'];
        $sum['usersMatched']++;
        // fill in profile details that WordPress doesn't have yet; never change an existing user's role
        if (!get_user_meta($existing['id'], 'tj_phone', true) && !empty($u['phone'])) update_user_meta($existing['id'], 'tj_phone', $u['phone']);
        if (!get_user_meta($existing['id'], 'tj_organization', true) && !empty($u['organization'])) update_user_meta($existing['id'], 'tj_organization', $u['organization']);
        if (!get_user_meta($existing['id'], 'tj_legacy_id', true)) update_user_meta($existing['id'], 'tj_legacy_id', $u['id']);
        continue;
      }
      if (!is_email($email)) { $sum['warnings'][] = 'Skipped user with invalid email: ' . $email; continue; }
      $role = isset($u['role']) ? $u['role'] : 'user';
      if ($role === 'promoter') $role = 'organizer';
      // Never create administrators from a bundle: old admins become organizers.
      $wp_role = ($role === 'admin' || $role === 'organizer') ? 'tj_organizer' : 'tj_attendee';
      $login = sanitize_user($email, true);
      if ($login === '' || strlen($login) > 60 || username_exists($login)) {
        $base = substr(sanitize_user(strstr($email, '@', true), true), 0, 40);
        if ($base === '') $base = 'user';
        $login = $base; $i = 1;
        while (username_exists($login)) { $i++; $login = $base . $i; }
      }
      $name = isset($u['name']) && $u['name'] !== '' ? $u['name'] : $login;
      if ($name === 'Demo Promoter') $name = 'Demo Organizer';
      $parts = preg_split('/\s+/', trim($name));
      $last = count($parts) > 1 ? array_pop($parts) : '';
      $reg = !empty($u['createdAt']) ? strtotime($u['createdAt']) : time();
      $id = wp_insert_user(array(
        'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password(24, true, true),
        'display_name' => $name, 'nickname' => $name, 'first_name' => implode(' ', $parts), 'last_name' => $last,
        'role' => $wp_role, 'user_registered' => gmdate('Y-m-d H:i:s', $reg ? $reg : time()),
      ));
      if (is_wp_error($id)) { $sum['warnings'][] = 'Could not create user ' . $email . ': ' . $id->get_error_message(); continue; }
      update_user_meta($id, 'tj_phone', isset($u['phone']) ? (string) $u['phone'] : '');
      update_user_meta($id, 'tj_organization', isset($u['organization']) ? (string) $u['organization'] : '');
      update_user_meta($id, 'tj_suspended', !empty($u['suspended']) ? 1 : 0);
      update_user_meta($id, 'tj_legacy_id', $u['id']);
      if (!empty($u['createdAt'])) update_user_meta($id, 'tj_created_at', $u['createdAt']);
      if (!empty($u['lastLoginAt'])) update_user_meta($id, 'tj_last_login', $u['lastLoginAt']);
      if ($role === 'admin') $sum['warnings'][] = 'Old administrator ' . $email . ' was imported as an organizer (promote it in Users if needed).';
      $map[$u['id']] = (int) $id;
      $sum['usersCreated']++;
    }
    TJ_Store::flush_users();
    $fallback_owner = TJ_Store::first_admin_id();

    // ---------- images ----------
    $done = get_option('tj_import_images');
    if (!is_array($done)) $done = array();
    $image = function ($u) use (&$done, $images, &$sum) {
      $u = (string) $u;
      if (strpos($u, '/uploads/') !== 0) return $u;
      if (isset($done[$u])) return $done[$u];
      if (empty($images[$u]) || !preg_match('~^data:image/(png|jpe?g|webp|gif);base64,(.+)$~s', $images[$u], $m)) {
        $sum['warnings'][] = 'Image ' . $u . ' is not in the bundle; the event will have no image.';
        return '';
      }
      $url = TJ_Rest::save_upload(basename($u), base64_decode($m[2]));
      if (!$url) { $sum['warnings'][] = 'Could not save image ' . $u; return ''; }
      $done[$u] = $url;
      update_option('tj_import_images', $done, false);
      $sum['imagesUploaded']++;
      return $url;
    };

    // ---------- events ----------
    foreach ((array) (isset($db['events']) ? $db['events'] : array()) as $e) {
      if (empty($e['id'])) continue;
      if (TJ_Store::event_exists($e['id'])) { $sum['eventsSkipped']++; continue; }
      $old_org = isset($e['organizerId']) ? $e['organizerId'] : (isset($e['promoterId']) ? $e['promoterId'] : null);
      unset($e['promoterId']);
      $e['organizerId'] = isset($map[$old_org]) ? $map[$old_org] : $fallback_owner;
      if (!isset($map[$old_org])) $sum['warnings'][] = 'Event ' . $e['id'] . ': organizer not found, assigned to the first administrator.';
      if (!empty($e['imageUrl'])) $e['imageUrl'] = $image($e['imageUrl']);
      if (!empty($e['imageShareUrl'])) $e['imageShareUrl'] = $e['imageUrl'] ? $image($e['imageShareUrl']) : '';
      if (!isset($e['tiers']) || !is_array($e['tiers'])) $e['tiers'] = array();
      TJ_Store::save_event($e);
      $sum['eventsImported']++;
    }

    // ---------- orders ----------
    foreach ((array) (isset($db['orders']) ? $db['orders'] : array()) as $o) {
      if (empty($o['id'])) continue;
      if (TJ_Store::order_exists($o['id'])) { $sum['ordersSkipped']++; continue; }
      $o['userId'] = !empty($o['userId']) && isset($map[$o['userId']]) ? $map[$o['userId']] : null;
      if (isset($o['issuedById'])) $o['issuedById'] = isset($map[$o['issuedById']]) ? $map[$o['issuedById']] : null;
      // same upgrades as migrate() in server.mjs
      if (empty($o['key'])) $o['key'] = bin2hex(random_bytes(12));
      if (!isset($o['buyerPhone'])) $o['buyerPhone'] = '';
      $last4 = isset($o['cardLast4']) ? $o['cardLast4'] : '';
      if (!isset($o['cardBrand'])) $o['cardBrand'] = $last4 ? ($last4 === '4242' ? 'Visa' : 'Card') : '';
      if (!isset($o['cardHolder'])) $o['cardHolder'] = $last4 ? $o['buyerName'] : '';
      if (!isset($o['cardFingerprint'])) $o['cardFingerprint'] = $last4 ? 'legacy_' . $last4 : '';
      if (empty($o['status'])) $o['status'] = 'paid';
      TJ_Store::save_order($o);
      $sum['ordersImported']++;
    }

    // ---------- settings ----------
    if ($opts['settings'] && !empty($db['settings']) && is_array($db['settings'])) {
      $old = $db['settings'];
      $s = TJ_Store::settings();
      if (isset($old['feePercent'])) $s['feePercent'] = (float) $old['feePercent'];
      if (isset($old['feeFixed'])) $s['feeFixed'] = (float) $old['feeFixed'];
      if (!empty($old['cardHashKey'])) $s['cardHashKey'] = $old['cardHashKey'];   // card fingerprints keep matching
      if (!empty($old['notifications']) && is_array($old['notifications'])) {
        $n = $old['notifications'];
        $aa = isset($n['adminAlerts']) && is_array($n['adminAlerts']) ? $n['adminAlerts'] : array();
        if (isset($aa['promoterSignup']) && !isset($aa['organizerSignup'])) $aa['organizerSignup'] = $aa['promoterSignup'];
        unset($aa['promoterSignup']);
        // The old site only really sent email with a Resend key; keep its effective on/off state (wp_mail needs no key).
        $n['emailEnabled'] = !empty($n['emailEnabled']) && !empty($n['resendApiKey']);
        if (!empty($old['notifications']['emailEnabled']) && !$n['emailEnabled']) $sum['warnings'][] = 'Email was in preview mode on the old site (no Resend key), so it stays off. Turn it on in Admin → Settings once WordPress mail (SMTP) works.';
        unset($n['siteUrl'], $n['adminAlerts']);
        $s['notifications'] = array_merge($s['notifications'], $n);
        $s['notifications']['adminAlerts'] = array_merge($s['notifications']['adminAlerts'], array_map('boolval', $aa));
      }
      if (!empty($old['captcha']) && is_array($old['captcha'])) $s['captcha'] = array_merge($s['captcha'], $old['captcha']);
      if (!empty($old['maps']) && is_array($old['maps'])) $s['maps'] = array_merge($s['maps'], $old['maps']);
      TJ_Store::save_settings($s);
      $sum['settings'] = true;
    }

    // ---------- message log (oldest first so ordering is preserved) ----------
    $msgs = isset($db['notifications']) && is_array($db['notifications']) ? array_reverse($db['notifications']) : array();
    foreach ($msgs as $m) {
      if (empty($m['id'])) continue;
      if (TJ_Store::message_exists($m['id'])) { $sum['messagesSkipped']++; continue; }
      if (empty($m['createdAt'])) $m['createdAt'] = TJ_Store::now_iso();
      if (!isset($m['tag'])) $m['tag'] = '';
      if (!isset($m['channel'])) $m['channel'] = 'email';
      TJ_Store::add_message($m);
      $sum['messagesImported']++;
    }

    // ---------- remove untouched sample events from activation ----------
    if ($opts['remove_samples']) {
      $seeded = get_option('tj_seeded');
      if (is_array($seeded) && $seeded) {
        $with_orders = array();
        foreach (TJ_Store::orders() as $o) $with_orders[$o['eventId']] = true;
        foreach ($seeded as $id) {
          if (!isset($with_orders[$id]) && TJ_Store::event_exists($id)) { TJ_Store::delete_event($id); $sum['samplesRemoved']++; }
        }
        update_option('tj_seeded', array(), false);
      }
    }

    // locate events without map coordinates in the background
    if (!wp_next_scheduled('tj_locate_missing')) wp_schedule_single_event(time() + 30, 'tj_locate_missing');
    TJ_Store::flush_cache();
    $sum['totals'] = array('events' => count(TJ_Store::events()), 'orders' => count(TJ_Store::orders()), 'messages' => count(TJ_Store::messages()));
    if (class_exists('TJ_Directory')) { TJ_Directory::seed(); foreach (TJ_Store::events() as $e) TJ_Directory::sync_event($e, TJ_Store::user_by_id($e['organizerId'])); }
    return $sum;
  }
}

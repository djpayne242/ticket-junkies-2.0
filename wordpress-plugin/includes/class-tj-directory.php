<?php
if (!defined('ABSPATH')) exit;

/**
 * Saved organizers & venues: the lists organizers pick from on the event form,
 * managed in Admin Panel → Organizers / Venues. Stored as two WordPress options.
 */
final class TJ_Directory {
  public static function norm($x) { return preg_replace('/\s+/', ' ', strtolower(trim((string) $x))); }

  public static function join_names($list) {
    $list = array_values($list);
    $n = count($list);
    if ($n <= 2) return implode(' & ', $list);
    return implode(', ', array_slice($list, 0, -1)) . ' & ' . $list[$n - 1];
  }

  public static function event_organizers($e) {
    if (!empty($e['organizers']) && is_array($e['organizers'])) return array_values($e['organizers']);
    if (!empty($e['organizerName'])) return array($e['organizerName']);
    $u = !empty($e['organizerId']) ? TJ_Store::user_by_id($e['organizerId']) : null;
    return $u ? array($u['organization'] ? $u['organization'] : $u['name']) : array();
  }

  public static function event_venue_names($e) {
    $out = array();
    if (!empty($e['venue'])) $out[] = $e['venue'];
    if (!empty($e['extraVenues']) && is_array($e['extraVenues'])) foreach ($e['extraVenues'] as $v) if (!empty($v['name'])) $out[] = $v['name'];
    return $out;
  }

  // ---------- storage ----------
  public static function organizers() { self::seed(); $v = get_option('tj_organizers', array()); return is_array($v) ? $v : array(); }
  public static function venues() { self::seed(); $v = get_option('tj_venues', array()); return is_array($v) ? $v : array(); }
  public static function save_organizers($list) { update_option('tj_organizers', array_values($list), false); }
  public static function save_venues($list) { update_option('tj_venues', array_values($list), false); }

  private static $seeding = false;
  /** First use: build the lists from organizer accounts and existing events. Also links organizers to matching accounts. */
  public static function seed() {
    if (self::$seeding) return;
    $have = get_option('tj_organizers', null) !== null && get_option('tj_venues', null) !== null;
    if ($have && get_option('tj_directory_linked') === '1') return;
    self::$seeding = true;
    if (get_option('tj_organizers', null) === null) add_option('tj_organizers', array(), '', 'no');
    if (get_option('tj_venues', null) === null) add_option('tj_venues', array(), '', 'no');
    if (!$have) {
      $orgs = get_option('tj_organizers', array());
      foreach (TJ_Store::users() as $u) {
        if ($u['role'] !== 'organizer') continue;
        $name = $u['organization'] ? $u['organization'] : $u['name'];
        if (!$name || self::find_org($orgs, $name)) continue;
        $orgs[] = array('id' => TJ_Store::new_id('org'), 'name' => $name, 'email' => $u['email'], 'phone' => $u['phone'] ? $u['phone'] : '', 'website' => '', 'notes' => '', 'userId' => $u['id'], 'createdById' => null, 'createdAt' => TJ_Store::now_iso());
      }
      self::save_organizers($orgs);
      foreach (TJ_Store::events() as $e) self::sync_event($e, TJ_Store::user_by_id($e['organizerId']));
    }
    // link organizers to accounts (organizer or admin) with the same organizer name
    $orgs = get_option('tj_organizers', array());
    foreach ($orgs as &$o) {
      if (!empty($o['userId'])) continue;
      foreach (TJ_Store::users() as $u) {
        if (($u['role'] === 'organizer' || $u['role'] === 'admin') && $u['organization'] && self::norm($u['organization']) === self::norm($o['name'])) {
          $o['userId'] = $u['id']; if (empty($o['email'])) $o['email'] = $u['email']; if (empty($o['phone'])) $o['phone'] = $u['phone'] ? $u['phone'] : '';
          break;
        }
      }
    }
    unset($o);
    self::save_organizers($orgs);
    update_option('tj_directory_linked', '1', false);
    self::$seeding = false;
  }

  public static function find_org($list, $name) { foreach ($list as $o) if (self::norm($o['name']) === self::norm($name)) return $o; return null; }

  /** Adds organizers / venues used on an event to the saved lists. */
  public static function sync_event($ev, $user) {
    $orgs = get_option('tj_organizers', array()); if (!is_array($orgs)) $orgs = array();
    $vens = get_option('tj_venues', array()); if (!is_array($vens)) $vens = array();
    $now = TJ_Store::now_iso();
    foreach (self::event_organizers($ev) as $n) {
      if (self::find_org($orgs, $n)) continue;
      $link = $user && ($user['role'] === 'organizer' || $user['role'] === 'admin') && self::norm($user['organization'] ? $user['organization'] : $user['name']) === self::norm($n);
      $orgs[] = array('id' => TJ_Store::new_id('org'), 'name' => $n, 'email' => '', 'phone' => '', 'website' => '', 'notes' => '', 'userId' => $link ? $user['id'] : null, 'createdById' => $user ? $user['id'] : null, 'createdAt' => $now);
    }
    $all = array(array('name' => isset($ev['venue']) ? $ev['venue'] : '', 'address' => isset($ev['address']) ? $ev['address'] : '', 'city' => isset($ev['city']) ? $ev['city'] : '', 'lat' => isset($ev['lat']) ? $ev['lat'] : null, 'lng' => isset($ev['lng']) ? $ev['lng'] : null, 'geoPrecision' => isset($ev['geoPrecision']) ? $ev['geoPrecision'] : null));
    if (!empty($ev['extraVenues']) && is_array($ev['extraVenues'])) foreach ($ev['extraVenues'] as $x) $all[] = $x;
    foreach ($all as $v) {
      if (empty($v['name'])) continue;
      $city = isset($v['city']) ? $v['city'] : '';
      $ok = TJ_Geo::valid_lat_lng(isset($v['lat']) ? $v['lat'] : null, isset($v['lng']) ? $v['lng'] : null);
      $hit = -1;
      foreach ($vens as $i => $x) if (self::norm($x['name']) === self::norm($v['name']) && ($city === '' || $x['city'] === '' || self::norm($x['city']) === self::norm($city))) { $hit = $i; break; }
      if ($hit < 0) {
        $vens[] = array('id' => TJ_Store::new_id('ven'), 'name' => $v['name'], 'address' => isset($v['address']) ? (string) $v['address'] : '', 'city' => (string) $city,
          'lat' => $ok ? $v['lat'] : null, 'lng' => $ok ? $v['lng'] : null, 'geoPrecision' => $ok ? (!empty($v['geoPrecision']) ? $v['geoPrecision'] : 'pin') : null,
          'phone' => '', 'capacity' => null, 'notes' => '', 'createdById' => $user ? $user['id'] : null, 'createdAt' => $now);
      } elseif ($ok && !TJ_Geo::valid_lat_lng($vens[$hit]['lat'], $vens[$hit]['lng'])) {
        $vens[$hit]['lat'] = $v['lat']; $vens[$hit]['lng'] = $v['lng']; $vens[$hit]['geoPrecision'] = !empty($v['geoPrecision']) ? $v['geoPrecision'] : 'pin';
      }
    }
    update_option('tj_organizers', array_values($orgs), false);
    update_option('tj_venues', array_values($vens), false);
  }

  /** Renaming in the lists also renames it on every event that uses it. Returns how many events changed. */
  public static function rename_on_events($kind, $old, $next) {
    $n = 0;
    foreach (TJ_Store::events() as $e) {
      $hit = false;
      if ($kind === 'organizer') {
        $list = self::event_organizers($e);
        $found = false;
        foreach ($list as $x) if (self::norm($x) === self::norm($old)) $found = true;
        if (!$found) continue;
        $new = array();
        foreach ($list as $x) { $y = self::norm($x) === self::norm($old) ? $next['name'] : $x; $dup = false; foreach ($new as $z) if (self::norm($z) === self::norm($y)) $dup = true; if (!$dup) $new[] = $y; }
        $e['organizers'] = $new; $e['organizerName'] = self::join_names($new); $hit = true;
      } else {
        if (self::norm($e['venue']) === self::norm($old)) {
          $e['venue'] = $next['name']; $e['address'] = $next['address']; $e['city'] = $next['city'];
          if (TJ_Geo::valid_lat_lng($next['lat'], $next['lng'])) { $e['lat'] = $next['lat']; $e['lng'] = $next['lng']; $e['geoPrecision'] = $next['geoPrecision'] ? $next['geoPrecision'] : 'pin'; }
          $hit = true;
        }
        if (!empty($e['extraVenues']) && is_array($e['extraVenues'])) {
          foreach ($e['extraVenues'] as &$x) {
            if (self::norm($x['name']) !== self::norm($old)) continue;
            $x = array('name' => $next['name'], 'address' => $next['address'], 'city' => $next['city'], 'lat' => $next['lat'], 'lng' => $next['lng']);
            $hit = true;
          }
          unset($x);
        }
      }
      if ($hit) { TJ_Store::save_event($e); $n++; }
    }
    return $n;
  }
}

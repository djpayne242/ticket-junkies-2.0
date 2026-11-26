<?php if (!defined('ABSPATH')) exit;
/**
 * Address lookup (OpenStreetMap Nominatim) and weather forecasts (Open-Meteo). Both free, no API key.
 * Results are cached in transients to stay fast and polite (Nominatim allows ~1 request/second).
 */
class TJ_Geo {
  const UA = 'TicketJunkies.com/1.0 (event ticketing; admin contact via site)';
  const WX_TTL = 1800; // 30 minutes

  private static function user_agent() {
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    return self::UA . ($host ? ' ' . $host : '');
  }

  /** @return array|null {lat,lng,label}; throws Exception on service errors. */
  private static function nominatim($q, $timeout = 8) {
    $key = 'tj_geo_' . md5(strtolower(trim($q)));
    $cached = get_transient($key);
    if ($cached !== false) return $cached === 'none' ? null : $cached;
    // throttle: at most one request per ~1.1s across requests
    $last = (float) get_transient('tj_geo_last');
    $wait = $last + 1.1 - microtime(true);
    if ($wait > 0 && $wait < 2) usleep((int) ($wait * 1000000));
    set_transient('tj_geo_last', (string) microtime(true), 10);
    $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&addressdetails=0&q=' . rawurlencode($q);
    $res = wp_remote_get($url, array('timeout' => $timeout, 'headers' => array('User-Agent' => self::user_agent(), 'Accept-Language' => 'en')));
    if (is_wp_error($res)) throw new Exception($res->get_error_message());
    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code < 200 || $code >= 300) throw new Exception('Geocoder error ' . $code);
    $list = json_decode(wp_remote_retrieve_body($res), true);
    $hit = is_array($list) && !empty($list[0]) ? array('lat' => (float) $list[0]['lat'], 'lng' => (float) $list[0]['lon'], 'label' => (string) $list[0]['display_name']) : null;
    set_transient($key, $hit ? $hit : 'none', $hit ? 30 * DAY_IN_SECONDS : DAY_IN_SECONDS);
    return $hit;
  }

  /** Tries the most precise query first, then falls back to broader ones. */
  /** $deadline: optional unix time after which no further lookups are attempted. */
  public static function geocode_place($p, $deadline = null) {
    $g = function ($k) use ($p) { return isset($p[$k]) ? trim((string) $p[$k]) : ''; };
    $parts = function () { return implode(', ', array_values(array_filter(array_map(function ($x) { return trim((string) $x); }, func_get_args()), 'strlen'))); };
    $venue = $g('venue'); $address = $g('address'); $city = $g('city'); $country = $g('country');
    $cityq = $parts($city, $country);
    $tries = array(
      array('venue', $parts($venue, $address, $city, $country)),
      array('address', $parts($address, $city, $country)),
      array('venue', $parts($venue, $city, $country)),
      array('city', $cityq),
    );
    $seen = array();
    foreach ($tries as $t) {
      list($precision, $q) = $t;
      if (!$q || isset($seen[$q]) || ($precision !== 'city' && $q === $cityq)) continue;
      $seen[$q] = true;
      $left = $deadline ? $deadline - microtime(true) : 8;
      if ($left < 1) return null;
      try {
        $hit = self::nominatim($q, (int) max(1, min(8, floor($left))));
        if ($hit) return array_merge($hit, array('precision' => $precision, 'query' => $q));
      } catch (Exception $e) {
        error_log('[tj geo] ' . $e->getMessage());
        return null;
      }
    }
    return null;
  }

  public static function valid_lat_lng($lat, $lng) {
    if (!is_int($lat) && !is_float($lat)) return false;
    if (!is_int($lng) && !is_float($lng)) return false;
    if (is_nan((float) $lat) || is_nan((float) $lng) || is_infinite((float) $lat) || is_infinite((float) $lng)) return false;
    return abs($lat) <= 90 && abs($lng) <= 180 && !($lat == 0 && $lng == 0);
  }

  /** Weather forecast (cached 30 min per spot). Throws Exception on failure. */
  public static function forecast($lat, $lng) {
    $key = 'tj_wx_' . md5(sprintf('%.2f,%.2f', $lat, $lng));
    $hit = get_transient($key);
    if (is_array($hit)) return $hit;
    $params = array(
      'latitude' => sprintf('%.4f', $lat), 'longitude' => sprintf('%.4f', $lng), 'timezone' => 'auto', 'timeformat' => 'unixtime', 'forecast_days' => '16',
      'temperature_unit' => 'fahrenheit', 'wind_speed_unit' => 'mph', 'precipitation_unit' => 'inch',
      'current' => 'temperature_2m,apparent_temperature,relative_humidity_2m,weather_code,wind_speed_10m,is_day',
      'hourly' => 'temperature_2m,precipitation_probability,weather_code,wind_speed_10m,is_day',
      'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,sunrise,sunset',
    );
    $res = wp_remote_get('https://api.open-meteo.com/v1/forecast?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986), array('timeout' => 8, 'headers' => array('User-Agent' => self::user_agent())));
    if (is_wp_error($res)) throw new Exception($res->get_error_message());
    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code < 200 || $code >= 300) throw new Exception('Weather service error ' . $code);
    $j = json_decode(wp_remote_retrieve_body($res), true);
    if (!is_array($j)) throw new Exception('Weather service returned invalid data');
    $at = function ($arr, $k, $i) { return isset($arr[$k][$i]) ? $arr[$k][$i] : null; };
    $data = array('timezone' => isset($j['timezone']) ? $j['timezone'] : null, 'utcOffset' => isset($j['utc_offset_seconds']) ? $j['utc_offset_seconds'] : null, 'current' => null, 'daily' => array(), 'hourly' => array());
    if (!empty($j['current'])) {
      $c = $j['current'];
      $data['current'] = array(
        'time' => $c['time'], 'temp' => $c['temperature_2m'], 'feels' => $c['apparent_temperature'],
        'humidity' => $c['relative_humidity_2m'], 'code' => $c['weather_code'], 'wind' => $c['wind_speed_10m'], 'isDay' => !empty($c['is_day']),
      );
    }
    $d = isset($j['daily']) ? $j['daily'] : array();
    foreach ((isset($d['time']) ? $d['time'] : array()) as $i => $t) {
      $data['daily'][] = array('time' => $t, 'code' => $at($d, 'weather_code', $i), 'max' => $at($d, 'temperature_2m_max', $i), 'min' => $at($d, 'temperature_2m_min', $i),
        'rain' => $at($d, 'precipitation_probability_max', $i), 'sunrise' => $at($d, 'sunrise', $i), 'sunset' => $at($d, 'sunset', $i));
    }
    $hh = isset($j['hourly']) ? $j['hourly'] : array();
    foreach ((isset($hh['time']) ? $hh['time'] : array()) as $i => $t) {
      $data['hourly'][] = array('time' => $t, 'temp' => $at($hh, 'temperature_2m', $i), 'rain' => $at($hh, 'precipitation_probability', $i),
        'code' => $at($hh, 'weather_code', $i), 'wind' => $at($hh, 'wind_speed_10m', $i), 'isDay' => !empty($hh['is_day'][$i]));
    }
    set_transient($key, $data, self::WX_TTL);
    return $data;
  }

  // ---------- public holidays for the site's country ----------
  // The Bahamas are calculated from the Public Holidays Act rules (online databases list wrong
  // Bahamian dates). Other countries come from date.nager.at and are cached for a week.
  private static function d($y, $m, $d) { return new DateTime(sprintf('%04d-%02d-%02d', $y, $m, $d), new DateTimeZone('UTC')); }
  private static function nth_weekday($y, $m, $weekday, $n) {
    $first = self::d($y, $m, 1); $off = ($weekday - (int) $first->format('w') + 7) % 7 + ($n - 1) * 7;
    return $first->modify('+' . $off . ' days');
  }
  private static function easter($y) {
    $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    return self::d($y, intdiv($h + $l - 7 * $m + 114, 31), (($h + $l - 7 * $m + 114) % 31) + 1);
  }
  public static function bahamas_holidays($y) {
    $out = array();
    $add = function ($dt, $name, $note = '') use (&$out) { $out[] = array('date' => $dt->format('Y-m-d'), 'name' => $name, 'note' => $note); };
    $fixed = function ($m, $day, $name) use ($y, $add) {
      $dt = self::d($y, $m, $day); $add($dt, $name);
      if ((int) $dt->format('w') === 0) $add((clone $dt)->modify('+1 day'), $name, 'observed');
    };
    $fixed(1, 1, "New Year's Day");
    $fixed(1, 10, 'Majority Rule Day');
    $e = self::easter($y);
    $add((clone $e)->modify('-2 days'), 'Good Friday');
    $add((clone $e)->modify('+1 day'), 'Easter Monday');
    $add((clone $e)->modify('+50 days'), 'Whit Monday');
    $add(self::nth_weekday($y, 6, 5, 1), 'Randol Fawkes Labour Day');
    $fixed(7, 10, 'Independence Day');
    $add(self::nth_weekday($y, 8, 1, 1), 'Emancipation Day');
    $add(self::nth_weekday($y, 10, 1, 2), "National Heroes' Day");
    $x = self::d($y, 12, 25);
    $add($x, 'Christmas Day');
    $add(self::d($y, 12, 26), 'Boxing Day');
    if ((int) $x->format('w') === 6) $add(self::d($y, 12, 27), 'Boxing Day', 'observed');
    if ((int) $x->format('w') === 0) $add(self::d($y, 12, 27), 'Christmas Day', 'observed');
    usort($out, function ($a, $b) { return strcmp($a['date'], $b['date']); });
    return $out;
  }
  private static function country_code($name) {
    $n = strtolower(trim((string) $name));
    if ($n === '' || $n === 'bahamas' || $n === 'the bahamas' || $n === 'bs') return 'BS';
    if (preg_match('/^[a-z]{2}$/', $n)) return strtoupper($n);
    $list = get_transient('tj_nager_countries');
    if (!is_array($list)) {
      $r = wp_remote_get('https://date.nager.at/api/v3/AvailableCountries', array('timeout' => 8));
      $list = !is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200 ? json_decode(wp_remote_retrieve_body($r), true) : array();
      if (!is_array($list)) $list = array();
      set_transient('tj_nager_countries', $list, $list ? WEEK_IN_SECONDS : HOUR_IN_SECONDS);
    }
    $plain = preg_replace('/^the /', '', $n);
    foreach ($list as $c) { $cn = strtolower($c['name']); if ($cn === $n || preg_replace('/^the /', '', $cn) === $plain) return $c['countryCode']; }
    return '';
  }
  public static function public_holidays($country, $year) {
    $code = self::country_code($country);
    if (!$code) return array('country' => (string) $country, 'holidays' => array());
    if ($code === 'BS') return array('country' => 'The Bahamas', 'holidays' => self::bahamas_holidays((int) $year));
    $key = 'tj_hol_' . $code . '_' . (int) $year;
    $c = get_transient($key);
    if (is_array($c)) return $c;
    $val = array('country' => $country ? $country : $code, 'holidays' => array());
    $r = wp_remote_get('https://date.nager.at/api/v3/PublicHolidays/' . (int) $year . '/' . $code, array('timeout' => 8));
    if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
      $seen = array();
      foreach ((array) json_decode(wp_remote_retrieve_body($r), true) as $x) {
        if (isset($x['global']) && $x['global'] === false) continue;
        if (!empty($x['types']) && !in_array('Public', (array) $x['types'], true)) continue;
        $k = $x['date'] . $x['name']; if (isset($seen[$k])) continue; $seen[$k] = 1;
        $val['holidays'][] = array('date' => $x['date'], 'name' => $x['name'], 'note' => '');
      }
    }
    set_transient($key, $val, $val['holidays'] ? WEEK_IN_SECONDS : HOUR_IN_SECONDS);
    return $val;
  }
}

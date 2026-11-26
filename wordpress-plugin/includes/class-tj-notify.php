<?php if (!defined('ABSPATH')) exit;
/**
 * Email (wp_mail, HTML + QR attachments) and SMS (Twilio REST) with a built-in message log.
 * When a channel is not connected, messages are recorded in "preview" mode so they can be reviewed in the Admin Panel.
 * Port of notify.mjs + the Notify object in server.mjs.
 */
class TJ_Notify {
  /** Sandbox context: while set, messages are tagged as tests and staff copies go to the tester. ['orderId','testerEmail'] */
  private static $sandbox = null;
  private static $queue = array();
  private static $hooked = false;

  // ---------- small helpers ----------
  public static function h($s) {
    return strtr((string) $s, array('&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;'));
  }
  public static function normalize_phone($input) {
    $raw = is_string($input) || is_numeric($input) ? trim((string) $input) : '';
    if ($raw === '') return '';
    $digits = preg_replace('/\D/', '', $raw);
    $n = strlen($digits);
    if ($raw[0] === '+') return $n >= 8 && $n <= 15 ? '+' . $digits : null;
    if ($n === 10) return '+1' . $digits;                       // US / Canada / Caribbean NANP
    if ($n === 11 && $digits[0] === '1') return '+' . $digits;
    if ($n === 7) return '+1242' . $digits;                     // Bahamas local number
    return null;
  }
  public static function money($n) { return '$' . number_format((float) $n, 2, '.', ''); }
  public static function when($iso) {
    $ms = TJ_Store::ts_ms($iso);
    if (is_nan($ms)) return 'Invalid Date';
    return wp_date('D, M j, Y, g:i A', (int) floor($ms / 1000));
  }
  public static function N() { $s = TJ_Store::settings(); return $s['notifications']; }
  public static function site() {
    $n = self::N();
    $u = !empty($n['siteUrl']) ? $n['siteUrl'] : home_url();
    return rtrim($u, '/');
  }
  /** App hash route -> page URL (helper from class-tj-frontend.php, with a fallback). */
  public static function route($hash) {
    if (function_exists('tj_route_url')) return tj_route_url($hash);
    if (strpos($hash, '#/t/') === 0) return home_url('/ticket/' . $hash);
    return home_url('/' . ltrim($hash, '/'));
  }
  public static function logo_url() {
    foreach (array('assets/logo.png', 'assets/img/logo.png', 'assets/images/logo.png', 'assets/app/logo.png') as $p) {
      if (defined('TJ_DIR') && file_exists(TJ_DIR . $p)) return apply_filters('tj_logo_url', TJ_URL . $p);
    }
    return apply_filters('tj_logo_url', self::site() . '/logo.png');
  }
  public static function qr_url($code) { return rest_url('tj/v1/qr/' . $code . '.png'); }
  public static function ticket_link($o) { return self::route('#/t/' . $o['id'] . '/' . $o['key']); }
  public static function ticket_key($o, $code) { return substr(hash_hmac('sha256', (string) $code, (string) $o['key']), 0, 20); }
  public static function ticket_pdf_link($o, $t) { return add_query_arg(array('code' => rawurlencode($t['code']), 'tk' => self::ticket_key($o, $t['code'])), rest_url('tj/v1/tickets/' . $o['id'] . '/pdf')); }
  public static function order_pdf_link($o) { return add_query_arg(array('k' => $o['key']), rest_url('tj/v1/tickets/' . $o['id'] . '/pdf')); }
  public static function ticket_pdf_name($ev, $o, $suffix = '') {
    $slug = substr(trim(preg_replace('/[^\w]+/', '-', (string) (isset($ev['title']) ? $ev['title'] : 'Event')), '-'), 0, 40);
    return 'TicketJunkies-' . $slug . '-' . ($suffix !== '' ? $suffix : strtoupper(substr($o['id'], -8))) . '.pdf';
  }
  private static function pdf_attachment($o, $ev, $tickets, $suffix = '') {
    if (!class_exists('TJ_Export')) return null;
    $o['tickets'] = array_values($tickets);
    return array('filename' => self::ticket_pdf_name($ev, $o, $suffix), 'content' => TJ_Export::tickets_pdf($o, $ev, self::site()));
  }
  /** Table of every ticket on an order and who it belongs to (organizer + admin sale emails) */
  private static function attendee_table($o) {
    $th = 'style="text-align:left;padding:8px 10px;border-bottom:2px solid #e5e7eb;font-size:11px;color:#6b7280;text-transform:uppercase"';
    $td = 'style="padding:8px 10px;border-bottom:1px solid #eef0f3;vertical-align:top"';
    $rows = '';
    foreach (array_values($o['tickets']) as $i => $t) {
      $nm = !empty($t['holderName']) ? $t['holderName'] : $o['buyerName'];
      $em = !empty($t['holderEmail']) ? $t['holderEmail'] : $o['buyerEmail'];
      $pr = !empty($t['price']) ? self::money($t['price']) : (!empty($o['comp']) ? 'Comp' : 'Free');
      $rows .= '<tr><td ' . $td . '>' . ($i + 1) . '</td><td ' . $td . '><strong>' . self::h($nm) . '</strong><br><span style="color:#6b7280">' . self::h($em) . '</span></td><td ' . $td . '>' . self::h($t['tierName']) . '<br><span style="color:#6b7280">' . $pr . '</span></td><td style="padding:8px 10px;border-bottom:1px solid #eef0f3;vertical-align:top;font-family:Menlo,Consolas,monospace;font-weight:700;color:#376eb8">' . self::h($t['code']) . '</td></tr>';
    }
    return '<h3 style="font-size:15px;margin:20px 0 8px">👥 Attendees (' . count($o['tickets']) . ')</h3><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px"><tr><th ' . $th . '>#</th><th ' . $th . '>Attendee</th><th ' . $th . '>Ticket</th><th ' . $th . '>Code</th></tr>' . $rows . '</table>';
  }
  public static function event_link($ev) { return self::route('#/event/' . $ev['id']); }
  private static function first_name($name) { $p = preg_split('/\s+/', trim((string) $name)); return $p[0]; }
  private static function ref($o) { return strtoupper(substr($o['id'], -8)); }
  private static function s($n) { return $n > 1 ? 's' : ''; }

  public static function sandbox_ctx() { return self::$sandbox; }
  /** Runs $fn with the sandbox context set (null = normal). */
  public static function with_sandbox($ctx, $fn) {
    $prev = self::$sandbox;
    self::$sandbox = $ctx;
    try { return $fn(); } finally { self::$sandbox = $prev; }
  }
  private static function context() {
    return self::$sandbox ? array('test' => true, 'ref' => self::$sandbox['orderId']) : array();
  }

  // ---------- branded email layout ----------
  public static function email_layout($a) {
    $siteUrl = $a['siteUrl'];
    $pre = isset($a['preheader']) ? $a['preheader'] : '';
    $cta = isset($a['cta']) ? $a['cta'] : null;
    $foot = isset($a['footerNote']) ? $a['footerNote'] : '';
    $btn = $cta ? '<tr><td style="padding:8px 0 4px"><a href="' . self::h($cta['url']) . '" style="display:inline-block;background:#eb742a;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 24px;border-radius:10px;font-size:15px">' . self::h($cta['label']) . '</a></td></tr>' : '';
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::h($a['heading']) . '</title></head>
<body style="margin:0;padding:0;background:#f2f4f8;font-family:Helvetica,Arial,sans-serif;color:#1b1f2a">
<span style="display:none;max-height:0;overflow:hidden;opacity:0">' . self::h($pre) . '</span>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f8;padding:24px 10px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden">
  <tr><td style="padding:22px 28px;border-bottom:4px solid #84d65f"><a href="' . self::h($siteUrl) . '"><img src="' . self::h(self::logo_url()) . '" alt="TICKETJUNKIES.COM" width="260" style="display:block;width:260px;max-width:100%;height:auto;border:0;font-family:Impact,Arial;font-size:22px;color:#376eb8"></a></td></tr>
  <tr><td style="padding:28px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
      <tr><td style="font-size:24px;font-weight:800;padding-bottom:12px;color:#1b1f2a">' . self::h($a['heading']) . '</td></tr>
      <tr><td style="font-size:15px;line-height:1.6;color:#374151">' . $a['bodyHtml'] . '</td></tr>
      ' . $btn . '
    </table>
  </td></tr>
  <tr><td style="padding:18px 28px;background:#12161f;color:#9aa3b2;font-size:12px;line-height:1.5">
    ' . ($foot ? self::h($foot) . '<br>' : '') . 'TicketJunkies.com · <a href="' . self::h($siteUrl) . '" style="color:#84d65f">Browse events</a>
  </td></tr>
</table></td></tr></table></body></html>';
  }

  public static function detail_table($rows) {
    $out = '';
    $i = 0;
    foreach ($rows as $r) {
      if (!$r) continue;
      $b = $i ? 'border-top:1px solid #e5e7eb;' : '';
      $out .= '<tr><td style="padding:10px 14px;color:#6b7280;font-size:13px;' . $b . 'width:38%">' . self::h($r[0]) . '</td><td style="padding:10px 14px;font-weight:600;font-size:14px;' . $b . '">' . $r[1] . '</td></tr>';
      $i++;
    }
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:14px 0;border:1px solid #e5e7eb;border-radius:10px;border-collapse:separate">' . $out . '</table>';
  }

  // ---------- delivery + log ----------
  private static function record($entry) {
    $entry = array_merge(array('id' => 'ntf_' . bin2hex(random_bytes(6)), 'createdAt' => TJ_Store::now_iso()), $entry);
    TJ_Store::add_message($entry);
    return $entry;
  }

  /** Synchronous delivery (used by the "send test" button). Returns the log entry. */
  public static function deliver_email($m) {
    $entry = self::queue_email($m, false);
    if ($entry && $entry['status'] === 'pending') $entry = self::send_email_now($entry, $m);
    return $entry;
  }
  public static function deliver_sms($m) {
    $entry = self::queue_sms($m, false);
    if ($entry && $entry['status'] === 'pending') $entry = self::send_sms_now($entry);
    return $entry;
  }

  /** Fire-and-forget: the log entry is written now, the actual send happens after the response is sent. */
  public static function email($m) { self::queue_email(array_merge($m, self::context()), true); }
  public static function sms($m) { self::queue_sms(array_merge($m, self::context()), true); }

  private static function queue_email($m, $defer) {
    $n = self::N();
    $recipients = array_values(array_filter(is_array($m['to']) ? $m['to'] : array($m['to'])));
    if (!$recipients) return null;
    $atts = isset($m['attachments']) ? $m['attachments'] : array();
    $entry = self::record(array(
      'channel' => 'email', 'tag' => $m['tag'], 'test' => !empty($m['test']), 'ref' => isset($m['ref']) ? $m['ref'] : '',
      'to' => implode(', ', $recipients), 'subject' => $m['subject'], 'html' => $m['html'], 'text' => isset($m['text']) ? $m['text'] : '', 'status' => 'pending',
      'attachments' => array_map(function ($a) { return $a['filename']; }, $atts),
    ));
    if (empty($n['emailEnabled'])) {
      $entry['status'] = 'preview';
      $entry['detail'] = 'Email sending is not connected yet (Admin → Settings). Saved for preview.';
      return TJ_Store::update_message($entry);
    }
    if ($defer) { self::defer(array('email', $entry, array('to' => $recipients, 'attachments' => $atts))); }
    return $entry;
  }

  private static function queue_sms($m, $defer) {
    $body = (string) $m['body'];
    if (!empty($m['test']) && strpos($body, '[TEST]') !== 0) $body = '[TEST] ' . $body;
    $n = self::N();
    if (empty($m['to'])) return null;
    $entry = self::record(array(
      'channel' => 'sms', 'tag' => $m['tag'], 'test' => !empty($m['test']), 'ref' => isset($m['ref']) ? $m['ref'] : '',
      'to' => $m['to'], 'subject' => function_exists('mb_substr') ? mb_substr($body, 0, 60) : substr($body, 0, 60), 'text' => $body, 'status' => 'pending',
    ));
    if (empty($n['smsEnabled']) || empty($n['twilioSid']) || empty($n['twilioToken']) || empty($n['twilioFrom'])) {
      $entry['status'] = 'preview';
      $entry['detail'] = 'Text messaging is not connected yet (Admin → Settings). Saved for preview.';
      return TJ_Store::update_message($entry);
    }
    if ($defer) self::defer(array('sms', $entry));
    return $entry;
  }

  private static function defer($job) {
    self::$queue[] = $job;
    if (!self::$hooked) {
      self::$hooked = true;
      add_action('shutdown', array(__CLASS__, 'flush_queue'), 1);
    }
  }
  /** Sends queued messages after the HTTP response has been delivered (when the server supports it). */
  public static function flush_queue() {
    if (!self::$queue) return;
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
    $jobs = self::$queue;
    self::$queue = array();
    foreach ($jobs as $j) {
      try {
        if ($j[0] === 'email') self::send_email_now($j[1], $j[2]);
        else self::send_sms_now($j[1]);
      } catch (Exception $e) { error_log('[tj notify] ' . $e->getMessage()); }
    }
  }

  private static function send_email_now($entry, $m) {
    $n = self::N();
    $recipients = is_array($m['to']) ? array_values(array_filter($m['to'])) : array_filter(array($m['to']));
    $headers = array('Content-Type: text/html; charset=UTF-8');
    if (!empty($n['emailFrom'])) $headers[] = 'From: ' . $n['emailFrom'];
    if (!empty($n['replyTo'])) $headers[] = 'Reply-To: ' . $n['replyTo'];
    // write attachments to a private temp folder (file name = attachment name), delete afterwards
    $files = array();
    $dir = '';
    if (!empty($m['attachments'])) {
      $dir = trailingslashit(get_temp_dir()) . 'tj-mail-' . bin2hex(random_bytes(6));
      wp_mkdir_p($dir);
      foreach ($m['attachments'] as $a) {
        $p = $dir . '/' . sanitize_file_name($a['filename']);
        file_put_contents($p, $a['content']);
        $files[] = $p;
      }
    }
    $error = '';
    $onfail = function ($e) use (&$error) { $error = is_wp_error($e) ? $e->get_error_message() : 'wp_mail failed'; };
    $text = isset($entry['text']) ? $entry['text'] : '';
    $alt = function ($phpmailer) use ($text) { if ($text) $phpmailer->AltBody = $text; };
    add_action('wp_mail_failed', $onfail);
    add_action('phpmailer_init', $alt);
    try {
      $ok = wp_mail($recipients, $entry['subject'], $entry['html'], $headers, $files);
    } catch (Exception $e) { $ok = false; $error = $e->getMessage(); }
    remove_action('wp_mail_failed', $onfail);
    remove_action('phpmailer_init', $alt);
    foreach ($files as $f) @unlink($f);
    if ($dir) @rmdir($dir);
    if ($ok) { $entry['status'] = 'sent'; $entry['detail'] = 'Sent with wp_mail'; }
    else { $entry['status'] = 'failed'; $entry['detail'] = $error ? $error : 'wp_mail could not send the message'; error_log('[tj email] failed: ' . $entry['subject'] . ' -> ' . $entry['to'] . ' ' . $entry['detail']); }
    return TJ_Store::update_message($entry);
  }

  private static function send_sms_now($entry) {
    $n = self::N();
    $res = wp_remote_post('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($n['twilioSid']) . '/Messages.json', array(
      'timeout' => 15,
      'headers' => array('Authorization' => 'Basic ' . base64_encode($n['twilioSid'] . ':' . $n['twilioToken'])),
      'body' => array('To' => $entry['to'], 'From' => $n['twilioFrom'], 'Body' => $entry['text']),
    ));
    if (is_wp_error($res)) { $entry['status'] = 'failed'; $entry['detail'] = $res->get_error_message(); }
    else {
      $code = (int) wp_remote_retrieve_response_code($res);
      $data = json_decode(wp_remote_retrieve_body($res), true);
      if ($code >= 200 && $code < 300) { $entry['status'] = 'sent'; $entry['detail'] = 'Twilio sid ' . (isset($data['sid']) ? $data['sid'] : ''); }
      else { $entry['status'] = 'failed'; $entry['detail'] = isset($data['message']) ? $data['message'] : 'Twilio error ' . $code; }
    }
    if ($entry['status'] === 'failed') error_log('[tj sms] failed -> ' . $entry['to'] . ' ' . $entry['detail']);
    return TJ_Store::update_message($entry);
  }

  // ---------- mail helpers (sandbox redirection + admin alerts) ----------
  public static function active_admins() {
    $out = array();
    foreach (TJ_Store::users() as $u) if ($u['role'] === 'admin' && !$u['suspended']) $out[] = $u;
    return $out;
  }
  public static function admin_recipients() {
    $n = self::N();
    if (!empty($n['adminEmail'])) return array_values(array_filter(preg_split('/[,;\s]+/', $n['adminEmail'])));
    return array_map(function ($u) { return $u['email']; }, self::active_admins());
  }
  private static function organizer_of($ev) { return TJ_Store::user_by_id($ev['organizerId']); }
  private static function ev_rows($ev) {
    return array(
      array('Event', '<strong>' . self::h($ev['title']) . '</strong>'),
      array('When', self::h(self::when($ev['startsAt']))),
      array('Where', self::h(implode(', ', array_filter(array($ev['venue'], $ev['address'], $ev['city']), 'strlen')))),
    );
  }

  public static function mail($to, $subject, $o) {
    $tag = (string) $o['tag'];
    $body = $o['body'];
    $sb = self::$sandbox;
    if ($sb) {
      // Never bother real organizers/admins with test messages: their copies go to the person running the test.
      if (strpos($tag, 'buyer.') !== 0) {
        $role = strpos($tag, 'admin.') === 0 ? 'admin' : 'organizer';
        $body = '<p style="background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;padding:10px 14px;border-radius:10px;font-size:13px">🧪 Sandbox test — this is the <strong>' . $role . '</strong> copy. In a real sale it goes to ' . self::h(implode(', ', (array) $to)) . '.</p>' . $body;
        $to = $sb['testerEmail'];
      }
      $subject = '[TEST] ' . $subject;
    }
    $cta = isset($o['cta']) ? $o['cta'] : null;
    $pre = isset($o['preheader']) ? $o['preheader'] : '';
    self::email(array(
      'to' => $to, 'subject' => $subject, 'tag' => $tag, 'attachments' => isset($o['attachments']) ? $o['attachments'] : array(),
      'html' => self::email_layout(array('siteUrl' => self::site(), 'heading' => $o['heading'], 'preheader' => $pre, 'bodyHtml' => $body, 'cta' => $cta, 'footerNote' => isset($o['footerNote']) ? $o['footerNote'] : '')),
      'text' => $o['heading'] . "\n\n" . $pre . "\n\n" . ($cta ? $cta['label'] . ': ' . $cta['url'] : ''),
    ));
  }
  private static function alert_admin($kind, $subject, $o) {
    $n = self::N();
    if (empty($n['adminAlerts'][$kind])) return;
    self::mail(self::admin_recipients(), '[Admin] ' . $subject, array_merge($o, array('tag' => 'admin.' . $kind, 'footerNote' => 'You receive this because you are a TicketJunkies administrator.')));
  }
  private static function qr_attachments($o) {
    $out = array();
    if (!class_exists('TJ_QR')) return $out;
    foreach ($o['tickets'] as $t) $out[] = array('filename' => $t['code'] . '.png', 'content' => TJ_QR::png($t['code'], 10));
    return $out;
  }

  // ---------- templates ----------
  public static function registered($u) {
    $org = $u['role'] === 'organizer';
    self::alert_admin($org ? 'organizerSignup' : 'userSignup', $org ? 'New organizer: ' . ($u['organization'] ? $u['organization'] : $u['name']) : 'New user: ' . $u['name'], array(
      'heading' => $org ? 'New organizer registered' : 'New user registered',
      'preheader' => $u['name'] . ' (' . $u['email'] . ') just created an account.',
      'body' => self::detail_table(array(array('Name', self::h($u['name'])), array('Email', self::h($u['email'])), $u['phone'] ? array('Phone', self::h($u['phone'])) : null, array('Account type', $org ? 'Organizer' : 'Attendee'), $org ? array('Organizer name', self::h($u['organization'])) : null, array('Joined', self::h(self::when($u['createdAt']))))),
      'cta' => array('label' => 'View in Admin Panel', 'url' => self::route('#/admin/user/' . $u['id'])),
    ));
    self::mail($u['email'], $org ? 'Welcome to TicketJunkies — start selling tickets' : 'Welcome to TicketJunkies!', array(
      'tag' => 'user.welcome',
      'heading' => 'Welcome, ' . self::first_name($u['name']) . '!',
      'preheader' => $org ? 'Your organizer account is ready.' : 'Your account is ready.',
      'body' => $org
        ? '<p>Your organizer account for <strong>' . self::h($u['organization']) . '</strong> is ready. Create your first event, set your ticket types and start selling in minutes.</p><p>We\'ll email you every time you sell tickets, so you always know how your events are doing.</p>'
        : '<p>Your TicketJunkies account is ready. Discover parties, concerts, comedy shows and festivals, and get your tickets in seconds.</p>',
      'cta' => $org ? array('label' => 'Create an event', 'url' => self::route('#/create')) : array('label' => 'Browse events', 'url' => home_url('/')),
    ));
  }

  public static function event_created($ev, $actor) {
    $p = self::organizer_of($ev);
    $live = $ev['status'] === 'published';
    $pname = (!empty($ev['organizerName']) ? $ev['organizerName'] : ($p ? ($p['organization'] ? $p['organization'] : $p['name']) : ''));
    $tiers = array();
    foreach ($ev['tiers'] as $t) $tiers[] = self::h($t['name']) . ' — ' . ($t['price'] ? self::money($t['price']) : 'Free') . ' × ' . $t['quantity'];
    self::alert_admin('eventCreated', 'New event: ' . $ev['title'], array(
      'heading' => 'New event created',
      'preheader' => $pname . ' created "' . $ev['title'] . '".',
      'body' => self::detail_table(array_merge(self::ev_rows($ev), array(
        array('Organizer', self::h($pname) . '<br><span style="color:#6b7280;font-weight:400">' . self::h($p ? $p['email'] : '') . '</span>'),
        array('Status', $live ? 'Published' : 'Draft'),
        array('Tickets', implode('<br>', $tiers)),
      ))),
      'cta' => array('label' => 'Review event', 'url' => self::event_link($ev)),
    ));
    if ($p) self::mail($p['email'], $live ? 'Your event is live: ' . $ev['title'] : 'Draft saved: ' . $ev['title'], array(
      'tag' => 'organizer.eventCreated',
      'heading' => $live ? 'Your event is live! 🎉' : 'Your event draft is saved',
      'preheader' => $live ? 'Tickets are now on sale.' : 'Publish it when you are ready to sell.',
      'body' => '<p>' . ($live ? 'Tickets for your event are now on sale on TicketJunkies.com. Share the link with your fans!' : 'Your event is saved as a draft and is only visible to you. Publish it from your dashboard to start selling.') . '</p>' . self::detail_table(self::ev_rows($ev)) . ($actor && $actor['id'] !== $ev['organizerId'] ? '<p style="color:#6b7280">This event was created for you by a TicketJunkies administrator.</p>' : ''),
      'cta' => $live ? array('label' => 'View event page', 'url' => self::event_link($ev)) : array('label' => 'Open dashboard', 'url' => self::route('#/dashboard')),
    ));
  }

  public static function event_changed($ev, $before, $actor) {
    $p = self::organizer_of($ev);
    if (!$p) return;
    $byAdmin = $actor && $actor['id'] !== $ev['organizerId'];
    $statusMsg = array(
      'published' => array('Your event is live', 'Tickets are now on sale.'),
      'draft' => array('Your event was unpublished', 'It is hidden from the public and tickets are not on sale.'),
      'cancelled' => array('Your event was cancelled', 'Ticket sales have stopped.'),
    );
    if ($before['status'] !== $ev['status']) {
      list($heading, $msg) = $statusMsg[$ev['status']];
      self::mail($p['email'], $heading . ': ' . $ev['title'], array('tag' => 'organizer.eventStatus', 'heading' => $heading, 'preheader' => $msg, 'body' => '<p>' . $msg . ($byAdmin ? ' This change was made by a TicketJunkies administrator.' : '') . '</p>' . self::detail_table(self::ev_rows($ev)), 'cta' => array('label' => 'Open dashboard', 'url' => self::route('#/dashboard'))));
      if ($ev['status'] === 'cancelled') self::event_cancelled_buyers($ev);
    } else {
      self::mail($p['email'], 'Event updated: ' . $ev['title'], array('tag' => 'organizer.eventUpdated', 'heading' => 'Your event was updated', 'preheader' => 'Changes to "' . $ev['title'] . '" were saved.', 'body' => '<p>Changes to your event were saved' . ($byAdmin ? ' by a TicketJunkies administrator' : '') . '.</p>' . self::detail_table(self::ev_rows($ev)), 'cta' => array('label' => 'View event page', 'url' => self::event_link($ev))));
    }
  }

  public static function event_deleted($ev, $actor) {
    $p = self::organizer_of($ev);
    if ($p) self::mail($p['email'], 'Event deleted: ' . $ev['title'], array('tag' => 'organizer.eventDeleted', 'heading' => 'Your event was deleted', 'preheader' => '"' . $ev['title'] . '" has been removed.', 'body' => '<p>"' . self::h($ev['title']) . '" was deleted' . ($actor && $actor['id'] !== $ev['organizerId'] ? ' by a TicketJunkies administrator' : '') . '. It had no ticket sales.</p>', 'cta' => array('label' => 'Open dashboard', 'url' => self::route('#/dashboard'))));
  }

  public static function event_cancelled_buyers($ev) {
    foreach (TJ_Store::orders() as $o) {
      if ($o['eventId'] !== $ev['id'] || $o['status'] === 'refunded' || !empty($o['test'])) continue;
      $n = count($o['tickets']);
      self::mail($o['buyerEmail'], 'Event cancelled: ' . $ev['title'], array('tag' => 'buyer.eventCancelled', 'heading' => 'Your event has been cancelled', 'preheader' => '"' . $ev['title'] . '" will not go ahead.', 'body' => '<p>Hi ' . self::h(self::first_name($o['buyerName'])) . ', we\'re sorry — the organizer has cancelled this event. Your order (' . $n . ' ticket' . self::s($n) . ', ' . self::money($o['total']) . ') will be refunded.</p>' . self::detail_table(self::ev_rows($ev))));
      if (!empty($o['buyerPhone'])) self::sms(array('to' => $o['buyerPhone'], 'tag' => 'buyer.eventCancelled', 'body' => 'TicketJunkies: "' . $ev['title'] . '" on ' . self::when($ev['startsAt']) . ' has been CANCELLED. Your order of ' . self::money($o['total']) . ' will be refunded.'));
    }
  }

  /** opts: buyerOnly, email, sms — buyerOnly is used when a customer asks to resend their tickets */
  public static function order_placed($o, $ev, $opts = array()) {
    $sendEmail = !isset($opts['email']) || $opts['email'] !== false;
    $sendSms = !isset($opts['sms']) || $opts['sms'] !== false;
    $p = self::organizer_of($ev);
    $qty = count($o['tickets']);
    $comp = !empty($o['comp']);
    $items = array();
    foreach ($o['items'] as $i) $items[] = $i['qty'] . ' × ' . self::h($i['tierName']) . ' (' . ($comp ? 'Complimentary' : ($i['price'] ? self::money($i['price']) : 'Free')) . ')';
    $itemsHtml = implode('<br>', $items);
    $qrBlock = function ($t) use ($ev) {
      return '
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px;border:2px dashed #eb742a;border-radius:14px"><tr>
        <td style="padding:14px;width:170px" valign="middle"><img src="' . self::h(self::qr_url($t['code'])) . '" width="160" height="160" alt="QR code ' . self::h($t['code']) . '" style="display:block;width:160px;height:160px;border:0"></td>
        <td style="padding:14px 14px 14px 0" valign="middle">
          <div style="font-size:12px;color:#eb742a;font-weight:800;text-transform:uppercase">' . self::h($t['tierName']) . '</div>
          <div style="font-size:17px;font-weight:800;margin:4px 0">' . self::h($ev['title']) . '</div>
          <div style="font-size:13px;color:#6b7280">' . self::h(self::when($ev['startsAt'])) . '<br>' . self::h($ev['venue']) . ', ' . self::h($ev['city']) . '</div>
          <div style="font-family:Menlo,Consolas,monospace;font-size:18px;font-weight:800;color:#376eb8;margin-top:8px;letter-spacing:1px">' . self::h($t['code']) . '</div>
          ' . (!empty($t['holderName']) ? '<div style="font-size:13px;font-weight:700;margin-top:6px">👤 ' . self::h($t['holderName']) . '</div>' : '') . '
        </td></tr></table>';
    };
    $qrBlocks = implode('', array_map($qrBlock, $o['tickets']));
    $pngs = function ($list) { $out = array(); if (class_exists('TJ_QR')) foreach ($list as $t) $out[] = array('filename' => $t['code'] . '.png', 'content' => TJ_QR::png($t['code'], 10)); return $out; };
    $withPdf = function ($pdf, $rest) { return $pdf ? array_merge(array($pdf), $rest) : $rest; };
    $buyerMail = strtolower((string) $o['buyerEmail']);
    // Tickets for other people with their own email address, grouped per person
    $guests = array();
    if (!$comp) foreach ($o['tickets'] as $t) {
      if (empty($t['holderEmail']) || $t['holderEmail'] === $buyerMail) continue;
      if (!isset($guests[$t['holderEmail']])) $guests[$t['holderEmail']] = array('name' => $t['holderName'], 'email' => $t['holderEmail'], 'tickets' => array());
      $guests[$t['holderEmail']]['tickets'][] = $t;
    }
    $guestNames = array();
    foreach ($guests as $g) $guestNames[] = '<strong>' . self::h($g['name']) . '</strong> (' . self::h($g['email']) . ')';
    $first = self::h(self::first_name($o['buyerName']));
    if ($comp) {
      $fromRaw = (!empty($ev['organizerName']) ? $ev['organizerName'] : ($p ? ($p['organization'] ? $p['organization'] : $p['name']) : 'The organizer'));
      $from = self::h($fromRaw);
      if ($sendEmail) self::mail($o['buyerEmail'], 'Complimentary tickets: ' . $ev['title'], array(
        'tag' => 'buyer.comp',
        'heading' => "You're on the list! 🎟️",
        'preheader' => $from . ' sent you ' . $qty . ' complimentary ticket' . self::s($qty) . ' for ' . $ev['title'] . '.',
        'body' => '<p>Hi ' . $first . ', <strong>' . $from . '</strong> has sent you ' . $qty . ' complimentary ticket' . self::s($qty) . ' — no payment needed. Show the QR code below at the door, on your phone or printed. Each ticket admits one person and can be scanned once.</p>
          ' . (!empty($o['compNote']) ? '<p style="background:#fdeee4;border-radius:10px;padding:12px 14px;margin:0 0 14px"><strong>Message:</strong> ' . self::h($o['compNote']) . '</p>' : '') . '
          ' . $qrBlocks . '
          ' . self::detail_table(array_merge(self::ev_rows($ev), array(array('Tickets', $itemsHtml), array('Price', '<strong>Complimentary — $0.00</strong>'), array('Order #', self::h(self::ref($o)))))) . '
          <p style="font-size:13px;color:#6b7280">QR codes are also attached as images, and you can always open your tickets from the button below.</p>',
        'cta' => array('label' => 'View my tickets', 'url' => self::ticket_link($o)),
        'attachments' => self::qr_attachments($o),
      ));
      if ($sendSms && !empty($o['buyerPhone'])) self::sms(array('to' => $o['buyerPhone'], 'tag' => 'buyer.comp', 'body' => 'TicketJunkies: ' . $fromRaw . ' sent you ' . $qty . ' complimentary ticket' . self::s($qty) . ' for ' . $ev['title'] . ', ' . self::when($ev['startsAt']) . ' @ ' . $ev['venue'] . '. Your QR tickets: ' . self::ticket_link($o)));
      return; // comps are not sales: no sale emails to the organizer or admin
    }
    if ($sendEmail) self::mail($o['buyerEmail'], 'Your tickets: ' . $ev['title'], array(
      'tag' => 'buyer.tickets',
      'heading' => "You're going! 🎟️",
      'preheader' => $qty . ' ticket' . self::s($qty) . ' for ' . $ev['title'] . ' — show the QR code at the door.',
      'body' => '<p>Hi ' . $first . ', thanks for your order! Show the QR code below at the door — on your phone or printed. Each ticket admits one person and can be scanned once.</p>
        ' . $qrBlocks . '
        <p style="margin:0 0 16px"><a href="' . self::h(self::order_pdf_link($o)) . '" style="display:inline-block;background:#376eb8;color:#ffffff;font-weight:700;text-decoration:none;padding:10px 16px;border-radius:10px">⬇ Download tickets (PDF)</a></p>
        ' . ($guests ? '<p style="background:#eef7e9;border-radius:10px;padding:12px 14px;margin:0 0 14px">📧 We also emailed each guest their own ticket: ' . implode(', ', $guestNames) . '.</p>' : '') . '
        ' . self::detail_table(array_merge(self::ev_rows($ev), array(array('Tickets', $itemsHtml), array('Subtotal', self::money($o['subtotal'])), array('Service fees', self::money($o['fees'])), array('Total paid', '<strong>' . self::money($o['total']) . '</strong>' . (!empty($o['cardLast4']) ? ' <span style="color:#6b7280;font-weight:400">(card •••• ' . self::h($o['cardLast4']) . ')</span>' : '')), array('Order #', self::h(self::ref($o)))))) . '
        <p style="font-size:13px;color:#6b7280">Your tickets are attached as a PDF (easy to print, save or share) and as QR images. You can always open them from the button below.</p>',
      'cta' => array('label' => 'View my tickets', 'url' => self::ticket_link($o)),
      'attachments' => $withPdf(self::pdf_attachment($o, $ev, $o['tickets']), $pngs($o['tickets'])),
    ));
    if ($sendSms && !empty($o['buyerPhone'])) self::sms(array('to' => $o['buyerPhone'], 'tag' => 'buyer.tickets', 'body' => 'TicketJunkies: You\'re going to ' . $ev['title'] . '! ' . $qty . ' ticket' . self::s($qty) . ', ' . self::when($ev['startsAt']) . ' @ ' . $ev['venue'] . '. Order ' . self::ref($o) . ', total ' . self::money($o['total']) . '. Your QR tickets: ' . self::ticket_link($o)));
    if (!empty($opts['buyerOnly'])) return;

    // Each guest: their own ticket(s) + a PDF they can download or share
    foreach ($guests as $g) {
      $n = count($g['tickets']);
      $gFirst = self::first_name($g['name']);
      $links = '';
      foreach ($g['tickets'] as $t) $links .= '<a href="' . self::h(self::ticket_pdf_link($o, $t)) . '" style="display:inline-block;background:#376eb8;color:#ffffff;font-weight:700;text-decoration:none;padding:10px 16px;border-radius:10px;margin:0 6px 6px 0">⬇ Download ticket ' . self::h($t['code']) . ' (PDF)</a>';
      $tiers = implode('<br>', array_map(function ($t) { return self::h($t['tierName']); }, $g['tickets']));
      $suffix = $n > 1 ? self::ref($o) . '-' . substr(preg_replace('/[^\w]+/', '-', $g['name']), 0, 20) : $g['tickets'][0]['code'];
      self::mail($g['email'], 'Your ticket' . self::s($n) . ': ' . $ev['title'], array(
        'tag' => 'buyer.attendee',
        'heading' => "You're going, " . $gFirst . '! 🎟️',
        'preheader' => $o['buyerName'] . ' got you ' . ($n > 1 ? $n . ' tickets' : 'a ticket') . ' for ' . $ev['title'] . '.',
        'body' => '<p>Hi ' . self::h($gFirst) . ', <strong>' . self::h($o['buyerName']) . '</strong> got you ' . ($n > 1 ? $n . ' tickets' : 'a ticket') . ' for <strong>' . self::h($ev['title']) . '</strong>. Show the QR code below at the door, on your phone or printed. Each ticket admits one person and can be scanned once.</p>
          ' . implode('', array_map($qrBlock, $g['tickets'])) . '
          <p style="margin:0 0 16px">' . $links . '</p>
          ' . self::detail_table(array_merge(self::ev_rows($ev), array(array('Ticket', $tiers), array('From', self::h(self::first_name($o['buyerName'])) . ' (' . self::h($o['buyerEmail']) . ')')))) . '
          <p style="font-size:13px;color:#6b7280">Your ticket is attached as a PDF you can save, print or share, plus a QR image.</p>',
        'cta' => array('label' => 'Download my ticket' . self::s($n) . ' (PDF)', 'url' => self::ticket_pdf_link($o, $g['tickets'][0])),
        'attachments' => $withPdf(self::pdf_attachment($o, $ev, $g['tickets'], $suffix), $pngs($g['tickets'])),
      ));
    }

    // Organizer: sale notification + sold-out alerts
    $buyerCell = '<strong>' . self::h($o['buyerName']) . '</strong><br><span style="color:#6b7280;font-weight:400">' . self::h($o['buyerEmail']) . (!empty($o['buyerPhone']) ? '<br>' . self::h($o['buyerPhone']) : '') . '</span>';
    $orderCell = self::h(self::ref($o)) . ' · ' . self::h(self::when($o['createdAt']));
    if ($p) {
      $stats = TJ_Rest::event_stats($ev);
      self::mail($p['email'], '🎟️ ' . $qty . ' ticket' . self::s($qty) . ' sold — ' . $ev['title'], array(
        'tag' => 'organizer.sale',
        'heading' => 'You sold ' . $qty . ' ticket' . self::s($qty) . '!',
        'preheader' => $o['buyerName'] . ' bought ' . $qty . ' ticket' . self::s($qty) . ' for ' . $ev['title'] . '.',
        'body' => self::detail_table(array(array('Event', '<strong>' . self::h($ev['title']) . '</strong>'), array('When', self::h(self::when($ev['startsAt']))), array('Order #', $orderCell), array('Buyer', $buyerCell), array('Tickets', $itemsHtml), array('Your revenue (this order)', '<strong>' . self::money($o['subtotal']) . '</strong>'), array('Total sold', $stats['sold'] . ' / ' . $stats['capacity']), array('Total revenue to date', self::money($stats['revenue'])))) . self::attendee_table($o),
        'cta' => array('label' => 'View attendees', 'url' => self::route('#/attendees/' . $ev['id'])),
      ));
      foreach ($o['items'] as $it) {
        $tier = null;
        foreach ($ev['tiers'] as $t) if ($t['id'] === $it['tierId']) $tier = $t;
        if ($tier && $tier['sold'] >= $tier['quantity']) self::mail($p['email'], 'Sold out: ' . $tier['name'] . ' — ' . $ev['title'], array('tag' => 'organizer.soldOut', 'heading' => '"' . $tier['name'] . '" is sold out! 🔥', 'preheader' => 'All ' . $tier['quantity'] . ' ' . $tier['name'] . ' tickets are gone.', 'body' => '<p>All <strong>' . $tier['quantity'] . '</strong> "' . self::h($tier['name']) . '" tickets for <strong>' . self::h($ev['title']) . '</strong> have been sold. You can add more tickets or a new ticket type from your dashboard.</p>', 'cta' => array('label' => 'Edit event', 'url' => self::route('#/edit/' . $ev['id']))));
      }
    }

    // Admin: sale alert
    self::alert_admin('ticketSale', 'Sale: ' . $qty . ' × ' . $ev['title'] . ' (' . self::money($o['total']) . ')', array(
      'heading' => 'New ticket sale',
      'preheader' => $o['buyerName'] . ' paid ' . self::money($o['total']) . ' for ' . $ev['title'] . '.',
      'body' => self::detail_table(array_values(array_filter(array_merge(self::ev_rows($ev), array(
        array('Organizer', self::h((!empty($ev['organizerName']) ? $ev['organizerName'] : ($p ? ($p['organization'] ? $p['organization'] : $p['name']) : ''))) . ($p ? '<br><span style="color:#6b7280;font-weight:400">' . self::h($p['email']) . (!empty($p['phone']) ? ' · ' . self::h($p['phone']) : '') . '</span>' : '')),
        array('Order #', $orderCell),
        array('Buyer', $buyerCell),
        array('Tickets', $itemsHtml),
        array('Ticket sales', self::money($o['subtotal'])),
        array('Platform fees (yours)', '<strong>' . self::money($o['fees']) . '</strong>'),
        array('Total collected', '<strong>' . self::money($o['total']) . '</strong>'),
        !empty($o['cardLast4']) ? array('Payment', self::h(!empty($o['cardBrand']) ? $o['cardBrand'] : 'Card') . ' •••• ' . self::h($o['cardLast4']) . (!empty($o['cardHolder']) ? ' · ' . self::h($o['cardHolder']) : '')) : null,
        $guests ? array('Guest emails sent', (string) count($guests)) : null,
      ))))) . self::attendee_table($o),
      'cta' => array('label' => 'View orders', 'url' => self::route('#/admin/orders')),
    ));
  }

  public static function order_refunded($o) {
    $ev = TJ_Store::get_event($o['eventId']);
    $title = $ev ? $ev['title'] : 'your event';
    $codes = array_map(function ($t) { return $t['code']; }, $o['tickets']);
    $n = count($o['tickets']);
    self::mail($o['buyerEmail'], 'Refund issued: ' . $title, array('tag' => 'buyer.refund', 'heading' => 'Your order was refunded', 'preheader' => self::money($o['total']) . ' is on its way back to you.', 'body' => '<p>Hi ' . self::h(self::first_name($o['buyerName'])) . ', your order for <strong>' . self::h($title) . '</strong> has been refunded (' . self::money($o['total']) . '). The tickets on this order are no longer valid.</p>' . self::detail_table(array(array('Order #', self::h(self::ref($o))), array('Tickets', implode(', ', $codes))))));
    if (!empty($o['buyerPhone'])) self::sms(array('to' => $o['buyerPhone'], 'tag' => 'buyer.refund', 'body' => 'TicketJunkies: Your order ' . self::ref($o) . ' for ' . $title . ' was refunded (' . self::money($o['total']) . '). Those tickets are no longer valid.'));
    $p = $ev ? self::organizer_of($ev) : null;
    if ($p) {
      $items = array();
      foreach ($o['items'] as $i) $items[] = $i['qty'] . ' × ' . self::h($i['tierName']);
      self::mail($p['email'], 'Order refunded — ' . $title, array('tag' => 'organizer.refund', 'heading' => 'An order was refunded', 'preheader' => $n . ' ticket(s) went back on sale.', 'body' => '<p>A TicketJunkies administrator refunded an order for <strong>' . self::h($title) . '</strong>. The ' . $n . ' ticket' . ($n > 1 ? 's are' : ' is') . ' back on sale.</p>' . self::detail_table(array(array('Buyer', self::h($o['buyerName']) . ' · ' . self::h($o['buyerEmail'])), array('Tickets', implode('<br>', $items)), array('Amount removed from your sales', self::money($o['subtotal'])))), 'cta' => array('label' => 'Open dashboard', 'url' => self::route('#/dashboard'))));
    }
  }

  /** Admin "send test" message. */
  public static function test_email($to) {
    return self::deliver_email(array('to' => $to, 'tag' => 'test', 'subject' => 'TicketJunkies test email', 'html' => self::email_layout(array('siteUrl' => self::site(), 'heading' => 'Your emails are working! ✅', 'bodyHtml' => '<p>This is a test email from your TicketJunkies Admin Panel. Notifications to administrators, organizers and ticket buyers will look like this.</p>', 'cta' => array('label' => 'Open Admin Panel', 'url' => self::route('#/admin')))), 'text' => 'Your TicketJunkies emails are working!'));
  }
  public static function test_sms($to) {
    return self::deliver_sms(array('to' => $to, 'tag' => 'test', 'body' => 'TicketJunkies: This is a test text message. Your SMS notifications are working! 🎟️'));
  }
}

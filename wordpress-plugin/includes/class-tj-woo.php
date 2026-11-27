<?php
if (!defined('ABSPATH')) exit;

/**
 * WooCommerce payments for TicketJunkies.
 *
 * - Every ticket type is kept in sync as a hidden, virtual WooCommerce product (category "Event tickets").
 * - Paid purchases go through the store's WooCommerce checkout, so the store's own payment gateway charges the card.
 * - Tickets are issued only when WooCommerce confirms the payment (Processing / Completed). Declined, failed,
 *   pending or on-hold payments never get tickets.
 * - Refunding or cancelling the WooCommerce order cancels the tickets; refunding in the TicketJunkies admin
 *   refunds the payment through WooCommerce.
 */
final class TJ_Woo {
  const MAP = 'tj_woo_products';      // tierId => WooCommerce product id
  const CAT = 'event-tickets';
  private static $adding = false;

  public static function active() {
    return class_exists('WooCommerce') && function_exists('WC') && function_exists('wc_get_product') && apply_filters('tj_use_woocommerce', true);
  }

  public static function init() {
    add_action('tj_event_saved', array(__CLASS__, 'sync_event'), 20);
    add_action('tj_event_deleted', array(__CLASS__, 'event_deleted'));
    add_action('admin_init', array(__CLASS__, 'maybe_sync_all'));
    add_action('admin_notices', array(__CLASS__, 'admin_notice'));
    // WooCommerce needs its own checkout page (the card form). Create or repair it automatically.
    add_action('wp_loaded', array(__CLASS__, 'ensure_checkout_page'), 20);
    // WooCommerce → Tools → "Create default pages" must never take over the TicketJunkies "checkout" page
    add_filter('woocommerce_create_page_id', array(__CLASS__, 'avoid_tj_pages'), 10, 3);
    // Elementor can open the checkout page to edit it even when the cart is empty
    add_filter('woocommerce_checkout_redirect_empty_cart', array(__CLASS__, 'editor_no_redirect'));
    // cart
    add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'block_direct_add'), 10, 2);
    add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'cart_prices'), 20);
    add_action('woocommerce_cart_calculate_fees', array(__CLASS__, 'cart_fees'));
    add_filter('woocommerce_cart_item_quantity', array(__CLASS__, 'fixed_quantity'), 10, 3);
    add_filter('woocommerce_store_api_product_quantity_editable', array(__CLASS__, 'quantity_editable'), 10, 3);
    add_filter('woocommerce_get_item_data', array(__CLASS__, 'item_data'), 10, 2);
    add_action('woocommerce_check_cart_items', array(__CLASS__, 'check_cart'));
    // order
    add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'line_item_meta'), 10, 4);
    add_action('woocommerce_payment_complete', array(__CLASS__, 'fulfil'));
    add_action('woocommerce_order_status_processing', array(__CLASS__, 'fulfil'));
    add_action('woocommerce_order_status_completed', array(__CLASS__, 'fulfil'));
    add_action('woocommerce_order_status_refunded', array(__CLASS__, 'order_cancelled'));
    add_action('woocommerce_order_status_cancelled', array(__CLASS__, 'order_cancelled'));
    add_action('woocommerce_order_refunded', array(__CLASS__, 'order_partially_refunded'), 10, 2);
    add_action('woocommerce_thankyou', array(__CLASS__, 'thankyou'), 5);
    add_filter('woocommerce_hidden_order_itemmeta', function ($keys) { $keys[] = '_tj'; return $keys; });
  }

  /** Front-end info: which payment system is used and which gateways are switched on. */
  public static function meta() {
    if (!self::active()) return array('provider' => 'none');
    $names = array();
    if (WC()->payment_gateways()) foreach (WC()->payment_gateways()->get_available_payment_gateways() as $g) $names[] = wp_strip_all_tags($g->get_title());
    return array('provider' => 'woocommerce', 'gateways' => array_values(array_unique($names)));
  }

  // =====================================================================
  // Products: one hidden virtual product per ticket type
  // =====================================================================
  private static function category_id() {
    $t = get_term_by('slug', self::CAT, 'product_cat');
    if ($t) return (int) $t->term_id;
    $r = wp_insert_term('Event tickets', 'product_cat', array('slug' => self::CAT, 'description' => 'TicketJunkies ticket types (kept in sync automatically).'));
    return is_wp_error($r) ? 0 : (int) $r['term_id'];
  }

  public static function product_id($tierId) {
    $map = get_option(self::MAP, array());
    $pid = is_array($map) && isset($map[$tierId]) ? (int) $map[$tierId] : 0;
    return $pid && get_post($pid) ? $pid : 0;
  }

  public static function sync_event($ev) {
    if (!self::active() || !is_array($ev) || empty($ev['tiers'])) return;
    $map = get_option(self::MAP, array()); if (!is_array($map)) $map = array();
    $changed = false; $cat = null;
    foreach ($ev['tiers'] as $t) {
      $kind = TJ_Rest::tier_kind($t);
      $sellable = $ev['status'] === 'published' && empty($t['cancelled']) && empty($t['deleted']);
      $sig = md5(wp_json_encode(array(3, $ev['title'], $ev['startsAt'], $ev['venue'], $ev['status'], $t['name'], (float) $t['price'], $kind, $sellable, isset($t['visibility']) ? $t['visibility'] : '')));
      $pid = isset($map[$t['id']]) ? (int) $map[$t['id']] : 0;
      $p = $pid ? wc_get_product($pid) : null;
      if ($p && $p->get_meta('_tj_sig') === $sig && $p->get_status() !== 'trash') continue;
      if (!$p || $p->get_status() === 'trash') $p = new WC_Product_Simple();
      if ($cat === null) $cat = self::category_id();
      $p->set_name($ev['title'] . ' — ' . $t['name']);
      $p->set_regular_price((string) ($kind === 'free' ? 0 : (float) $t['price']));
      $p->set_virtual(true);
      $p->set_catalog_visibility('hidden');
      $p->set_tax_status('none');
      $p->set_manage_stock(false);
      $p->set_stock_status('instock');
      $p->set_reviews_allowed(false);
      $p->set_status($sellable ? 'publish' : 'private');
      $p->set_short_description('');
      $p->set_description('TicketJunkies ticket: ' . $ev['title'] . ' — ' . $t['name'] . '. ' . TJ_Notify::when($ev['startsAt']) . ($ev['venue'] ? ' at ' . $ev['venue'] : '') . '. Sold through the TicketJunkies event page; kept in sync automatically.');
      if ($cat) $p->set_category_ids(array($cat));
      $p->update_meta_data('_tj_event_id', $ev['id']);
      $p->update_meta_data('_tj_tier_id', $t['id']);
      $p->update_meta_data('_tj_sig', $sig);
      $map[$t['id']] = (int) $p->save();
      $changed = true;
    }
    if ($changed) update_option(self::MAP, $map, false);
  }

  public static function event_deleted($id) {
    if (!self::active()) return;
    $map = get_option(self::MAP, array()); if (!is_array($map)) return;
    foreach ($map as $tierId => $pid) {
      $p = wc_get_product($pid);
      if ($p && $p->get_meta('_tj_event_id') === $id) { $p->delete(false); unset($map[$tierId]); }
    }
    update_option(self::MAP, $map, false);
  }

  /** After installing/updating the plugin (or turning on WooCommerce), create/refresh the products for every event. */
  public static function maybe_sync_all($force = false) {
    if (!self::active()) return;
    $ver = TJ_VERSION . '|' . count(TJ_Store::events());
    if (!$force && get_option('tj_woo_synced') === $ver) return;
    foreach (TJ_Store::events() as $ev) self::sync_event($ev);
    update_option('tj_woo_synced', $ver, false);
  }

  // =====================================================================
  // WooCommerce checkout page (where buyers type their card details)
  // =====================================================================
  /** True when $id is a published page that WooCommerce can use as its checkout (not a TicketJunkies screen page). */
  public static function checkout_page_ok($id) {
    $id = (int) $id;
    if ($id <= 0 || get_post_type($id) !== 'page' || get_post_status($id) !== 'publish') return false;
    if (get_post_meta($id, '_tj_page', true)) return false;
    $tj = get_option('tj_pages', array());
    return !in_array($id, array_map('intval', is_array($tj) ? $tj : array()), true);
  }

  /** Content of the WooCommerce checkout page: the classic checkout form, which works with every payment gateway. */
  const CHECKOUT_SHORTCODE = '[woocommerce_checkout]';

  /**
   * Makes sure WooCommerce has its own checkout page. Runs on every request but only does work when the page
   * is missing, unpublished, or pointing at a TicketJunkies page.
   */
  public static function ensure_checkout_page() {
    if (!self::active() || !function_exists('wc_get_page_id')) return;
    if (!apply_filters('tj_manage_checkout_page', true)) return;
    $cur = (int) get_option('woocommerce_checkout_page_id');
    if (self::checkout_page_ok($cur)) return;
    // Reuse a published page that already holds the checkout form (shortcode or block), if there is one
    $id = 0;
    $found = get_posts(array('post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 20, 'fields' => 'ids', 's' => 'woocommerce_checkout', 'suppress_filters' => true));
    foreach ($found as $pid) {
      $c = (string) get_post_field('post_content', $pid);
      if ((strpos($c, '[woocommerce_checkout') !== false || strpos($c, 'wp:woocommerce/checkout') !== false) && self::checkout_page_ok($pid)) { $id = (int) $pid; break; }
    }
    if (!$id) {
      $existing = get_page_by_path('secure-checkout', OBJECT, 'page');
      if ($existing && self::checkout_page_ok($existing->ID)) $id = (int) $existing->ID;
    }
    if (!$id) {
      $id = wp_insert_post(array(
        'post_title' => 'Secure Checkout', 'post_name' => 'secure-checkout', 'post_type' => 'page', 'post_status' => 'publish',
        'post_content' => '<!-- wp:shortcode -->' . self::CHECKOUT_SHORTCODE . '<!-- /wp:shortcode -->', 'comment_status' => 'closed',
      ));
      if (!$id || is_wp_error($id)) return;
      $id = (int) $id;
      self::elementor_checkout_layout($id);
    }
    update_option('woocommerce_checkout_page_id', $id);
    update_option('tj_checkout_page_fixed', time(), false);
  }

  /** Build the new checkout page with Elementor (when installed) so it can be designed like the other pages. */
  private static function elementor_checkout_layout($id) {
    if (!did_action('elementor/loaded') && !defined('ELEMENTOR_VERSION')) return;
    $eid = function () { return substr(md5(uniqid('', true)), 0, 7); };
    $px = function ($t, $r, $b, $l) { return array('unit' => 'px', 'top' => (string) $t, 'right' => (string) $r, 'bottom' => (string) $b, 'left' => (string) $l, 'isLinked' => false); };
    $data = array(array(
      'id' => $eid(), 'elType' => 'container', 'isInner' => false, 'elements' => array(
        array('id' => $eid(), 'elType' => 'widget', 'widgetType' => 'heading', 'elements' => array(), 'settings' => (object) array('title' => '🔒 Secure checkout', 'header_size' => 'h1')),
        array('id' => $eid(), 'elType' => 'widget', 'widgetType' => 'shortcode', 'elements' => array(), 'settings' => (object) array('shortcode' => self::CHECKOUT_SHORTCODE)),
      ),
      'settings' => (object) array('content_width' => 'boxed', 'boxed_width' => array('unit' => 'px', 'size' => 1100, 'sizes' => array()), 'padding' => $px(24, 20, 60, 20)),
    ));
    update_post_meta($id, '_elementor_edit_mode', 'builder');
    update_post_meta($id, '_elementor_template_type', 'wp-page');
    update_post_meta($id, '_elementor_version', defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0');
    update_post_meta($id, '_elementor_data', wp_slash(wp_json_encode($data)));
    update_post_meta($id, '_wp_page_template', 'elementor_header_footer');
  }

  /** WooCommerce "create pages": a TicketJunkies page with the same address is not a WooCommerce page. */
  public static function avoid_tj_pages($found, $slug = '', $content = '') {
    if ($found && !self::checkout_page_ok($found) && get_post_meta((int) $found, '_tj_page', true)) return 0;
    return $found;
  }

  /** Don't bounce the checkout page to the cart while Elementor (or the block editor preview) is showing it. */
  public static function editor_no_redirect($redirect) {
    if (isset($_GET['elementor-preview']) || isset($_GET['preview']) || (class_exists('TJ_Frontend') && TJ_Frontend::is_editor())) return false;
    return $redirect;
  }

  public static function admin_notice() {
    if (!current_user_can('manage_options')) return;
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || !in_array($screen->id, array('plugins', 'dashboard', 'woocommerce_page_wc-settings', 'toplevel_page_ticketjunkies'), true)) return;
    if (!self::active()) {
      echo '<div class="notice notice-warning"><p><strong>TicketJunkies:</strong> paid tickets need WooCommerce. Install and activate WooCommerce and turn on a payment gateway, otherwise paid tickets cannot be sold (free tickets still work).</p></div>';
      return;
    }
    $m = self::meta();
    if (empty($m['gateways'])) echo '<div class="notice notice-warning"><p><strong>TicketJunkies:</strong> WooCommerce has no payment method switched on, so buyers cannot pay for tickets. Turn one on in <a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')) . '">WooCommerce → Settings → Payments</a>.</p></div>';
    $cid = (int) get_option('woocommerce_checkout_page_id');
    if (!self::checkout_page_ok($cid)) {
      echo '<div class="notice notice-error"><p><strong>TicketJunkies:</strong> WooCommerce has no checkout page, so buyers cannot enter their card details. Reload this page and TicketJunkies will create one ("Secure Checkout").</p></div>';
    } elseif ((int) get_option('tj_checkout_page_fixed') > time() - 7 * DAY_IN_SECONDS) {
      echo '<div class="notice notice-success is-dismissible"><p><strong>TicketJunkies:</strong> buyers now pay on the WooCommerce page <a href="' . esc_url(get_permalink($cid)) . '" target="_blank">' . esc_html(get_the_title($cid)) . '</a> (the card form). Edit its design with <a href="' . esc_url(admin_url('post.php?post=' . $cid . '&action=elementor')) . '">Elementor</a>. The TicketJunkies "Checkout" page is a different page: it shows the order summary before payment.</p></div>';
    }
  }

  // =====================================================================
  // Cart
  // =====================================================================
  /** Put the validated ticket groups in the WooCommerce cart and return the checkout URL. */
  public static function fill_cart($user, $groups) {
    if (function_exists('wc_load_cart') && (!WC()->cart || !WC()->session)) wc_load_cart();
    if (!WC()->cart) throw new Exception('the store cart is unavailable');
    if (WC()->session && method_exists(WC()->session, 'set_customer_session_cookie')) WC()->session->set_customer_session_cookie(true);
    foreach (WC()->cart->get_cart() as $key => $item) if (isset($item['tj']) || self::is_ticket_product($item)) WC()->cart->remove_cart_item($key);
    self::$adding = true;
    try {
      foreach ($groups as $g) {
        $ev = $g['ev']; $body = $g['body'];
        self::sync_event($ev);
        $att = is_array(self::g($body, 'attendees')) ? array_values($body['attendees']) : array();
        $slot = 0;
        foreach ((array) self::g($body, 'items') as $it) {
          $qty = (int) self::g($it, 'qty', 0);
          if ($qty < 1) continue;
          $tier = null;
          foreach ($ev['tiers'] as $t) if ($t['id'] === self::g($it, 'tierId')) $tier = $t;
          if (!$tier) continue;
          $kind = TJ_Rest::tier_kind($tier);
          $unit = $kind === 'free' ? 0 : ($kind === 'donation' ? round((float) self::g($it, 'amount', $tier['price']), 2) : (float) $tier['price']);
          $pid = self::product_id($tier['id']);
          if (!$pid) throw new Exception('ticket "' . $tier['name'] . '" is not set up in the store');
          $data = array('tj' => array(
            'eventId' => $ev['id'], 'tierId' => $tier['id'], 'unit' => $unit, 'donation' => $kind === 'donation',
            'holders' => array_slice($att, $slot, $qty), 'userId' => (int) $user['id'],
            'buyerFirstName' => (string) self::g($body, 'buyerFirstName', ''), 'buyerLastName' => (string) self::g($body, 'buyerLastName', ''),
            'buyerEmail' => (string) self::g($body, 'buyerEmail', ''), 'buyerPhone' => (string) self::g($body, 'buyerPhone', ''),
          ));
          $slot += $qty;
          $key = WC()->cart->add_to_cart($pid, $qty, 0, array(), $data);
          if (!$key) throw new Exception('ticket "' . $tier['name'] . '" could not be added to the cart');
        }
      }
    } finally {
      self::$adding = false;
    }
    if (method_exists(WC()->cart, 'calculate_totals')) WC()->cart->calculate_totals();
    if (WC()->cart && method_exists(WC()->cart, 'set_session')) WC()->cart->set_session();
    if (WC()->cart && method_exists(WC()->cart, 'maybe_set_cart_cookies')) WC()->cart->maybe_set_cart_cookies();
    if (WC()->session && method_exists(WC()->session, 'save_data')) WC()->session->save_data();
    // Prefill the store checkout with the buyer's details
    if (WC()->customer) {
      $b = $groups[0]['body'];
      if (!WC()->customer->get_billing_first_name()) WC()->customer->set_billing_first_name((string) self::g($b, 'buyerFirstName', ''));
      if (!WC()->customer->get_billing_last_name()) WC()->customer->set_billing_last_name((string) self::g($b, 'buyerLastName', ''));
      if (!WC()->customer->get_billing_email()) WC()->customer->set_billing_email((string) self::g($b, 'buyerEmail', ''));
      if (!WC()->customer->get_billing_phone()) WC()->customer->set_billing_phone((string) self::g($b, 'buyerPhone', ''));
      WC()->customer->save();
    }
    self::ensure_checkout_page();
    return wc_get_checkout_url();
  }

  /** Ticket products can only be bought through the TicketJunkies checkout (it collects the ticket details). */
  public static function block_direct_add($ok, $product_id) {
    if (self::$adding) return $ok;
    if (get_post_meta($product_id, '_tj_tier_id', true)) {
      wc_add_notice('Please buy event tickets from the event page.', 'error');
      return false;
    }
    return $ok;
  }

  public static function cart_prices($cart) {
    if (!is_object($cart) || !method_exists($cart, 'get_cart')) return;
    foreach ($cart->get_cart() as $item) if (isset($item['tj']) && isset($item['data']) && is_object($item['data'])) $item['data']->set_price((float) $item['tj']['unit']);
  }

  /** TicketJunkies service fee (same as shown on the event page). */
  public static function cart_fees($cart) {
    if (!is_object($cart) || !method_exists($cart, 'get_cart')) return;
    $fees = 0;
    foreach ($cart->get_cart() as $item) if (isset($item['tj'])) $fees += TJ_Rest::ticket_fee((float) $item['tj']['unit']) * (int) $item['quantity'];
    if ($fees > 0) $cart->add_fee('Ticket service fee', round($fees, 2), false);
  }

  public static function fixed_quantity($html, $key, $item) {
    return isset($item['tj']) ? sprintf('%d <input type="hidden" name="cart[%s][qty]" value="%d" />', (int) $item['quantity'], esc_attr($key), (int) $item['quantity']) : $html;
  }
  public static function quantity_editable($editable, $product = null, $item = null) {
    return is_array($item) && isset($item['tj']) ? false : $editable;
  }

  public static function item_data($data, $item) {
    if (!isset($item['tj'])) return $data;
    $ev = TJ_Store::get_event($item['tj']['eventId']);
    if ($ev) $data[] = array('key' => 'When', 'value' => TJ_Notify::when($ev['startsAt']));
    if ($ev && $ev['venue']) $data[] = array('key' => 'Where', 'value' => $ev['venue']);
    if (!empty($item['tj']['donation'])) $data[] = array('key' => 'Donation', 'value' => TJ_Notify::money($item['tj']['unit']) . ' each');
    return $data;
  }

  private static function is_ticket_product($item) {
    $pid = is_array($item) && isset($item['product_id']) ? (int) $item['product_id'] : 0;
    return $pid && get_post_meta($pid, '_tj_tier_id', true);
  }

  /** Before payment: make sure every ticket is still available (sold out, sales ended, limits…). */
  public static function check_cart() {
    if (!WC()->cart) return;
    // A ticket product without ticket details (added from the shop) would be paid for but never issued: remove it.
    foreach (WC()->cart->get_cart() as $key => $item) {
      if (!isset($item['tj']) && self::is_ticket_product($item)) {
        WC()->cart->remove_cart_item($key);
        wc_add_notice('Event tickets must be chosen on the event page, so one item was removed from your cart.', 'error');
      }
    }
    foreach (self::groups_from(WC()->cart->get_cart(), function ($i) { return (int) $i['quantity']; }) as $eventId => $g) {
      $user = TJ_Store::user_by_id($g['userId']);
      $ev = TJ_Store::get_event($eventId);
      if (!$user || !$ev) { wc_add_notice('Some tickets in your cart are no longer available. Please remove them and pick your tickets again on the event page.', 'error'); continue; }
      try { TJ_Rest::woo_check($user, $eventId, $g['body']); }
      catch (TJ_Fail $e) { wc_add_notice($ev['title'] . ': ' . $e->getMessage(), 'error'); }
    }
  }

  /** Group TicketJunkies lines (cart items or order items) by event into checkout bodies. */
  private static function groups_from($items, $qtyOf) {
    $out = array();
    foreach ($items as $item) {
      $tj = is_array($item) ? (isset($item['tj']) ? $item['tj'] : null) : $item->get_meta('_tj');
      if (!is_array($tj) || empty($tj['eventId'])) continue;
      $q = (int) $qtyOf($item);
      if ($q < 1) continue;
      $id = $tj['eventId'];
      if (!isset($out[$id])) $out[$id] = array('userId' => (int) $tj['userId'], 'body' => array(
        'buyerFirstName' => $tj['buyerFirstName'], 'buyerLastName' => $tj['buyerLastName'], 'buyerEmail' => $tj['buyerEmail'], 'buyerPhone' => $tj['buyerPhone'],
        'items' => array(), 'attendees' => array()));
      $line = array('tierId' => $tj['tierId'], 'qty' => $q);
      if (!empty($tj['donation'])) $line['amount'] = (float) $tj['unit'];
      $out[$id]['body']['items'][] = $line;
      $h = is_array($tj['holders']) ? array_values($tj['holders']) : array();
      for ($k = 0; $k < $q; $k++) $out[$id]['body']['attendees'][] = isset($h[$k]) && is_array($h[$k]) ? $h[$k] : array();
    }
    return $out;
  }

  // =====================================================================
  // Order
  // =====================================================================
  public static function line_item_meta($item, $key, $values, $order) {
    if (!isset($values['tj'])) return;
    $item->add_meta_data('_tj', $values['tj'], true);
    $ev = TJ_Store::get_event($values['tj']['eventId']);
    if ($ev) {
      $item->add_meta_data('Event date', TJ_Notify::when($ev['startsAt']), true);
      if ($ev['venue']) $item->add_meta_data('Venue', $ev['venue'], true);
    }
  }

  private static function has_tickets($order) {
    foreach ($order->get_items() as $it) if (is_array($it->get_meta('_tj'))) return true;
    return false;
  }

  /** Payment confirmed by the gateway → issue the tickets (exactly once per WooCommerce order). */
  public static function fulfil($order_id) {
    if (!self::active()) return;
    $order = wc_get_order($order_id);
    if (!$order || !self::has_tickets($order)) return;
    if (!$order->is_paid() && !in_array($order->get_status(), array('processing', 'completed'), true)) return;
    $lock = TJ_Store::lock('woo_' . $order->get_id());
    try {
      $order = wc_get_order($order_id);
      if ($order->get_meta('_tj_issued')) return;
      $done = array(); $codes = array();
      $pay = array('cardBrand' => wp_strip_all_tags((string) $order->get_payment_method_title()), 'cardLast4' => '',
        'cardHolder' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()), 'cardFingerprint' => '',
        'wcOrderId' => (int) $order->get_id(), 'paymentMethod' => (string) $order->get_payment_method(), 'transactionId' => (string) $order->get_transaction_id());
      $groups = self::groups_from($order->get_items(), function ($i) { return (int) $i->get_quantity(); });
      foreach ($groups as $eventId => $g) {
        $user = TJ_Store::user_by_id($g['userId'] ? $g['userId'] : $order->get_customer_id());
        if (!$user) { $order->add_order_note('TicketJunkies: could not issue tickets for event ' . $eventId . ' (buyer account not found). Please issue them manually.'); continue; }
        try {
          $r = TJ_Rest::woo_issue($user, $eventId, $g['body'], $pay, 'wc' . $order->get_id());
          $done[] = $r;
          foreach ($r['order']['tickets'] as $t) $codes[] = $t['code'];
        } catch (TJ_Fail $e) {
          $order->add_order_note('TicketJunkies: tickets for event ' . $eventId . ' could not be issued: ' . $e->getMessage());
        }
      }
      $ids = array_map(function ($d) { return $d['order']['id']; }, $done);
      $order->update_meta_data('_tj_issued', $ids ? 'yes' : 'failed');
      $order->update_meta_data('_tj_orders', $ids);
      if ($ids) $order->add_order_note('TicketJunkies tickets issued (' . count($codes) . '): ' . implode(', ', $codes));
      $order->save();
    } finally {
      TJ_Store::unlock($lock);
    }
    foreach ($done as $d) TJ_Notify::order_placed($d['order'], $d['ev']);
  }

  /** WooCommerce order refunded or cancelled → cancel its tickets. */
  public static function order_cancelled($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return;
    foreach ((array) $order->get_meta('_tj_orders') as $tid) {
      $o = TJ_Store::get_order($tid);
      if ($o && $o['status'] !== 'refunded') TJ_Rest::mark_refunded($o);
    }
  }
  /** A partial refund made inside WooCommerce: note it (tickets stay valid unless the whole order is refunded). */
  public static function order_partially_refunded($order_id, $refund_id) {
    $order = wc_get_order($order_id);
    if ($order && $order->get_meta('_tj_orders') && $order->get_status() !== 'refunded') $order->add_order_note('TicketJunkies: partial refund recorded. Tickets stay valid; refund individual ticket orders from the TicketJunkies Admin Panel → Orders to cancel them.');
  }

  /** Refund one TicketJunkies order's payment through the WooCommerce gateway. Returns an error message or ''. */
  public static function refund_payment($o) {
    $order = wc_get_order((int) $o['wcOrderId']);
    if (!$order) return 'The WooCommerce order for this purchase was not found.';
    $left = (float) $order->get_total() - (float) $order->get_total_refunded();
    $amount = round(min((float) $o['total'], $left), 2);
    if ($amount <= 0) return '';
    $gw = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
    $m = $order->get_payment_method();
    $auto = isset($gw[$m]) && $gw[$m]->supports('refunds');
    $r = wc_create_refund(array('order_id' => $order->get_id(), 'amount' => $amount, 'reason' => 'TicketJunkies refund: order ' . $o['id'], 'refund_payment' => $auto));
    if (is_wp_error($r)) return 'The payment refund failed: ' . $r->get_error_message();
    if (!$auto) $order->add_order_note('TicketJunkies: ' . TJ_Notify::money($amount) . ' marked as refunded. Your payment method (' . $order->get_payment_method_title() . ') does not refund automatically — send the money back to the buyer yourself.');
    return '';
  }

  public static function thankyou($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || !self::has_tickets($order)) return;
    $url = function_exists('tj_route_url') ? tj_route_url('#/tickets') : home_url('/');
    $ok = $order->get_meta('_tj_issued') === 'yes';
    // paid: empty the TicketJunkies cart in this browser (it is kept until payment succeeds, so a declined card can try again)
    echo '<script>try{localStorage.removeItem("tj_basket");sessionStorage.removeItem("tj_cart")}catch(e){}</script>';
    echo '<section class="tj-woo-thanks" style="margin:0 0 24px;padding:18px 20px;border-radius:14px;background:#eef9e8;border:1px solid #84d65f;color:#12350a">';
    echo $ok
      ? '<h2 style="margin:0 0 6px">🎉 You\'re going!</h2><p style="margin:0 0 12px">Your QR tickets are on their way by email and text, and they\'re saved in your TicketJunkies dashboard.</p><a class="button" href="' . esc_url($url) . '">🎟️ View my tickets</a>'
      : '<h2 style="margin:0 0 6px">Thanks for your order</h2><p style="margin:0">Your tickets will be emailed as soon as your payment is confirmed. They will also appear in <a href="' . esc_url($url) . '">your TicketJunkies dashboard</a>.</p>';
    echo '</section>';
  }

  private static function g($a, $k, $d = null) { return is_array($a) && array_key_exists($k, $a) ? $a[$k] : $d; }
}

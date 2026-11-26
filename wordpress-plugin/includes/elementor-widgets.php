<?php if (!defined('ABSPATH')) exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Box_Shadow;

/** Shared base: style controls that re-color / re-type any TicketJunkies widget */
abstract class TJ_Widget_Base extends \Elementor\Widget_Base {
  protected $tj_type = 'grid';
  public function get_categories() { return ['ticketjunkies']; }
  public function get_keywords() { return ['ticket', 'event', 'ticketjunkies']; }
  public function get_style_depends() { return ['tj']; }
  public function get_script_depends() { return ['tj-app']; }
  protected function is_dynamic_content(): bool { return true; }

  /** Content-tab controls for the widget (override) */
  protected function tj_content_controls() {}

  /** Options passed to the front-end widget (override) */
  protected function tj_opts(array $s) { return []; }

  protected function register_controls() {
    $this->start_controls_section('tj_content', ['label' => 'Content', 'tab' => Controls_Manager::TAB_CONTENT]);
    $this->tj_content_controls();
    $this->add_control('tj_help', ['type' => Controls_Manager::RAW_HTML, 'raw' => '<small>Live TicketJunkies content. Colors follow <b>Site Settings → Global Colors</b> (Primary = blue, Secondary = green, Accent = orange) unless you override them in the Style tab.</small>', 'content_classes' => 'elementor-descriptor']);
    $this->end_controls_section();

    $this->start_controls_section('tj_colors', ['label' => 'Colors', 'tab' => Controls_Manager::TAB_STYLE]);
    $vars = [
      'blue' => ['Primary / links (blue)', '--blue'], 'orange' => ['Main buttons (orange)', '--orange'], 'green' => ['Success (green)', '--green'],
      'ink' => ['Text', '--ink'], 'muted' => ['Secondary text', '--muted'], 'card' => ['Card / panel background', '--card'],
      'line' => ['Borders & lines', '--line'], 'bg' => ['Soft background', '--bg'],
    ];
    foreach ($vars as $k => $v) {
      $this->add_control('tj_c_' . $k, ['label' => $v[0], 'type' => Controls_Manager::COLOR, 'selectors' => ['{{WRAPPER}} .tj-root' => $v[1] . ': {{VALUE}};']]);
    }
    $this->end_controls_section();

    $this->start_controls_section('tj_type', ['label' => 'Typography', 'tab' => Controls_Manager::TAB_STYLE]);
    $this->add_group_control(Group_Control_Typography::get_type(), ['name' => 'tj_body', 'label' => 'Body text', 'selector' => '{{WRAPPER}} .tj-root']);
    $this->add_group_control(Group_Control_Typography::get_type(), ['name' => 'tj_heads', 'label' => 'Headings', 'selector' => '{{WRAPPER}} .tj-root h1, {{WRAPPER}} .tj-root h2, {{WRAPPER}} .tj-root h3']);
    $this->add_control('tj_head_color', ['label' => 'Heading color', 'type' => Controls_Manager::COLOR, 'selectors' => ['{{WRAPPER}} .tj-root h1, {{WRAPPER}} .tj-root h2, {{WRAPPER}} .tj-root h3' => 'color: {{VALUE}};']]);
    $this->end_controls_section();

    $this->start_controls_section('tj_shape', ['label' => 'Buttons & cards', 'tab' => Controls_Manager::TAB_STYLE]);
    $this->add_group_control(Group_Control_Typography::get_type(), ['name' => 'tj_btn', 'label' => 'Button text', 'selector' => '{{WRAPPER}} .tj-root .btn']);
    $this->add_responsive_control('tj_btn_radius', ['label' => 'Button corners', 'type' => Controls_Manager::SLIDER, 'size_units' => ['px'], 'range' => ['px' => ['min' => 0, 'max' => 40]], 'selectors' => ['{{WRAPPER}} .tj-root .btn' => 'border-radius: {{SIZE}}{{UNIT}};']]);
    $this->add_responsive_control('tj_radius', ['label' => 'Card corners', 'type' => Controls_Manager::SLIDER, 'size_units' => ['px'], 'range' => ['px' => ['min' => 0, 'max' => 40]], 'selectors' => ['{{WRAPPER}} .tj-root' => '--radius: {{SIZE}}{{UNIT}};']]);
    $this->add_group_control(Group_Control_Box_Shadow::get_type(), ['name' => 'tj_card_shadow', 'label' => 'Card shadow', 'selector' => '{{WRAPPER}} .tj-root .card, {{WRAPPER}} .tj-root .panel, {{WRAPPER}} .tj-root .stat, {{WRAPPER}} .tj-root .table-wrap']);
    $this->end_controls_section();
    $this->tj_extra_style_controls();
  }
  protected function tj_extra_style_controls() {}

  protected function render() {
    echo TJ_Frontend::widget_html($this->tj_type, $this->tj_opts($this->get_settings_for_display())); // phpcs:ignore
  }
}

/** Full "screen" widgets: event page, checkout, dashboards, admin … */
abstract class TJ_Screen_Widget extends TJ_Widget_Base {
  protected $tj_type = 'screen';
  protected $tj_screen = 'event';
  protected $tj_desc = '';
  public function get_icon() { return 'eicon-site-identity'; }
  protected function tj_content_controls() {
    $this->add_control('tj_info', ['type' => Controls_Manager::RAW_HTML, 'raw' => '<div style="line-height:1.5">' . esc_html($this->tj_desc) . '<br><br><small>Only one of these "screen" widgets can be placed on a page. Add any Elementor widgets (headings, images, text, banners) around it.</small></div>']);
  }
  protected function tj_opts(array $s) { return ['screen' => $this->tj_screen]; }
}
class TJ_W_Event extends TJ_Screen_Widget { protected $tj_screen = 'event'; protected $tj_desc = 'Event details: flyer, date, venue map, weather forecast, share buttons and the ticket picker with "Continue to checkout".';
  public function get_name() { return 'tj_event'; } public function get_title() { return 'TJ Event Details & Tickets'; } public function get_icon() { return 'eicon-calendar'; } }
class TJ_W_Checkout extends TJ_Screen_Widget { protected $tj_screen = 'checkout'; protected $tj_desc = 'Checkout page: attendee first & last name, contact details, payment, robot check and order summary. Visitors must create an account or log in first. Also shows the "You\'re going!" confirmation.';
  public function get_name() { return 'tj_checkout'; } public function get_title() { return 'TJ Checkout'; } public function get_icon() { return 'eicon-cart'; } }
class TJ_W_Tickets extends TJ_Screen_Widget { protected $tj_screen = 'tickets'; protected $tj_desc = 'Attendee dashboard: upcoming & past tickets with QR codes, PDF download, resend by email/text and purchase history.';
  public function get_name() { return 'tj_tickets'; } public function get_title() { return 'TJ My Ticket Dashboard'; } public function get_icon() { return 'eicon-person'; } }
class TJ_W_Organizer extends TJ_Screen_Widget { protected $tj_screen = 'organizer'; protected $tj_desc = 'Organizer area: my events, create/edit events and ticket types, attendees, complimentary tickets, door check-in with QR scanner and sales reports.';
  public function get_name() { return 'tj_organizer'; } public function get_title() { return 'TJ Organizer Dashboard'; } public function get_icon() { return 'eicon-dashboard'; } }
class TJ_W_Admin extends TJ_Screen_Widget { protected $tj_screen = 'admin'; protected $tj_desc = 'Site admin panel: overview, users, events, orders & refunds, reports, emails & texts, sandbox test sales and settings. Only administrators can open it.';
  public function get_name() { return 'tj_admin'; } public function get_title() { return 'TJ Admin Panel'; } public function get_icon() { return 'eicon-lock-user'; } }
class TJ_W_Login extends TJ_Screen_Widget { protected $tj_screen = 'login'; protected $tj_desc = 'Log in form for attendees, organizers and admins.';
  public function get_name() { return 'tj_login'; } public function get_title() { return 'TJ Log In Form'; } public function get_icon() { return 'eicon-lock'; } }
class TJ_W_Register extends TJ_Screen_Widget { protected $tj_screen = 'register'; protected $tj_desc = 'Create-account form (attendee or organizer) with robot check.';
  public function get_name() { return 'tj_register'; } public function get_title() { return 'TJ Create Account Form'; } public function get_icon() { return 'eicon-person'; } }
class TJ_W_Ticket extends TJ_Screen_Widget { protected $tj_screen = 'ticket'; protected $tj_desc = 'Ticket viewer opened from the links in ticket emails and texts (big QR codes + PDF download).';
  public function get_name() { return 'tj_ticket'; } public function get_title() { return 'TJ Ticket Viewer'; } public function get_icon() { return 'eicon-barcode'; } }
class TJ_W_Scanner extends TJ_Screen_Widget { protected $tj_screen = 'scanner'; protected $tj_desc = 'Door Scanner for organizers: pick the event, scan ticket QR codes with the phone camera, see who is checked in and who is not, search attendees and keep a typed-in guest list. Only organizers and admins can use it.';
  public function get_name() { return 'tj_scanner'; } public function get_title() { return 'TJ Door Scanner'; } public function get_icon() { return 'eicon-camera'; } }
class TJ_W_Home extends TJ_Screen_Widget { protected $tj_screen = 'home'; protected $tj_desc = 'The complete original homepage in one block (hero, search, weather & clock, categories, map, events). Prefer the separate widgets for full Elementor control.';
  public function get_name() { return 'tj_home'; } public function get_title() { return 'TJ Complete Homepage'; } public function get_icon() { return 'eicon-home'; } }

/** Homepage building blocks */
class TJ_W_Search extends TJ_Widget_Base { protected $tj_type = 'search';
  public function get_name() { return 'tj_search'; } public function get_title() { return 'TJ Event Search Bar'; } public function get_icon() { return 'eicon-search'; }
  protected function tj_content_controls() {
    $this->add_control('placeholder', ['label' => 'Placeholder', 'type' => Controls_Manager::TEXT, 'default' => 'Search events, venues, organizers…']);
    $this->add_control('button', ['label' => 'Button text', 'type' => Controls_Manager::TEXT, 'default' => 'Search']);
    $this->add_control('show_category', ['label' => 'Category dropdown', 'type' => Controls_Manager::SWITCHER, 'default' => 'yes']);
  }
  protected function tj_extra_style_controls() {
    $this->start_controls_section('tj_box', ['label' => 'Search box', 'tab' => Controls_Manager::TAB_STYLE]);
    $this->add_control('box_bg', ['label' => 'Box background', 'type' => Controls_Manager::COLOR, 'selectors' => ['{{WRAPPER}} .search' => 'background: {{VALUE}};']]);
    $this->add_responsive_control('box_radius', ['label' => 'Box corners', 'type' => Controls_Manager::SLIDER, 'range' => ['px' => ['min' => 0, 'max' => 40]], 'selectors' => ['{{WRAPPER}} .search' => 'border-radius: {{SIZE}}px;']]);
    $this->end_controls_section();
  }
  protected function tj_opts(array $s) { return ['placeholder' => $s['placeholder'] ?? '', 'button' => $s['button'] ?? '', 'showCategory' => ($s['show_category'] ?? 'yes') === 'yes']; }
}
class TJ_W_Chips extends TJ_Widget_Base { protected $tj_type = 'chips';
  public function get_name() { return 'tj_chips'; } public function get_title() { return 'TJ Category Buttons'; } public function get_icon() { return 'eicon-tags'; }
  protected function tj_content_controls() { $this->add_control('all_label', ['label' => '"All" button text', 'type' => Controls_Manager::TEXT, 'default' => 'All']); }
  protected function tj_opts(array $s) { return ['allLabel' => $s['all_label'] ?? 'All']; }
}
abstract class TJ_Listing_Widget extends TJ_Widget_Base {
  protected function listing_controls($title, $filtered) {
    $this->add_control('show_title', ['label' => 'Show heading', 'type' => Controls_Manager::SWITCHER, 'default' => 'yes']);
    $this->add_control('title', ['label' => 'Heading', 'type' => Controls_Manager::TEXT, 'default' => $title, 'condition' => ['show_title' => 'yes']]);
    $this->add_control('title_filtered', ['label' => 'Heading when searching', 'type' => Controls_Manager::TEXT, 'default' => $filtered, 'condition' => ['show_title' => 'yes']]);
    $cats = ['' => 'Follow the search / category buttons'];
    foreach (['Music', 'Nightlife', 'Comedy', 'Food & Drink', 'Sports', 'Arts', 'Business', 'Community'] as $c) $cats[$c] = 'Only ' . $c;
    $this->add_control('category', ['label' => 'Events shown', 'type' => Controls_Manager::SELECT, 'options' => $cats, 'default' => '']);
  }
}
class TJ_W_Grid extends TJ_Listing_Widget { protected $tj_type = 'grid';
  public function get_name() { return 'tj_grid'; } public function get_title() { return 'TJ Events Grid'; } public function get_icon() { return 'eicon-posts-grid'; }
  protected function tj_content_controls() {
    $this->listing_controls('Upcoming events', 'Search results');
    $this->add_control('show_count', ['label' => 'Show event count', 'type' => Controls_Manager::SWITCHER, 'default' => 'yes']);
    $this->add_control('limit', ['label' => 'Max events (0 = all)', 'type' => Controls_Manager::NUMBER, 'default' => 0, 'min' => 0]);
    $this->add_control('columns', ['label' => 'Columns (0 = automatic)', 'type' => Controls_Manager::NUMBER, 'default' => 0, 'min' => 0, 'max' => 6]);
    $this->add_control('empty_title', ['label' => 'No events – title', 'type' => Controls_Manager::TEXT, 'default' => 'No events found']);
    $this->add_control('empty_text', ['label' => 'No events – text', 'type' => Controls_Manager::TEXT, 'default' => 'Try another search or category.']);
  }
  protected function tj_extra_style_controls() {
    $this->start_controls_section('tj_grid_gap', ['label' => 'Grid', 'tab' => Controls_Manager::TAB_STYLE]);
    $this->add_responsive_control('gap', ['label' => 'Space between cards', 'type' => Controls_Manager::SLIDER, 'range' => ['px' => ['min' => 0, 'max' => 60]], 'selectors' => ['{{WRAPPER}} .grid' => 'gap: {{SIZE}}px;']]);
    $this->add_group_control(Group_Control_Typography::get_type(), ['name' => 'card_title', 'label' => 'Card title', 'selector' => '{{WRAPPER}} .card-body h3']);
    $this->add_control('date_color', ['label' => 'Date color', 'type' => Controls_Manager::COLOR, 'selectors' => ['{{WRAPPER}} .card-date' => 'color: {{VALUE}};']]);
    $this->end_controls_section();
  }
  protected function tj_opts(array $s) {
    return ['showTitle' => ($s['show_title'] ?? 'yes') === 'yes', 'title' => $s['title'] ?? '', 'titleFiltered' => $s['title_filtered'] ?? '', 'category' => $s['category'] ?? '',
      'showCount' => ($s['show_count'] ?? 'yes') === 'yes', 'limit' => (int) ($s['limit'] ?? 0), 'columns' => (int) ($s['columns'] ?? 0), 'emptyTitle' => $s['empty_title'] ?? '', 'emptyText' => $s['empty_text'] ?? ''];
  }
}
class TJ_W_Map extends TJ_Listing_Widget { protected $tj_type = 'map';
  public function get_name() { return 'tj_map'; } public function get_title() { return 'TJ Events Map'; } public function get_icon() { return 'eicon-google-maps'; }
  protected function tj_content_controls() {
    $this->listing_controls('🗺️ Live events on the map', '🗺️ These events on the map');
    $this->add_control('height', ['label' => 'Map height (px, 0 = default)', 'type' => Controls_Manager::NUMBER, 'default' => 0, 'min' => 0]);
  }
  protected function tj_opts(array $s) { return ['showTitle' => ($s['show_title'] ?? 'yes') === 'yes', 'title' => $s['title'] ?? '', 'titleFiltered' => $s['title_filtered'] ?? '', 'category' => $s['category'] ?? '', 'height' => (int) ($s['height'] ?? 0)]; }
}
class TJ_W_Weather extends TJ_Widget_Base { protected $tj_type = 'weather';
  public function get_name() { return 'tj_weather'; } public function get_title() { return 'TJ Clock & Weather Bar'; } public function get_icon() { return 'eicon-clock-o'; }
  protected function tj_content_controls() {
    $this->add_control('show_weather', ['label' => 'Weather forecast', 'type' => Controls_Manager::SWITCHER, 'default' => 'yes']);
    $this->add_control('show_clock', ['label' => 'Clock & date (visitor\'s time zone)', 'type' => Controls_Manager::SWITCHER, 'default' => 'yes']);
    $this->add_control('bar_note', ['type' => Controls_Manager::RAW_HTML, 'raw' => '<small>Shows as one slim bar. Place it above the hero box.</small>']);
    $this->add_control('place_note', ['type' => Controls_Manager::RAW_HTML, 'raw' => '<small>The weather location is set in the Admin Panel → Settings → Maps & weather.</small>']);
  }
  protected function tj_extra_style_controls() {
    $this->start_controls_section('tj_glass', ['label' => 'Bar', 'tab' => Controls_Manager::TAB_STYLE]);
    $this->add_control('glass_bg', ['label' => 'Bar background', 'type' => Controls_Manager::COLOR, 'selectors' => ['{{WRAPPER}} .info-bar' => 'background: linear-gradient({{VALUE}}, {{VALUE}}) padding-box, linear-gradient(90deg, #eb742a, #376eb8) border-box;']]);
    $this->add_control('glass_text', ['label' => 'Bar text', 'type' => Controls_Manager::COLOR, 'selectors' => ['{{WRAPPER}} .info-bar, {{WRAPPER}} .info-bar .hero-clock, {{WRAPPER}} .info-bar .wx-mini' => 'color: {{VALUE}};']]);
    $this->end_controls_section();
  }
  protected function tj_opts(array $s) { return ['showWeather' => ($s['show_weather'] ?? 'yes') === 'yes', 'showClock' => ($s['show_clock'] ?? 'yes') === 'yes']; }
}
class TJ_W_Hero extends TJ_Widget_Base { protected $tj_type = 'hero';
  public function get_name() { return 'tj_hero'; } public function get_title() { return 'TJ Hero Box ("Get your ticket fix.")'; } public function get_icon() { return 'eicon-banner'; }
  protected function tj_content_controls() {
    $this->add_control('title', ['label' => 'Headline', 'type' => Controls_Manager::TEXT, 'default' => 'Get your ticket fix.']);
    $this->add_control('subtitle', ['label' => 'Text', 'type' => Controls_Manager::TEXTAREA, 'default' => 'Discover the hottest parties, concerts, comedy shows and festivals — or launch your own event and start selling tickets in minutes.']);
    $this->add_control('show_weather', ['label' => 'Weather forecast', 'type' => Controls_Manager::SWITCHER, 'default' => 'yes']);
    $this->add_control('show_clock', ['label' => 'Live clock', 'type' => Controls_Manager::SWITCHER, 'default' => 'yes']);
    $this->add_control('show_cta', ['label' => '"Become an organizer" link', 'type' => Controls_Manager::SWITCHER, 'default' => 'yes']);
  }
  protected function tj_extra_style_controls() {
    $this->start_controls_section('tj_hero_bg', ['label' => 'Box', 'tab' => Controls_Manager::TAB_STYLE]);
    $this->add_control('hero_bg', ['label' => 'Background (leave empty for orange & blue)', 'type' => Controls_Manager::COLOR, 'selectors' => ['{{WRAPPER}} .hero.tj-hero' => 'background: {{VALUE}};']]);
    $this->add_control('hero_text', ['label' => 'Text color', 'type' => Controls_Manager::COLOR, 'selectors' => ['{{WRAPPER}} .hero.tj-hero, {{WRAPPER}} .hero.tj-hero h1, {{WRAPPER}} .hero.tj-hero p, {{WRAPPER}} .hero-cta a.link' => 'color: {{VALUE}};']]);
    $this->end_controls_section();
  }
  protected function tj_opts(array $s) { return ['title' => $s['title'] ?? '', 'subtitle' => $s['subtitle'] ?? null, 'showWeather' => ($s['show_weather'] ?? 'yes') === 'yes', 'showClock' => ($s['show_clock'] ?? 'yes') === 'yes', 'showCta' => ($s['show_cta'] ?? 'yes') === 'yes']; }
}
class TJ_W_Calendar extends TJ_Widget_Base { protected $tj_type = 'calendar';
  public function get_name() { return 'tj_calendar'; } public function get_title() { return 'TJ Calendar of Events'; } public function get_icon() { return 'eicon-calendar'; }
  protected function tj_content_controls() {
    $this->add_control('title', ['label' => 'Title', 'type' => Controls_Manager::TEXT, 'default' => '📅 Calendar of events']);
    $this->add_control('cal_note', ['type' => Controls_Manager::RAW_HTML, 'raw' => '<small>Shows the current month. Days with events are highlighted; visitors tap a day to see its events and use ‹ › to change month.</small>']);
  }
  protected function tj_extra_style_controls() {
    $this->start_controls_section('tj_cal_c', ['label' => 'Highlighted days', 'tab' => Controls_Manager::TAB_STYLE]);
    $this->add_control('cal_hl', ['label' => 'Day number color', 'type' => Controls_Manager::COLOR, 'selectors' => ['{{WRAPPER}} .cal-cell.has-ev .cal-num' => 'background: {{VALUE}};', '{{WRAPPER}} .cal-cell.has-ev' => 'border-color: {{VALUE}};']]);
    $this->add_control('cal_ev', ['label' => 'Event label color', 'type' => Controls_Manager::COLOR, 'selectors' => ['{{WRAPPER}} .cal-ev, {{WRAPPER}} .cal-dots i' => 'background: {{VALUE}};']]);
    $this->end_controls_section();
  }
  protected function tj_opts(array $s) { return ['title' => $s['title'] ?? '']; }
}
class TJ_W_Cta extends TJ_Widget_Base { protected $tj_type = 'cta';
  public function get_name() { return 'tj_cta'; } public function get_title() { return 'TJ "Become an Organizer" Link'; } public function get_icon() { return 'eicon-call-to-action'; }
  protected function tj_content_controls() {
    $this->add_control('text', ['label' => 'Text', 'type' => Controls_Manager::TEXT, 'default' => 'Hosting an event?']);
    $this->add_control('link', ['label' => 'Link text', 'type' => Controls_Manager::TEXT, 'default' => 'Become an organizer →']);
    $this->add_control('hide_org', ['label' => 'Show "+ Create an event" to logged-in organizers', 'type' => Controls_Manager::SWITCHER, 'default' => 'yes']);
  }
  protected function tj_extra_style_controls() {
    $this->start_controls_section('tj_cta_c', ['label' => 'Link', 'tab' => Controls_Manager::TAB_STYLE]);
    $this->add_control('cta_color', ['label' => 'Text color', 'type' => Controls_Manager::COLOR, 'default' => '#FFFFFF', 'selectors' => ['{{WRAPPER}} .hero-cta, {{WRAPPER}} .hero-cta a.link' => 'color: {{VALUE}};']]);
    $this->end_controls_section();
  }
  protected function tj_opts(array $s) { return ['text' => $s['text'] ?? '', 'link' => $s['link'] ?? '', 'hideForOrganizers' => ($s['hide_org'] ?? 'yes') === 'yes']; }
}

function tj_elementor_widget_classes() {
  return ['TJ_W_Hero', 'TJ_W_Search', 'TJ_W_Weather', 'TJ_W_Cta', 'TJ_W_Chips', 'TJ_W_Map', 'TJ_W_Calendar', 'TJ_W_Grid', 'TJ_W_Event', 'TJ_W_Checkout', 'TJ_W_Tickets', 'TJ_W_Organizer', 'TJ_W_Admin', 'TJ_W_Login', 'TJ_W_Register', 'TJ_W_Ticket', 'TJ_W_Scanner', 'TJ_W_Home'];
}

<?php
/**
 * Plugin Name: UA Short Links PRO
 * Description: Скорочення посилань, рекламні кампанії, облік показів, переходів і рекламних балансів.
 * Version: 1.0.0
 * Author: UA Tools
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

define('UASL_VERSION', '1.0.0');
define('UASL_DB_VERSION', '1.0');

class UA_Short_Links_PRO {

    private $campaigns;
    private $links;
    private $events;

    public function __construct() {
        global $wpdb;

        $this->campaigns = $wpdb->prefix . 'uasl_campaigns';
        $this->links     = $wpdb->prefix . 'uasl_links';
        $this->events    = $wpdb->prefix . 'uasl_events';

        add_action('init', [$this, 'register_routes']);
        add_filter('query_vars', [$this, 'query_vars']);
        add_action('template_redirect', [$this, 'handle_routes']);

        add_shortcode('ua_shortener', [$this, 'shortener_shortcode']);

        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_uasl_save_campaign', [$this, 'save_campaign']);
        add_action('admin_post_uasl_topup', [$this, 'topup_campaign']);
        add_action('admin_post_uasl_delete_campaign', [$this, 'delete_campaign']);
        add_action('admin_post_uasl_create_link', [$this, 'create_link']);
        add_action('admin_post_uasl_delete_link', [$this, 'delete_link']);
        add_action('admin_post_uasl_save_settings', [$this, 'save_settings']);

        add_action('admin_post_nopriv_uasl_create_short', [$this, 'public_create_short']);
        add_action('admin_post_uasl_create_short', [$this, 'public_create_short']);

        add_action('uasl_monthly_reports', [$this, 'send_monthly_reports']);

        add_action('wp_enqueue_scripts', [$this, 'frontend_styles']);
    }

    public static function activate() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $campaigns = $wpdb->prefix . 'uasl_campaigns';
        $links = $wpdb->prefix . 'uasl_links';
        $events = $wpdb->prefix . 'uasl_events';

        $sql1 = "CREATE TABLE $campaigns (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            advertiser varchar(190) NOT NULL,
            email varchar(190) NOT NULL,
            domain varchar(190) NOT NULL,
            contact varchar(255) DEFAULT '',
            landing_url text DEFAULT '',
            banner_url text DEFAULT '',
            ad_html longtext DEFAULT '',
            ad_type varchar(30) NOT NULL DEFAULT 'page',
            order_amount decimal(12,2) NOT NULL DEFAULT 0,
            balance decimal(12,2) NOT NULL DEFAULT 0,
            impression_price decimal(12,4) NOT NULL DEFAULT 0.01,
            click_price decimal(12,4) NOT NULL DEFAULT 0.10,
            impressions bigint(20) unsigned NOT NULL DEFAULT 0,
            clicks bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY status (status)
        ) $charset;";

        $sql2 = "CREATE TABLE $links (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            alias varchar(100) NOT NULL,
            target_url text NOT NULL,
            created_at datetime NOT NULL,
            clicks bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY alias (alias)
        ) $charset;";

        $sql3 = "CREATE TABLE $events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) unsigned NOT NULL,
            link_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_type varchar(20) NOT NULL,
            cost decimal(12,4) NOT NULL DEFAULT 0,
            event_token varchar(64) DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY campaign_id (campaign_id),
            KEY created_at (created_at),
            UNIQUE KEY event_token (event_token)
        ) $charset;";

        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);

        add_option('uasl_settings', [
            'timer' => 10,
            'form_title' => 'Створити коротке посилання',
            'report_email' => get_option('admin_email')
        ]);

        if (!wp_next_scheduled('uasl_monthly_reports')) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'uasl_monthly_reports');
        }

        flush_rewrite_rules();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('uasl_monthly_reports');
        flush_rewrite_rules();
    }

    public function register_routes() {
        add_rewrite_rule(
            '^UAServer([A-Za-z0-9_-]+)/?$',
            'index.php?uasl_alias=$matches[1]',
            'top'
        );
    }

    public function query_vars($vars) {
        $vars[] = 'uasl_alias';
        $vars[] = 'uasl_continue';
        $vars[] = 'uasl_click';
        return $vars;
    }

    public function frontend_styles() {
        wp_register_style('uasl-style', false, [], UASL_VERSION);
        wp_enqueue_style('uasl-style');

        wp_add_inline_style('uasl-style', '
            .uasl-wrap{max-width:650px;margin:25px auto;padding:25px;background:#fff;border:1px solid #ddd;border-radius:12px;box-shadow:0 3px 15px rgba(0,0,0,.06)}
            .uasl-wrap h2{text-align:center;margin:0 0 20px}
            .uasl-wrap input{width:100%;padding:12px;margin:7px 0 15px;border:1px solid #ccc;border-radius:6px;box-sizing:border-box}
            .uasl-wrap button,.uasl-button{display:inline-block;background:#1769aa;color:#fff!important;padding:12px 22px;border:0;border-radius:6px;text-decoration:none;cursor:pointer}
            .uasl-ad{max-width:800px;margin:35px auto;padding:25px;text-align:center;background:#fff;border:1px solid #ddd;border-radius:12px}
            .uasl-ad img{max-width:100%;height:auto}
            .uasl-timer{font-size:22px;font-weight:bold;margin:20px}
            .uasl-muted{color:#777;font-size:13px}
        ');
    }

    private function settings() {
        return wp_parse_args(get_option('uasl_settings', []), [
            'timer' => 10,
            'form_title' => 'Створити коротке посилання',
            'report_email' => get_option('admin_email')
        ]);
    }

    private function table($name) {
        global $wpdb;
        return $wpdb->get_blog_prefix() . 'uasl_' . $name;
    }

    private function event($campaign_id, $link_id, $type, $cost, $token = '') {
        global $wpdb;

        $wpdb->insert($this->events, [
            'campaign_id' => absint($campaign_id),
            'link_id' => absint($link_id),
            'event_type' => sanitize_key($type),
            'cost' => max(0, (float)$cost),
            'event_token' => $token ? sanitize_text_field($token) : '',
            'created_at' => current_time('mysql')
        ], ['%d', '%d', '%s', '%f', '%s', '%s']);
    }

    private function get_campaign($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->campaigns} WHERE id=%d",
            $id
        ));
    }

    private function get_link($alias) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->links} WHERE alias=%s",
            $alias
        ));
    }

    private function select_campaign() {
        global $wpdb;

        $items = $wpdb->get_results(
            "SELECT * FROM {$this->campaigns}
             WHERE status='active'
             AND balance >= impression_price
             ORDER BY id DESC"
        );

        if (!$items) return false;

        $weighted = [];
        $total = 0;

        foreach ($items as $item) {
            $weight = max(1, (int)round(sqrt(max(1, (float)$item->order_amount) * 100)));
            $total += $weight;
            $weighted[] = ['item' => $item, 'weight' => $weight];
        }

        $pick = mt_rand(1, $total);

        foreach ($weighted as $entry) {
            $pick -= $entry['weight'];
            if ($pick <= 0) return $entry['item'];
        }

        return $items[0];
    }

    private function charge_impression($campaign, $link_id) {
        global $wpdb;

        $price = (float)$campaign->impression_price;

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->campaigns}
             SET balance = balance - %f,
                 impressions = impressions + 1
             WHERE id=%d AND status='active' AND balance >= %f",
            $price,
            $campaign->id,
            $price
        ));

        if ($updated) {
            $this->event($campaign->id, $link_id, 'impression', $price);
            return true;
        }

        return false;
    }

    public function handle_routes() {
        $alias = get_query_var('uasl_alias');

        if ($alias) {
            $this->show_interstitial(sanitize_text_field($alias));
            exit;
        }

        if (isset($_GET['uasl_continue'])) {
            $this->continue_redirect();
            exit;
        }

        if (isset($_GET['uasl_click'])) {
            $this->click_redirect();
            exit;
        }
    }

    private function show_interstitial($alias) {
        $link = $this->get_link($alias);

        if (!$link) {
            status_header(404);
            wp_die('Коротке посилання не знайдено.');
        }

        $campaign = $this->select_campaign();
        $timer = (int)$this->settings()['timer'];
        if (!in_array($timer, [5, 10, 30], true)) $timer = 10;

        $continue_url = add_query_arg([
            'uasl_continue' => $link->id,
            'sig' => hash_hmac('sha256', $link->id . '|' . $link->target_url, wp_salt('auth'))
        ], home_url('/'));

        $ad_html = '<p>Рекламне місце тимчасово вільне.</p>';

        if ($campaign && $this->charge_impression($campaign, $link->id)) {
            $ad_html = $this->render_ad($campaign, $link);
        } else {
            $campaign = false;
        }

        nocache_headers();

        echo '<!DOCTYPE html><html lang="uk"><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Рекламна сторінка</title>';
        echo '<style>
            body{font-family:Arial,sans-serif;background:#f4f6f8;margin:0;padding:15px;color:#222}
            .uasl-box{max-width:850px;margin:35px auto;background:#fff;padding:25px;border-radius:12px;text-align:center;box-shadow:0 3px 15px #0001}
            .uasl-box img{max-width:100%;height:auto}
            .uasl-btn{display:inline-block;padding:13px 25px;background:#1769aa;color:#fff;text-decoration:none;border-radius:7px;margin:8px}
            .uasl-count{font-size:24px;font-weight:bold;margin:20px}
            .uasl-small{font-size:13px;color:#777}
        </style></head><body>';

        echo '<div class="uasl-box">';
        echo '<h2>Підтримайте наших рекламодавців</h2>';
        echo '<div>' . $ad_html . '</div>';
        echo '<div class="uasl-count">Перехід через <span id="uasl-count">' . esc_html($timer) . '</span> сек.</div>';
        echo '<a class="uasl-btn" id="uasl-go" href="' . esc_url($continue_url) . '">Продовжити</a>';
        echo '<p class="uasl-small">Натисніть кнопку або дочекайтеся завершення таймера.</p>';
        echo '</div>';

        echo '<script>
        (function(){
            var n=' . (int)$timer . ';
            var el=document.getElementById("uasl-count");
            var btn=document.getElementById("uasl-go");
            var url=' . wp_json_encode($continue_url) . ';
            var interval=setInterval(function(){
                n--;
                el.textContent=n;
                if(n<=0){clearInterval(interval);window.location.href=url;}
            },1000);
            btn.addEventListener("click",function(){clearInterval(interval);});
        })();
        </script>';

        echo '</body></html>';
    }

    private function render_ad($campaign, $link) {
        $output = '';

        if ($campaign->ad_type === 'banner' && $campaign->banner_url) {
            $click = $this->click_url($campaign, $link);
            $output .= '<a href="' . esc_url($click) . '" rel="nofollow sponsored">';
            $output .= '<img src="' . esc_url($campaign->banner_url) . '" alt="' . esc_attr($campaign->advertiser) . '">';
            $output .= '</a>';
        } elseif ($campaign->ad_type === 'html' && $campaign->ad_html) {
            $output .= '<div class="uasl-ad-html">' . wp_kses_post($campaign->ad_html) . '</div>';
            if ($campaign->landing_url) {
                $output .= '<p><a class="uasl-btn" href="' . esc_url($this->click_url($campaign, $link)) . '" rel="nofollow sponsored">Дізнатися більше</a></p>';
            }
        } else {
            $output .= '<h3>' . esc_html($campaign->advertiser) . '</h3>';
            $output .= '<p>Реклама партнера</p>';
            if ($campaign->landing_url) {
                $output .= '<a class="uasl-btn" href="' . esc_url($this->click_url($campaign, $link)) . '" rel="nofollow sponsored">Перейти до рекламодавця</a>';
            }
        }

        return $output;
    }

    private function click_url($campaign, $link) {
        $token = wp_generate_password(32, false, false);
        $payload = $campaign->id . '|' . $link->id . '|' . $token;
        $sig = hash_hmac('sha256', $payload, wp_salt('auth'));

        return add_query_arg([
            'uasl_click' => $campaign->id,
            'link' => $link->id,
            'token' => $token,
            'sig' => $sig
        ], home_url('/'));
    }

    private function continue_redirect() {
        global $wpdb;

        $id = absint($_GET['uasl_continue']);
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->links} WHERE id=%d",
            $id
        ));

        if (!$link) wp_die('Посилання не знайдено.');

        $expected = hash_hmac('sha256', $link->id . '|' . $link->target_url, wp_salt('auth'));
        $provided = isset($_GET['sig']) ? sanitize_text_field(wp_unslash($_GET['sig'])) : '';

        if (!$provided || !hash_equals($expected, $provided)) {
            wp_die('Некоректний підпис посилання.');
        }

        $target = esc_url_raw($link->target_url);
        if (!$target || !wp_http_validate_url($target)) {
            wp_die('Некоректна адреса переходу.');
        }

        wp_redirect($target, 302);
        exit;
    }

    private function click_redirect() {
        global $wpdb;

        $campaign_id = absint($_GET['uasl_click']);
        $link_id = absint($_GET['link']);
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $sig = isset($_GET['sig']) ? sanitize_text_field(wp_unslash($_GET['sig'])) : '';

        $campaign = $this->get_campaign($campaign_id);
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->links} WHERE id=%d",
            $link_id
        ));

        if (!$campaign || !$link || !$token) wp_die('Посилання недійсне.');

        $payload = $campaign_id . '|' . $link_id . '|' . $token;
        $expected = hash_hmac('sha256', $payload, wp_salt('auth'));

        if (!hash_equals($expected, $sig)) wp_die('Некоректний підпис.');

        $destination = esc_url_raw($campaign->landing_url);
        if (!$destination || !wp_http_validate_url($destination)) {
            wp_die('Рекламна адреса не налаштована.');
        }

        $transient = 'uasl_click_' . md5($token);

        if (!get_transient($transient)) {
            $price = (float)$campaign->click_price;

            $charged = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->campaigns}
                 SET balance=balance-%f, clicks=clicks+1
                 WHERE id=%d AND balance >= %f AND status='active'",
                $price,
                $campaign_id,
                $price
            ));

            if ($charged) {
                $this->event($campaign_id, $link_id, 'click', $price, $token);
            }

            set_transient($transient, 1, DAY_IN_SECONDS);
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->links} SET clicks=clicks+1 WHERE id=%d",
            $link_id
        ));

        wp_redirect($destination, 302);
        exit;
    }

    public function shortener_shortcode() {
        $settings = $this->settings();

        ob_start();

        echo '<div class="uasl-wrap">';
        echo '<h2>' . esc_html($settings['form_title']) . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uasl_create_short">';
        wp_nonce_field('uasl_public_create', 'uasl_nonce');

        echo '<label>Довге посилання</label>';
        echo '<input type="url" name="target_url" placeholder="https://example.com/page" required>';

        echo '<label>Бажаний короткий код</label>';
        echo '<input type="text" name="alias" placeholder="12345" pattern="[A-Za-z0-9_-]{3,50}" required>';

        echo '<button type="submit">Створити коротке посилання</button>';
        echo '</form>';

        if (isset($_GET['uasl_created']) && isset($_GET['code'])) {
            $url = home_url('/UAServer' . sanitize_text_field(wp_unslash($_GET['code'])));
            echo '<p><strong>Ваше коротке посилання:</strong></p>';
            echo '<input readonly onclick="this.select()" value="' . esc_attr($url) . '">';
        }

        if (isset($_GET['uasl_error'])) {
            echo '<p style="color:#b00">Не вдалося створити посилання. Перевірте адресу та короткий код.</p>';
        }

        echo '</div>';

        return ob_get_clean();
    }

    public function public_create_short() {
        if (!isset($_POST['uasl_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['uasl_nonce'])), 'uasl_public_create')) {
            wp_die('Перевірка безпеки не пройдена.');
        }

        global $wpdb;

        $target = isset($_POST['target_url']) ? esc_url_raw(wp_unslash($_POST['target_url'])) : '';
        $alias = isset($_POST['alias']) ? sanitize_text_field(wp_unslash($_POST['alias'])) : '';

        $alias = preg_replace('/[^A-Za-z0-9_-]/', '', $alias);

        if (!$target || !wp_http_validate_url($target) || strlen($alias) < 3 || strlen($alias) > 50) {
            $this->public_error_redirect();
        }

        $exists = $this->get_link($alias);
        if ($exists) $this->public_error_redirect();

        $inserted = $wpdb->insert($this->links, [
            'alias' => $alias,
            'target_url' => $target,
            'created_at' => current_time('mysql')
        ], ['%s', '%s', '%s']);

        if (!$inserted) $this->public_error_redirect();

        $referer = wp_get_referer();
        if (!$referer) $referer = home_url('/');

        wp_safe_redirect(add_query_arg([
            'uasl_created' => 1,
            'code' => rawurlencode($alias)
        ], $referer));
        exit;
    }

    private function public_error_redirect() {
        $referer = wp_get_referer();
        if (!$referer) $referer = home_url('/');
        wp_safe_redirect(add_query_arg('uasl_error', 1, $referer));
        exit;
    }

    public function admin_menu() {
        add_menu_page(
            'UA Short Links PRO',
            'UA Short Links',
            'manage_options',
            'uasl',
            [$this, 'admin_dashboard'],
            'dashicons-admin-links',
            27
        );

        add_submenu_page('uasl', 'Кампанії', 'Рекламодавці', 'manage_options', 'uasl-campaigns', [$this, 'admin_campaigns']);
        add_submenu_page('uasl', 'Короткі посилання', 'Короткі посилання', 'manage_options', 'uasl-links', [$this, 'admin_links']);
        add_submenu_page('uasl', 'Налаштування', 'Налаштування', 'manage_options', 'uasl-settings', [$this, 'admin_settings']);
    }

    private function admin_header($title) {
        echo '<div class="wrap"><h1>' . esc_html($title) . '</h1>';
        echo '<style>
            .uasl-admin-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:15px;margin:20px 0}
            .uasl-stat{background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px}
            .uasl-stat strong{display:block;font-size:24px;margin-top:8px}
            .uasl-form{background:#fff;border:1px solid #ddd;padding:20px;max-width:850px;margin:20px 0}
            .uasl-form input,.uasl-form select,.uasl-form textarea{width:100%;max-width:600px;margin:5px 0 15px}
            .uasl-form input,.uasl-form select{padding:8px}
            .uasl-form textarea{min-height:90px}
            .uasl-table{background:#fff}
        </style>';
    }

    public function admin_dashboard() {
        if (!current_user_can('manage_options')) return;

        global $wpdb;

        $stats = $wpdb->get_row(
            "SELECT
                COUNT(*) AS campaigns,
                COALESCE(SUM(balance),0) AS balance,
                COALESCE(SUM(impressions),0) AS impressions,
                COALESCE(SUM(clicks),0) AS clicks
             FROM {$this->campaigns}"
        );

        $links = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->links}");

        $this->admin_header('UA Short Links PRO — Панель керування');

        echo '<div class="uasl-admin-grid">';
        echo '<div class="uasl-stat">Рекламні кампанії<strong>' . (int)$stats->campaigns . '</strong></div>';
        echo '<div class="uasl-stat">Загальний баланс<strong>' . number_format((float)$stats->balance, 2, ',', ' ') . ' грн</strong></div>';
        echo '<div class="uasl-stat">Покази<strong>' . number_format_i18n((int)$stats->impressions) . '</strong></div>';
        echo '<div class="uasl-stat">Переходи<strong>' . number_format_i18n((int)$stats->clicks) . '</strong></div>';
        echo '<div class="uasl-stat">Короткі посилання<strong>' . $links . '</strong></div>';
        echo '</div>';

        echo '<p><strong>Шорткод форми:</strong> <code>[ua_shortener]</code></p>';
        echo '<p>Додайте цей шорткод на сторінку WordPress, щоб відвідувачі могли створювати короткі посилання.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=uasl-campaigns')) . '">Керувати рекламодавцями</a> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=uasl-links')) . '">Переглянути посилання</a></p>';

        echo '</div>';
    }

    public function admin_campaigns() {
        if (!current_user_can('manage_options')) return;

        global $wpdb;

        $edit = isset($_GET['edit']) ? $this->get_campaign(absint($_GET['edit'])) : false;

        $this->admin_header('Рекламодавці та кампанії');

        echo '<form class="uasl-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<h2>' . ($edit ? 'Редагувати кампанію' : 'Додати рекламодавця / кампанію') . '</h2>';
        echo '<input type="hidden" name="action" value="uasl_save_campaign">';
        echo '<input type="hidden" name="id" value="' . ($edit ? (int)$edit->id : 0) . '">';
        wp_nonce_field('uasl_save_campaign');

        $fields = [
            'advertiser' => ['Назва рекламодавця', $edit->advertiser ?? ''],
            'email' => ['Email рекламодавця', $edit->email ?? ''],
            'domain' => ['Домен рекламодавця', $edit->domain ?? ''],
            'contact' => ['Контактні дані', $edit->contact ?? ''],
            'landing_url' => ['Рекламна сторінка / URL переходу', $edit->landing_url ?? ''],
            'banner_url' => ['URL банера', $edit->banner_url ?? '']
        ];

        foreach ($fields as $name => $field) {
            echo '<label><strong>' . esc_html($field[0]) . '</strong></label>';
            echo '<input type="' . ($name === 'email' ? 'email' : 'text') . '" name="' . esc_attr($name) . '" value="' . esc_attr($field[1]) . '" ' . (in_array($name, ['advertiser','email','domain'], true) ? 'required' : '') . '>';
        }

        $type = $edit->ad_type ?? 'page';

        echo '<label><strong>Тип реклами</strong></label>';
        echo '<select name="ad_type">';
        foreach (['page' => 'Рекламна сторінка', 'banner' => 'Графічний банер', 'html' => 'HTML-код'] as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($type, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';

        echo '<label><strong>HTML-реклама</strong></label>';
        echo '<textarea name="ad_html">' . esc_textarea($edit->ad_html ?? '') . '</textarea>';
        echo '<p class="description">WordPress очищає небезпечні HTML-теги та скрипти.</p>';

        if ($edit) {
            echo '<p>Поточний баланс: <strong>' . number_format((float)$edit->balance, 2, ',', ' ') . ' грн</strong></p>';
            echo '<label><strong>Нове замовлення (початковий бюджет)</strong></label>';
            echo '<input type="number" step="0.01" min="0" name="order_amount" value="' . esc_attr($edit->order_amount) . '">';
            echo '<p class="description">Під час редагування баланс не змінюється. Для поповнення використовуйте окрему форму нижче.</p>';
        } else {
            echo '<label><strong>Сума замовлення / початковий бюджет (грн)</strong></label>';
            echo '<input type="number" step="0.01" min="0" name="order_amount" value="1000" required>';
        }

        echo '<label><strong>Ціна за 1000? Ні — ціна за один показ (грн)</strong></label>';
        echo '<input type="number" step="0.0001" min="0" name="impression_price" value="' . esc_attr($edit->impression_price ?? '0.01') . '">';

        echo '<label><strong>Ціна за один відстежуваний перехід (грн)</strong></label>';
        echo '<input type="number" step="0.0001" min="0" name="click_price" value="' . esc_attr($edit->click_price ?? '0.10') . '">';

        echo '<label><strong>Статус</strong></label>';
        echo '<select name="status">';
        foreach (['active' => 'Активна', 'paused' => 'Призупинена'] as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($edit->status ?? 'active', $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';

        submit_button($edit ? 'Зберегти зміни' : 'Створити кампанію');
        echo '</form>';

        $items = $wpdb->get_results("SELECT * FROM {$this->campaigns} ORDER BY id DESC");

        echo '<h2>Список рекламодавців</h2>';
        echo '<table class="widefat striped uasl-table"><thead><tr>
            <th>Рекламодавець</th><th>Email</th><th>Замовлення</th><th>Баланс</th>
            <th>Покази</th><th>Переходи</th><th>Витрачено</th><th>Статус</th><th>Дії</th>
        </tr></thead><tbody>';

        foreach ($items as $item) {
            $spent = max(0, (float)$item->order_amount - (float)$item->balance);

            echo '<tr>';
            echo '<td><strong>' . esc_html($item->advertiser) . '</strong><br><small>' . esc_html($item->domain) . '</small></td>';
            echo '<td>' . esc_html($item->email) . '</td>';
            echo '<td>' . number_format((float)$item->order_amount, 2, ',', ' ') . ' грн</td>';
            echo '<td><strong>' . number_format((float)$item->balance, 2, ',', ' ') . ' грн</strong></td>';
            echo '<td>' . number_format_i18n((int)$item->impressions) . '</td>';
            echo '<td>' . number_format_i18n((int)$item->clicks) . '</td>';
            echo '<td>' . number_format($spent, 2, ',', ' ') . ' грн</td>';
            echo '<td>' . ($item->status === 'active' ? 'Активна' : 'Призупинена') . '</td>';
            echo '<td><a href="' . esc_url(admin_url('admin.php?page=uasl-campaigns&edit=' . (int)$item->id)) . '">Редагувати</a><br>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:6px">';
            echo '<input type="hidden" name="action" value="uasl_delete_campaign">';
            echo '<input type="hidden" name="id" value="' . (int)$item->id . '">';
            wp_nonce_field('uasl_delete_campaign_' . (int)$item->id);
            echo '<button class="button-link-delete" onclick="return confirm(\'Видалити кампанію?\')">Видалити</button>';
            echo '</form></td></tr>';

            echo '<tr><td colspan="9">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
            echo '<input type="hidden" name="action" value="uasl_topup">';
            echo '<input type="hidden" name="id" value="' . (int)$item->id . '">';
            wp_nonce_field('uasl_topup_' . (int)$item->id);
            echo '<strong>Поповнити баланс:</strong> ';
            echo '<input type="number" name="amount" min="0.01" step="0.01" placeholder="Сума, грн" required style="width:130px">';
            echo '<button class="button">Зарахувати</button></form>';
            echo '</td></tr>';
        }

        if (!$items) echo '<tr><td colspan="9">Рекламодавців поки немає.</td></tr>';

        echo '</tbody></table></div>';
    }

    public function save_campaign() {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');
        check_admin_referer('uasl_save_campaign');

        global $wpdb;

        $id = absint($_POST['id'] ?? 0);
        $advertiser = sanitize_text_field(wp_unslash($_POST['advertiser'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $domain = sanitize_text_field(wp_unslash($_POST['domain'] ?? ''));

        if (!$advertiser || !is_email($email) || !$domain) {
            wp_die('Заповніть назву, коректний email і домен.');
        }

        $data = [
            'advertiser' => $advertiser,
            'email' => $email,
            'domain' => $domain,
            'contact' => sanitize_text_field(wp_unslash($_POST['contact'] ?? '')),
            'landing_url' => esc_url_raw(wp_unslash($_POST['landing_url'] ?? '')),
            'banner_url' => esc_url_raw(wp_unslash($_POST['banner_url'] ?? '')),
            'ad_html' => wp_kses_post(wp_unslash($_POST['ad_html'] ?? '')),
            'ad_type' => in_array($_POST['ad_type'] ?? '', ['page','banner','html'], true) ? sanitize_key($_POST['ad_type']) : 'page',
            'impression_price' => max(0, (float)($_POST['impression_price'] ?? 0.01)),
            'click_price' => max(0, (float)($_POST['click_price'] ?? 0.10)),
            'status' => ($_POST['status'] ?? 'active') === 'paused' ? 'paused' : 'active'
        ];

        if ($id) {
            $wpdb->update($this->campaigns, $data, ['id' => $id]);
        } else {
            $amount = max(0, (float)($_POST['order_amount'] ?? 0));
            $data['order_amount'] = $amount;
            $data['balance'] = $amount;
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($this->campaigns, $data);
        }

        wp_safe_redirect(admin_url('admin.php?page=uasl-campaigns&saved=1'));
        exit;
    }

    public function topup_campaign() {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');

        $id = absint($_POST['id'] ?? 0);
        check_admin_referer('uasl_topup_' . $id);

        global $wpdb;

        $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
        if ($amount <= 0) wp_die('Сума поповнення має бути більшою за нуль.');

        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->campaigns}
             SET balance=balance+%f, order_amount=order_amount+%f
             WHERE id=%d",
            $amount, $amount, $id
        ));

        wp_safe_redirect(admin_url('admin.php?page=uasl-campaigns&topped=1'));
        exit;
    }

    public function delete_campaign() {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');

        $id = absint($_POST['id'] ?? 0);
        check_admin_referer('uasl_delete_campaign_' . $id);

        global $wpdb;
        $wpdb->delete($this->campaigns, ['id' => $id], ['%d']);

        wp_safe_redirect(admin_url('admin.php?page=uasl-campaigns&deleted=1'));
        exit;
    }

    public function admin_links() {
        if (!current_user_can('manage_options')) return;

        global $wpdb;

        $items = $wpdb->get_results("SELECT * FROM {$this->links} ORDER BY id DESC LIMIT 500");

        $this->admin_header('Короткі посилання');

        echo '<form class="uasl-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<h2>Створити посилання вручну</h2>';
        echo '<input type="hidden" name="action" value="uasl_create_link">';
        wp_nonce_field('uasl_create_link');

        echo '<label>Цільова адреса</label>';
        echo '<input type="url" name="target_url" required placeholder="https://example.com">';

        echo '<label>Короткий код</label>';
        echo '<input type="text" name="alias" required pattern="[A-Za-z0-9_-]{3,50}" placeholder="12345">';

        submit_button('Створити посилання');
        echo '</form>';

        echo '<table class="widefat striped"><thead><tr><th>Коротке посилання</th><th>Цільова адреса</th><th>Кліки</th><th>Створено</th><th>Дії</th></tr></thead><tbody>';

        foreach ($items as $item) {
            $short = home_url('/UAServer' . $item->alias);

            echo '<tr>';
            echo '<td><a href="' . esc_url($short) . '" target="_blank">' . esc_html($short) . '</a></td>';
            echo '<td style="max-width:350px;overflow-wrap:anywhere">' . esc_html($item->target_url) . '</td>';
            echo '<td>' . (int)$item->clicks . '</td>';
            echo '<td>' . esc_html($item->created_at) . '</td>';
            echo '<td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="uasl_delete_link">';
            echo '<input type="hidden" name="id" value="' . (int)$item->id . '">';
            wp_nonce_field('uasl_delete_link_' . (int)$item->id);
            echo '<button class="button-link-delete" onclick="return confirm(\'Видалити посилання?\')">Видалити</button>';
            echo '</form></td></tr>';
        }

        if (!$items) echo '<tr><td colspan="5">Посилань поки немає.</td></tr>';

        echo '</tbody></table></div>';
    }

    public function create_link() {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');
        check_admin_referer('uasl_create_link');

        global $wpdb;

        $target = esc_url_raw(wp_unslash($_POST['target_url'] ?? ''));
        $alias = sanitize_text_field(wp_unslash($_POST['alias'] ?? ''));
        $alias = preg_replace('/[^A-Za-z0-9_-]/', '', $alias);

        if (!$target || !wp_http_validate_url($target) || strlen($alias) < 3 || $this->get_link($alias)) {
            wp_die('Перевірте адресу та унікальність короткого коду.');
        }

        $wpdb->insert($this->links, [
            'alias' => $alias,
            'target_url' => $target,
            'created_at' => current_time('mysql')
        ]);

        wp_safe_redirect(admin_url('admin.php?page=uasl-links&created=1'));
        exit;
    }

    public function delete_link() {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');

        $id = absint($_POST['id'] ?? 0);
        check_admin_referer('uasl_delete_link_' . $id);

        global $wpdb;
        $wpdb->delete($this->links, ['id' => $id], ['%d']);

        wp_safe_redirect(admin_url('admin.php?page=uasl-links&deleted=1'));
        exit;
    }

    public function admin_settings() {
        if (!current_user_can('manage_options')) return;

        $settings = $this->settings();

        $this->admin_header('Налаштування UA Short Links PRO');

        echo '<form class="uasl-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="uasl_save_settings">';
        wp_nonce_field('uasl_save_settings');

        echo '<label><strong>Тривалість рекламної сторінки</strong></label>';
        echo '<select name="timer">';
        foreach ([5, 10, 30] as $seconds) {
            echo '<option value="' . $seconds . '" ' . selected((int)$settings['timer'], $seconds, false) . '>' . $seconds . ' секунд</option>';
        }
        echo '</select>';

        echo '<label><strong>Заголовок форми скорочення посилань</strong></label>';
        echo '<input type="text" name="form_title" value="' . esc_attr($settings['form_title']) . '">';

        echo '<label><strong>Email адміністратора для звітів</strong></label>';
        echo '<input type="email" name="report_email" value="' . esc_attr($settings['report_email']) . '" required>';

        submit_button('Зберегти налаштування');
        echo '</form>';

        echo '<p>Щоб показати форму скорочення посилань на сторінці, додайте шорткод <code>[ua_shortener]</code>.</p>';
        echo '</div>';
    }

    public function save_settings() {
        if (!current_user_can('manage_options')) wp_die('Недостатньо прав.');
        check_admin_referer('uasl_save_settings');

        $timer = absint($_POST['timer'] ?? 10);
        if (!in_array($timer, [5, 10, 30], true)) $timer = 10;

        $settings = [
            'timer' => $timer,
            'form_title' => sanitize_text_field(wp_unslash($_POST['form_title'] ?? 'Створити коротке посилання')),
            'report_email' => sanitize_email(wp_unslash($_POST['report_email'] ?? get_option('admin_email')))
        ];

        update_option('uasl_settings', $settings);

        wp_safe_redirect(admin_url('admin.php?page=uasl-settings&saved=1'));
        exit;
    }

    public function send_monthly_reports() {
        global $wpdb;

        // Звіт за попередній календарний місяць у часовому поясі WordPress.
        $now = current_datetime();
        $first_this_month = $now->modify('first day of this month')->setTime(0, 0, 0);
        $first_previous_month = $first_this_month->modify('-1 month');

        $start = $first_previous_month->format('Y-m-d H:i:s');
        $end = $first_this_month->format('Y-m-d H:i:s');
        $period = $first_previous_month->format('m.Y');

        $campaigns = $wpdb->get_results("SELECT * FROM {$this->campaigns} WHERE email <> ''");
        $admin_email = $this->settings()['report_email'];

        foreach ($campaigns as $campaign) {
            $stats = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN event_type='impression' THEN 1 ELSE 0 END) AS impressions,
                    SUM(CASE WHEN event_type='click' THEN 1 ELSE 0 END) AS clicks,
                    COALESCE(SUM(cost),0) AS spent
                 FROM {$this->events}
                 WHERE campaign_id=%d AND created_at >= %s AND created_at < %s",
                $campaign->id, $start, $end
            ));

            $message = "Звіт UA Short Links PRO за {$period}\n\n";
            $message .= "Рекламодавець: {$campaign->advertiser}\n";
            $message .= "Покази: " . (int)$stats->impressions . "\n";
            $message .= "Переходи: " . (int)$stats->clicks . "\n";
            $message .= "Витрачено за місяць: " . number_format((float)$stats->spent, 2, '.', '') . " грн\n";
            $message .= "Поточний баланс: " . number_format((float)$campaign->balance, 2, '.', '') . " грн\n";

            wp_mail($campaign->email, "Звіт рекламної кампанії — {$period}", $message);

            if ($admin_email && is_email($admin_email)) {
                wp_mail($admin_email, "Звіт рекламодавця: {$campaign->advertiser} — {$period}", $message);
            }
        }
    }
}

register_activation_hook(__FILE__, ['UA_Short_Links_PRO', 'activate']);
register_deactivation_hook(__FILE__, ['UA_Short_Links_PRO', 'deactivate']);

new UA_Short_Links_PRO();

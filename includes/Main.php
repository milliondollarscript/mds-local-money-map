<?php
/**
 * Local Money Map runtime, admin UI, frontend, REST API, and privacy hooks.
 *
 * @package MillionDollarScript\Extensions\LocalMoneyMap
 */

namespace MillionDollarScript\Extensions\LocalMoneyMap;

if (!defined('ABSPATH')) {
    exit;
}

final class Main {

    public const REST_NAMESPACE = 'million-dollar-script/v1';
    public const REST_BASE = 'local-money-map';
    private static $instance;

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function register() {
        add_action('init', [__CLASS__, 'register_post_types'], 5);
        add_action('init', [$this, 'load_textdomain']);
        add_action('init', [$this, 'register_shortcodes']);
        add_action('admin_menu', [$this, 'admin_menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
        add_action('template_redirect', [$this, 'handle_spot_redirect']);
        add_action('admin_post_mds_local_money_map_save_board', [$this, 'handle_save_board']);
        add_action('admin_post_mds_local_money_map_moderate_localmap', [$this, 'handle_moderate_localmap']);
        add_action('admin_post_mds_local_money_map_moderate_claim', [$this, 'handle_moderate_claim']);
        add_action('admin_post_mds_local_money_map_submit_localmap', [$this, 'handle_submit_localmap']);
        add_action('admin_post_nopriv_mds_local_money_map_submit_localmap', [$this, 'handle_submit_localmap']);
        add_action('admin_post_mds_local_money_map_submit_claim', [$this, 'handle_submit_claim']);
        add_action('admin_post_nopriv_mds_local_money_map_submit_claim', [$this, 'handle_submit_claim']);
        add_action('admin_post_mds_local_money_map_qr', [$this, 'handle_qr_download']);
        add_action('admin_post_nopriv_mds_local_money_map_qr', [$this, 'handle_qr_download']);
        add_action('admin_post_mds_local_money_map_qr_print', [$this, 'handle_qr_print']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('million-dollar-script/payment/source/status', [$this, 'handle_payment_status'], 10, 4);
        add_action('million-dollar-script/commerce/source/activate', [$this, 'handle_subscription_term'], 10, 4);
        add_action('million-dollar-script/commerce/source/renew', [$this, 'handle_subscription_term'], 10, 4);
        add_filter('million-dollar-script/api/endpoint/manifest', [$this, 'api_endpoint_manifest']);
        add_filter('million-dollar-script/extension/onboarding/items', [$this, 'extension_onboarding_items']);
        add_filter('million-dollar-script/editor/extension/blocks', [$this, 'editor_blocks']);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'privacy_exporters']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'privacy_erasers']);
    }

    public static function register_post_types() {
        register_post_type(Repository::BOARD_POST_TYPE, [
            'label' => __('Local Money Map Campaigns', 'mds-local-money-map'),
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);

        register_post_type(Repository::BOUNTY_POST_TYPE, [
            'label' => __('Spots', 'mds-local-money-map'),
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title', 'editor'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);

        register_post_type(Repository::CLAIM_POST_TYPE, [
            'label' => __('Local Money Map Check-ins', 'mds-local-money-map'),
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title', 'editor'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public function load_textdomain() {
        load_plugin_textdomain('mds-local-money-map', false, dirname(MDS_LOCAL_MONEY_MAP_BASENAME) . '/languages');
    }

    public function register_shortcodes() {
        add_shortcode('mds_local_money_map', [$this, 'shortcode']);
    }

    public function editor_blocks(array $blocks) {
        $blocks[] = [
            'name' => 'mds-local-money-map/campaign',
            'title' => __('Local Money Map', 'mds-local-money-map'),
            'description' => __('Display a Million Dollar Script local sponsorship campaign with spot listings and optional check-in forms.', 'mds-local-money-map'),
            'icon' => 'location-alt',
            'category' => 'widgets',
            'shortcode' => 'mds_local_money_map',
            'attributes' => [
                'id' => ['type' => 'number', 'default' => 0],
                'slug' => ['type' => 'string', 'default' => ''],
                'show_spot_form' => ['type' => 'boolean', 'default' => true],
                'show_checkin_forms' => ['type' => 'boolean', 'default' => true],
                'show_notice' => ['type' => 'boolean', 'default' => true],
            ],
            'controls' => [
                [
                    'attribute' => 'id',
                    'type' => 'entity',
                    'label' => __('Campaign', 'mds-local-money-map'),
                    'help' => __('Choose a saved campaign by numeric ID. Leave this on First active campaign to let the block pick automatically, or use Custom campaign ID only when you already know the ID.', 'mds-local-money-map'),
                    'empty_label' => __('First active campaign', 'mds-local-money-map'),
                    'custom_label' => __('Custom campaign ID', 'mds-local-money-map'),
                    'custom_help' => __('Enter the numeric database ID for a campaign that is not shown in the list. This is different from the campaign slug.', 'mds-local-money-map'),
                    'options' => $this->block_entity_options(Repository::boards(100), __('Untitled campaign', 'mds-local-money-map')),
                ],
                [
                    'attribute' => 'slug',
                    'type' => 'text',
                    'label' => __('Campaign slug', 'mds-local-money-map'),
                    'help' => __('Optional URL-safe campaign slug used only when no specific campaign ID is selected. Leave blank unless you want this block to find a campaign by slug.', 'mds-local-money-map'),
                ],
                [
                    'attribute' => 'show_spot_form',
                    'type' => 'toggle',
                    'label' => __('Show sponsor spot form', 'mds-local-money-map'),
                    'help' => __('Display the public form visitors use to submit new sponsor spots when capacity is available.', 'mds-local-money-map'),
                ],
                [
                    'attribute' => 'show_checkin_forms',
                    'type' => 'toggle',
                    'label' => __('Show check-in forms', 'mds-local-money-map'),
                    'help' => __('Display public check-in forms on eligible sponsor spot cards.', 'mds-local-money-map'),
                ],
                [
                    'attribute' => 'show_notice',
                    'type' => 'toggle',
                    'label' => __('Show offer notice', 'mds-local-money-map'),
                    'help' => __('Display the public notice explaining sponsored offers and local fulfillment.', 'mds-local-money-map'),
                ],
            ],
            'preview' => [
                'title' => __('Local Money Map', 'mds-local-money-map'),
                'description' => __('Shows sponsor spots, QR targets, offer details, check-ins, and optional public spot submissions.', 'mds-local-money-map'),
                'rows' => [
                    __('Sponsor spot cards and QR links', 'mds-local-money-map'),
                    __('Optional check-in forms', 'mds-local-money-map'),
                    __('Optional public sponsor spot form', 'mds-local-money-map'),
                ],
            ],
            'render_callback' => [$this, 'render_editor_block'],
        ];

        return $blocks;
    }

    private function block_entity_options(array $items, $fallback_title) {
        $options = [];
        foreach ($items as $item) {
            $id = absint($item['id'] ?? 0);
            if (!$id) {
                continue;
            }

            $label = sprintf('#%d %s', $id, (string) ($item['title'] ?? $fallback_title));
            if (!empty($item['status'])) {
                $label .= ' - ' . ucfirst((string) $item['status']);
            }

            $options[] = [
                'label' => $label,
                'value' => (string) $id,
            ];
        }

        return $options;
    }

    public function render_editor_block(array $attributes = []) {
        return $this->shortcode([
            'id' => absint($attributes['id'] ?? 0),
            'slug' => sanitize_title((string) ($attributes['slug'] ?? '')),
            'show_spot_form' => $this->truthy($attributes['show_spot_form'] ?? true) ? 'true' : 'false',
            'show_checkin_forms' => $this->truthy($attributes['show_checkin_forms'] ?? true) ? 'true' : 'false',
            'show_notice' => $this->truthy($attributes['show_notice'] ?? true) ? 'true' : 'false',
        ]);
    }

    public function admin_menu() {
        add_submenu_page(
            $this->admin_parent_slug(),
            __('Local Money Map', 'mds-local-money-map'),
            __('Local Money Map', 'mds-local-money-map'),
            'manage_options',
            'mds-local-money-map',
            [$this, 'admin_page']
        );
    }

    public function admin_assets($hook) {
        if (false === strpos((string) $hook, 'mds-local-money-map')) {
            return;
        }

        if (class_exists('\MillionDollarScript\Core\Runtime')) {
            wp_enqueue_style('million-dollar-script-admin', \MillionDollarScript\Core\Runtime::url('assets/mds3/') . 'css/admin.css', [], \MillionDollarScript\Core\Runtime::version());
            wp_enqueue_script('million-dollar-script-admin', \MillionDollarScript\Core\Runtime::url('assets/mds3/') . 'js/admin.js', [], \MillionDollarScript\Core\Runtime::version(), true);
        }
        wp_enqueue_style('mds-local-money-map-admin', MDS_LOCAL_MONEY_MAP_URL . 'assets/css/admin.css', [], MDS_LOCAL_MONEY_MAP_VERSION);
    }

    public function extension_onboarding_items(array $items) {
        $items['mds-local-money-map'] = [
            'name' => __('Local Money Map', 'mds-local-money-map'),
            'summary' => __('Define local sponsor spots, location privacy, QR redirects, check-in moderation, and offer disclosures before publishing campaigns.', 'mds-local-money-map'),
            'priority' => 45,
            'actions' => [
                ['label' => __('Open Local Money Map', 'mds-local-money-map'), 'url' => admin_url('admin.php?page=mds-local-money-map'), 'primary' => true],
            ],
            'legal_documents' => [
                [
                    'slug' => 'local-sponsor-terms',
                    'title' => __('Local Sponsor Map Terms', 'mds-local-money-map'),
                    'description' => __('Terms for local sponsor spots, map locations, QR redirects, offers, check-ins, privacy, and removals.', 'mds-local-money-map'),
                    'page_slug' => 'local-sponsor-map-terms',
                    'content' => $this->local_money_map_legal_content(),
                ],
            ],
        ];

        return $items;
    }

    private function local_money_map_legal_content() {
        return '<h2>' . esc_html__('Local Sponsor Map Terms', 'mds-local-money-map') . '</h2>'
            . '<p>' . esc_html__('These terms apply to sponsor spots, local offers, trails, QR targets, business details, links, approximate locations, and check-ins published through Local Money Map on {{site_name}}.', 'mds-local-money-map') . '</p>'
            . '<h3>' . esc_html__('Location, Offers, and QR Redirects', 'mds-local-money-map') . '</h3>'
            . '<p>' . esc_html__('Sponsors and submitters are responsible for accurate business names, addresses, approximate map locations, offer details, hours, eligibility, expiration dates, landing pages, accessibility information, regulated notices, and permissions for submitted logos, images, and trademarks. QR codes and redirects may be logged for security, abuse prevention, reporting, dispute handling, and sponsor analytics.', 'mds-local-money-map') . '</p>'
            . '<h3>' . esc_html__('Paid Placement, Check-ins, and Privacy', 'mds-local-money-map') . '</h3>'
            . '<p>' . esc_html__('Map spots, highlighted routes, badges, featured offers, QR placements, and local trails may be paid advertisements or sponsored placements. Check-ins may require contact details, business proof, moderation notes, payment references, timestamps, and technical request data. Sponsor and check-in information may be reviewed, retained, corrected, exported, erased, paused, or removed according to privacy law and site rules.', 'mds-local-money-map') . '</p>'
            . '<h3>' . esc_html__('No Availability or Redemption Guarantee', 'mds-local-money-map') . '</h3>'
            . '<p>' . esc_html__('{{site_name}} does not guarantee that a listed business is open, that an offer is available, that a QR redirect will remain active, that map directions are accurate, or that a sponsor will honor an offer unless a separate written agreement says so. Users should confirm details directly with the business before relying on a listing.', 'mds-local-money-map') . '</p>'
            . '<h3>' . esc_html__('Moderation and Contact', 'mds-local-money-map') . '</h3>'
            . '<p>' . esc_html__('Listings may be rejected, edited for formatting, hidden, paused, relabeled, or removed if they are inaccurate, unlawful, misleading, infringing, unsafe, malicious, abusive, payment-risky, or inconsistent with site rules. Correction, takedown, privacy, and support requests can be sent through {{contact_method}}.', 'mds-local-money-map') . '</p>';
    }

    public function frontend_assets() {
        if (!wp_style_is('million-dollar-script-openlayers', 'registered') && class_exists('\\MillionDollarScript\\Core\\Runtime')) {
            wp_register_style('million-dollar-script-openlayers', \MillionDollarScript\Core\Runtime::url('assets/mds3/') . 'vendor/ol/ol.css', [], '10.9.0');
        }
        if (!wp_script_is('million-dollar-script-openlayers', 'registered') && class_exists('\\MillionDollarScript\\Core\\Runtime')) {
            wp_register_script('million-dollar-script-openlayers', \MillionDollarScript\Core\Runtime::url('assets/mds3/') . 'vendor/ol/ol.js', [], '10.9.0', true);
        }
        $script_dependencies = wp_script_is('million-dollar-script-openlayers', 'registered') ? ['million-dollar-script-openlayers'] : [];
        wp_register_style('mds-local-money-map', MDS_LOCAL_MONEY_MAP_URL . 'assets/css/style.css', [], MDS_LOCAL_MONEY_MAP_VERSION);
        wp_register_script('mds-local-money-map-map', MDS_LOCAL_MONEY_MAP_URL . 'assets/js/map.js', $script_dependencies, MDS_LOCAL_MONEY_MAP_VERSION, true);
        \MillionDollarScript\Extensions\Support::add_browser_config('mds-local-money-map-map', 'localMoneyMap', 'config', [
            'unavailable' => __('The interactive map is unavailable. Sponsor locations remain listed below.', 'mds-local-money-map'),
        ]);
    }

    public function handle_spot_redirect() {
        $spot_id = absint($_GET['mds_lmm_spot'] ?? 0);
        if (!$spot_id) {
            return;
        }

        $spot = Repository::localmap($spot_id);
        if (!$spot || !Repository::is_spot_public($spot_id)) {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        $target = $this->public_target_url((string) ($spot['external_url'] ?? ''));
        if (!$target) {
            wp_safe_redirect(home_url('/'));
            exit;
        }

        wp_redirect($target, 302, 'Million Dollar Script - Local Money Map');
        exit;
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage local money map campaigns.', 'mds-local-money-map'));
        }

        $board_page = max(1, absint($_GET['lmm_board_page'] ?? 1));
        $boards_collection = Repository::boards_page($board_page, 20);
        $boards = $boards_collection['items'];
        $selected_id = absint($_GET['board_id'] ?? ($boards[0]['id'] ?? 0));
        $board = $selected_id ? Repository::board($selected_id) : null;
        $spot_page = max(1, absint($_GET['lmm_spot_page'] ?? 1));
        $spots_collection = $board ? Repository::spots_page($board['id'], $spot_page, 20) : ['items' => [], 'page' => 1, 'pages' => 1, 'total' => 0];
        $spots = $spots_collection['items'];
        $checkin_spot_id = absint($_GET['lmm_checkin_spot'] ?? 0);
        $checkin_spot = $checkin_spot_id ? Repository::localmap($checkin_spot_id) : null;
        if (!$board || !$checkin_spot || absint($checkin_spot['board_id'] ?? 0) !== absint($board['id'])) {
            $checkin_spot = null;
            $checkin_spot_id = 0;
        }
        $checkin_page = max(1, absint($_GET['lmm_checkin_page'] ?? 1));
        $checkins_collection = $checkin_spot ? Repository::claims_page($checkin_spot_id, $checkin_page, 25) : ['items' => [], 'page' => 1, 'pages' => 1, 'total' => 0];
        $new_board = [
            'id' => 0,
            'title' => '',
            'slug' => '',
            'description' => '',
            'status' => 'draft',
            'currency' => Repository::default_currency(),
            'posting_fee' => 25,
            'featured_fee' => 50,
            'localmap_limit' => 50,
            'payment_mode' => 'manual',
            'claims_enabled' => true,
            'map_enabled' => true,
            'disclosure' => __('Sponsor spots and offers may be paid placements. Offer redemption, availability, and fulfillment are handled by each participating business.', 'mds-local-money-map'),
            'terms_url' => '',
            'contact_email' => get_option('admin_email'),
            'retention_days' => 365,
            'submission_limit_per_hour' => 10,
        ];
        $form_board = $board ?: $new_board;
        $currency_locked = Repository::currency_locked();
        if ($currency_locked) {
            $form_board['currency'] = Repository::effective_currency($form_board['currency'] ?? '');
        }
        $default_tab = $board ? 'spots' : 'settings';
        $active_tab = sanitize_key((string) ($_GET['tab'] ?? $default_tab));
        $allowed_tabs = $board ? ['settings', 'spots'] : ['settings'];
        if (!in_array($active_tab, $allowed_tabs, true)) {
            $active_tab = $default_tab;
        }
        ?>
        <div class="wrap mds3-admin mds-local-money-map-admin">
            <div class="mds-local-money-map-hero">
                <div>
                    <p class="mds-local-money-map-eyebrow"><?php esc_html_e('Million Dollar Script extension', 'mds-local-money-map'); ?></p>
                    <h1><?php esc_html_e('Local Money Map', 'mds-local-money-map'); ?></h1>
                    <p><?php esc_html_e('Create local sponsor trails with paid spots, featured offers, QR target links, and moderated visitor submissions.', 'mds-local-money-map'); ?></p>
                </div>
                <div class="mds-extension-header-actions has-shortcode">
                    <?php echo wp_kses_post(\MillionDollarScript\Extensions\Admin::docs_button('mds-local-money-map')); ?>
                    <?php if ($board) : ?>
                        <?php echo wp_kses_post(\MillionDollarScript\Extensions\Admin::shortcode_copy(sprintf('[mds_local_money_map id="%d"]', absint($board['id'])))); ?>
                    <?php endif; ?>
                </div>
            </div>
            <hr class="wp-header-end">

            <?php $this->admin_notice(); ?>

            <nav class="nav-tab-wrapper wp-clearfix mds-local-money-map-tabs" aria-label="<?php esc_attr_e('Local Money Map workspace', 'mds-local-money-map'); ?>">
                <?php if ($board) : ?>
                    <a class="nav-tab<?php echo 'spots' === $active_tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url($this->admin_url(['board_id' => $selected_id, 'tab' => 'spots'])); ?>" <?php echo 'spots' === $active_tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html(sprintf(__('Spots (%d)', 'mds-local-money-map'), absint($spots_collection['total']))); ?></a>
                <?php endif; ?>
                <a class="nav-tab<?php echo 'settings' === $active_tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url($this->admin_url(['board_id' => $selected_id, 'tab' => 'settings'])); ?>" <?php echo 'settings' === $active_tab ? 'aria-current="page"' : ''; ?>><?php esc_html_e('Campaign settings', 'mds-local-money-map'); ?></a>
            </nav>

            <div class="mds-local-money-map-layout">
                <section class="mds3-card mds-local-money-map-panel">
                    <h2><?php esc_html_e('Campaigns', 'mds-local-money-map'); ?></h2>
                    <div class="mds-local-money-map-list">
                        <?php foreach ($boards as $item) : ?>
                            <a class="mds-local-money-map-list-item <?php echo absint($item['id']) === absint($selected_id) ? 'is-selected' : ''; ?>" href="<?php echo esc_url($this->admin_url(['board_id' => $item['id'], 'tab' => $active_tab])); ?>">
                                <strong><?php echo esc_html($item['title']); ?></strong>
                                <span><?php echo esc_html($this->status_label($item['status'])); ?> · <?php echo esc_html(absint($item['used_spots']) . '/' . absint($item['localmap_limit'])); ?> · <?php echo esc_html($this->format_money($item['posting_fee'], $item['currency'])); ?></span>
                            </a>
                        <?php endforeach; ?>
                        <?php $this->admin_pagination($boards_collection, 'lmm_board_page', ['board_id' => $selected_id, 'tab' => $active_tab]); ?>
                        <a class="button" href="<?php echo esc_url($this->admin_url(['board_id' => 0, 'tab' => 'settings'])); ?>"><?php esc_html_e('New campaign', 'mds-local-money-map'); ?></a>
                    </div>
                </section>

                <?php if ('settings' === $active_tab) : ?>
                <section class="mds3-card mds-local-money-map-panel">
                    <h2><?php echo $board ? esc_html__('Campaign settings', 'mds-local-money-map') : esc_html__('Create campaign', 'mds-local-money-map'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mds-local-money-map-form">
                        <?php wp_nonce_field('mds_local_money_map_save_board_' . absint($form_board['id'])); ?>
                        <input type="hidden" name="action" value="mds_local_money_map_save_board">
                        <input type="hidden" name="board_id" value="<?php echo esc_attr((string) $form_board['id']); ?>">

                        <?php $this->field('title', __('Title', 'mds-local-money-map'), $form_board['title'], 'text', true); ?>
                        <?php $this->field('slug', __('Slug', 'mds-local-money-map'), $form_board['slug'], 'text'); ?>
                        <?php $this->field('description', __('Description', 'mds-local-money-map'), $form_board['description'], 'textarea'); ?>
                        <?php $this->select('status', __('Status', 'mds-local-money-map'), $form_board['status'], ['draft' => __('Draft', 'mds-local-money-map'), 'active' => __('Active', 'mds-local-money-map'), 'paused' => __('Paused', 'mds-local-money-map'), 'archived' => __('Archived', 'mds-local-money-map')]); ?>
                        <?php $this->field('currency', __('Currency', 'mds-local-money-map'), $form_board['currency'], 'text', true, $currency_locked); ?>
                        <?php if ($currency_locked) : ?><p class="mds-local-money-map-note"><?php esc_html_e('The active payment provider controls currency for this site.', 'mds-local-money-map'); ?></p><?php endif; ?>
                        <?php $this->field('posting_fee', __('Spot fee', 'mds-local-money-map'), $form_board['posting_fee'], 'number'); ?>
                        <?php $this->field('featured_fee', __('Featured spot fee', 'mds-local-money-map'), $form_board['featured_fee'], 'number'); ?>
                        <?php $this->field('localmap_limit', __('Spot capacity', 'mds-local-money-map'), $form_board['localmap_limit'], 'number'); ?>
                        <?php $this->select('payment_mode', __('Payment mode', 'mds-local-money-map'), $form_board['payment_mode'], ['manual' => __('Manual or free spots', 'mds-local-money-map'), 'provider' => __('Use MDS payment provider', 'mds-local-money-map')]); ?>
                        <?php $this->field('contact_email', __('Contact email', 'mds-local-money-map'), $form_board['contact_email'], 'email'); ?>
                        <?php $this->field('retention_days', __('Private data retention days', 'mds-local-money-map'), $form_board['retention_days'], 'number'); ?>
                        <?php $this->field('submission_limit_per_hour', __('Public submissions per visitor per hour', 'mds-local-money-map'), $form_board['submission_limit_per_hour'], 'number'); ?>
                        <?php $this->field('terms_url', __('Terms URL', 'mds-local-money-map'), $form_board['terms_url'], 'url'); ?>
                        <?php $this->field('disclosure', __('Sponsored offer disclosure', 'mds-local-money-map'), $form_board['disclosure'], 'textarea'); ?>
                        <label class="mds-local-money-map-check"><input type="checkbox" name="map_enabled" value="1" <?php checked(!empty($form_board['map_enabled'])); ?>> <span><?php esc_html_e('Show the interactive location map', 'mds-local-money-map'); ?></span></label>
                        <p class="mds-local-money-map-note"><?php esc_html_e('The interactive map uses the OpenStreetMap tile service in visitors\' browsers and sends their IP address to that service. Disable it to show the accessible location list and map links without loading map tiles.', 'mds-local-money-map'); ?></p>
                        <label class="mds-local-money-map-check"><input type="checkbox" name="claims_enabled" value="1" <?php checked(!empty($form_board['claims_enabled'])); ?>> <span><?php esc_html_e('Accept public check-ins/submissions', 'mds-local-money-map'); ?></span></label>
                        <p class="mds-local-money-map-note"><?php esc_html_e('QR target links and SVG QR codes are generated locally without image libraries or external services. Very large print batches can still be offloaded later if a hosted service is connected.', 'mds-local-money-map'); ?></p>
                        <p class="submit"><button class="button button-primary" type="submit"><?php esc_html_e('Save campaign', 'mds-local-money-map'); ?></button></p>
                    </form>
                </section>
                <?php endif; ?>

            <?php if ($board && 'spots' === $active_tab) : ?>
                <section class="mds3-card mds-local-money-map-panel">
                    <div class="mds-local-money-map-panel-heading">
                        <div>
                            <h2><?php esc_html_e('Spots', 'mds-local-money-map'); ?></h2>
                            <p><?php esc_html_e('Download individual SVG QR codes or print a batch sheet for approved QR targets.', 'mds-local-money-map'); ?></p>
                        </div>
                        <?php if ($spots) : ?>
                            <div class="mds-local-money-map-qr-tools" aria-label="<?php esc_attr_e('QR print layouts', 'mds-local-money-map'); ?>">
                                <span><?php esc_html_e('Print QR sheet', 'mds-local-money-map'); ?></span>
                                <a class="button" href="<?php echo esc_url($this->qr_print_url($board['id'], 1)); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Large', 'mds-local-money-map'); ?></a>
                                <a class="button" href="<?php echo esc_url($this->qr_print_url($board['id'], 4)); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('4/page', 'mds-local-money-map'); ?></a>
                                <a class="button" href="<?php echo esc_url($this->qr_print_url($board['id'], 8)); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('8/page', 'mds-local-money-map'); ?></a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!$spots) : ?>
                        <p><?php esc_html_e('No spots yet.', 'mds-local-money-map'); ?></p>
                        <p><a class="button" href="<?php echo esc_url($this->admin_url(['board_id' => $board['id'], 'tab' => 'settings'])); ?>"><?php esc_html_e('Review campaign settings', 'mds-local-money-map'); ?></a></p>
                    <?php else : ?>
                        <div class="mds-local-money-map-submissions">
                            <?php foreach ($spots as $localmap) : ?>
                                <article>
                                    <div class="mds-local-money-map-spot-summary">
                                        <strong><?php echo esc_html($localmap['title']); ?></strong>
                                        <span><?php echo esc_html($this->status_label($localmap['status'])); ?> · <?php echo esc_html($this->status_label($localmap['payment_status'])); ?> · <?php echo esc_html($this->format_money($localmap['amount'], $localmap['currency'])); ?> · <?php echo esc_html(sprintf(_n('%d check-in', '%d check-ins', absint($localmap['claim_count']), 'mds-local-money-map'), absint($localmap['claim_count']))); ?></span>
                                        <p>
                                            <?php echo esc_html($localmap['category'] ?: wp_trim_words($localmap['description'], 18)); ?>
                                            <?php if (!empty($localmap['location_label'])) : ?>
                                                <span aria-hidden="true"> · </span><?php echo esc_html($localmap['location_label']); ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="mds-local-money-map-actions">
                                        <?php $this->localmap_button($localmap['id'], 'approve', __('Approve', 'mds-local-money-map')); ?>
                                        <?php $this->localmap_button($localmap['id'], 'reject', __('Reject', 'mds-local-money-map')); ?>
                                        <?php $this->localmap_button($localmap['id'], 'review', __('Mark in review', 'mds-local-money-map')); ?>
                                        <?php $this->localmap_button($localmap['id'], 'complete', __('Complete', 'mds-local-money-map')); ?>
                                        <?php $this->localmap_button($localmap['id'], 'archive', __('Archive', 'mds-local-money-map')); ?>
                                        <?php if (!empty($localmap['external_url'])) : ?>
                                            <a class="button" href="<?php echo esc_url($localmap['external_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open', 'mds-local-money-map'); ?></a>
                                        <?php endif; ?>
                                        <?php if (!empty($localmap['qr_url'])) : ?>
                                            <a class="button" href="<?php echo esc_url($localmap['qr_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('QR target', 'mds-local-money-map'); ?></a>
                                            <a class="button" href="<?php echo esc_url($this->qr_image_url($localmap['id'], true)); ?>"><?php esc_html_e('Download QR', 'mds-local-money-map'); ?></a>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($localmap['qr_url'])) : ?>
                                        <div class="mds-local-money-map-qr-preview">
                                            <img src="<?php echo esc_url($this->qr_image_url($localmap['id'])); ?>" alt="<?php echo esc_attr(sprintf(__('QR code for %s', 'mds-local-money-map'), $localmap['title'])); ?>" width="72" height="72" loading="lazy">
                                            <div>
                                                <strong><?php esc_html_e('Local QR code', 'mds-local-money-map'); ?></strong>
                                                <span><?php echo esc_html($localmap['qr_url']); ?></span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    <?php $claims = Repository::claims($localmap['id'], ['limit' => 3]); ?>
                                    <?php if ($claims) : ?>
                                        <div class="mds-local-money-map-claims">
                                            <?php foreach ($claims as $claim) : ?>
                                                <div>
                                                    <span><?php echo esc_html($claim['display_name']); ?> · <?php echo esc_html($this->status_label($claim['status'])); ?></span>
                                                    <p><?php echo esc_html(wp_trim_words($claim['proposal'], 24)); ?></p>
                                                    <div class="mds-local-money-map-actions">
                                                        <?php $this->claim_button($claim['id'], 'approve', __('Approve check-in', 'mds-local-money-map')); ?>
                                                        <?php $this->claim_button($claim['id'], 'reject', __('Reject check-in', 'mds-local-money-map')); ?>
                                                        <?php if (!empty($claim['work_url'])) : ?>
                                                            <a class="button" href="<?php echo esc_url($claim['work_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Work', 'mds-local-money-map'); ?></a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (absint($localmap['claim_count']) > 3) : ?>
                                        <a class="button mds-local-money-map-view-checkins" href="<?php echo esc_url($this->admin_url(['board_id' => $board['id'], 'tab' => 'spots', 'lmm_spot_page' => $spot_page, 'lmm_checkin_spot' => $localmap['id']])); ?>"><?php echo esc_html(sprintf(__('View all %d check-ins', 'mds-local-money-map'), absint($localmap['claim_count']))); ?></a>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <?php $this->admin_pagination($spots_collection, 'lmm_spot_page', ['board_id' => $board['id'], 'tab' => 'spots']); ?>
                    <?php endif; ?>
                </section>

                <?php if ($checkin_spot) : ?>
                    <section class="mds3-card mds-local-money-map-panel mds-local-money-map-wide">
                        <div class="mds-local-money-map-panel-heading">
                            <div>
                                <h2><?php echo esc_html(sprintf(__('Check-ins for %s', 'mds-local-money-map'), $checkin_spot['title'])); ?></h2>
                                <p><?php echo esc_html(sprintf(_n('%d check-in', '%d check-ins', absint($checkins_collection['total']), 'mds-local-money-map'), absint($checkins_collection['total']))); ?></p>
                            </div>
                            <a class="button" href="<?php echo esc_url($this->admin_url(['board_id' => $board['id'], 'tab' => 'spots', 'lmm_spot_page' => $spot_page])); ?>"><?php esc_html_e('Close check-ins', 'mds-local-money-map'); ?></a>
                        </div>
                        <div class="mds-local-money-map-claims mds-local-money-map-claims-all">
                            <?php foreach ($checkins_collection['items'] as $claim) : ?>
                                <div>
                                    <span><?php echo esc_html($claim['display_name']); ?> · <?php echo esc_html($this->status_label($claim['status'])); ?> · <?php echo esc_html($claim['email']); ?></span>
                                    <p><?php echo esc_html($claim['proposal']); ?></p>
                                    <div class="mds-local-money-map-actions">
                                        <?php $this->claim_button($claim['id'], 'approve', __('Approve check-in', 'mds-local-money-map')); ?>
                                        <?php $this->claim_button($claim['id'], 'reject', __('Reject check-in', 'mds-local-money-map')); ?>
                                        <?php if (!empty($claim['work_url'])) : ?><a class="button" href="<?php echo esc_url($claim['work_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open proof', 'mds-local-money-map'); ?></a><?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php $this->admin_pagination($checkins_collection, 'lmm_checkin_page', ['board_id' => $board['id'], 'tab' => 'spots', 'lmm_spot_page' => $spot_page, 'lmm_checkin_spot' => $checkin_spot_id]); ?>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function handle_save_board() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to save localmap boards.', 'mds-local-money-map'));
        }

        $board_id = absint($_POST['board_id'] ?? 0);
        check_admin_referer('mds_local_money_map_save_board_' . $board_id);
        $saved = Repository::save_board([
            'title' => wp_unslash($_POST['title'] ?? ''),
            'slug' => wp_unslash($_POST['slug'] ?? ''),
            'description' => wp_unslash($_POST['description'] ?? ''),
            'status' => wp_unslash($_POST['status'] ?? 'draft'),
            'currency' => Repository::currency_locked() ? Repository::default_currency() : wp_unslash($_POST['currency'] ?? Repository::default_currency()),
            'posting_fee' => wp_unslash($_POST['posting_fee'] ?? 0),
            'featured_fee' => wp_unslash($_POST['featured_fee'] ?? 0),
            'localmap_limit' => wp_unslash($_POST['localmap_limit'] ?? 50),
            'payment_mode' => wp_unslash($_POST['payment_mode'] ?? 'manual'),
            'claims_enabled' => !empty($_POST['claims_enabled']) ? '1' : '0',
            'map_enabled' => !empty($_POST['map_enabled']) ? '1' : '0',
            'disclosure' => wp_unslash($_POST['disclosure'] ?? ''),
            'terms_url' => wp_unslash($_POST['terms_url'] ?? ''),
            'contact_email' => wp_unslash($_POST['contact_email'] ?? ''),
            'retention_days' => wp_unslash($_POST['retention_days'] ?? 365),
            'submission_limit_per_hour' => wp_unslash($_POST['submission_limit_per_hour'] ?? 10),
        ], $board_id);

        $this->redirect(['board_id' => $saved ?: $board_id, 'tab' => 'settings', 'mds_lmm_status' => $saved ? 'saved' : 'error']);
    }

    public function handle_moderate_localmap() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to moderate spots.', 'mds-local-money-map'));
        }

        $localmap_id = absint($_POST['localmap_id'] ?? 0);
        check_admin_referer('mds_local_money_map_moderate_localmap_' . $localmap_id);
        $localmap = Repository::localmap($localmap_id);
        $status = sanitize_key((string) ($_POST['moderation'] ?? ''));
        $ok = $localmap && Repository::update_localmap_status($localmap_id, $status);
        if ($ok) {
            $this->notify_spot_moderation(Repository::board(absint($localmap['board_id'] ?? 0)), Repository::localmap($localmap_id));
        }
        $this->safe_redirect(wp_get_referer() ?: $this->admin_url(['board_id' => absint($localmap['board_id'] ?? 0)]), [
            'mds_lmm_status' => $ok ? 'moderated' : 'error',
        ]);
    }

    public function handle_moderate_claim() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to moderate check-ins.', 'mds-local-money-map'));
        }

        $claim_id = absint($_POST['claim_id'] ?? 0);
        check_admin_referer('mds_local_money_map_moderate_claim_' . $claim_id);
        $claim = Repository::claim($claim_id);
        $status = sanitize_key((string) ($_POST['moderation'] ?? ''));
        $ok = $claim && Repository::update_claim_status($claim_id, $status);
        if ($ok) {
            $this->notify_claim_moderation(Repository::claim($claim_id));
        }
        $this->safe_redirect(wp_get_referer() ?: $this->admin_url(['board_id' => absint($claim['board_id'] ?? 0)]), [
            'mds_lmm_status' => $ok ? 'claim_moderated' : 'error',
        ]);
    }

    public function handle_submit_localmap() {
        $board_id = absint($_POST['board_id'] ?? 0);
        check_admin_referer('mds_local_money_map_submit_localmap_' . $board_id);
        $board = Repository::board($board_id);
        $referrer = esc_url_raw(wp_unslash($_POST['_wp_http_referer'] ?? wp_get_referer() ?: home_url('/')));
        if (!$board || 'active' !== (string) ($board['status'] ?? '') || !Repository::has_open_localmap_slots($board_id)) {
            $this->safe_redirect($referrer, ['localmap_engine_status' => 'closed']);
        }
        if (!empty($_POST['company'])) {
            $this->safe_redirect($referrer, ['localmap_engine_status' => 'submitted']);
        }
        $data = [
            'title' => wp_unslash($_POST['title'] ?? ''),
            'description' => wp_unslash($_POST['description'] ?? ''),
            'requester_name' => wp_unslash($_POST['requester_name'] ?? ''),
            'requester_email' => wp_unslash($_POST['requester_email'] ?? ''),
            'category' => wp_unslash($_POST['category'] ?? ''),
            'location_label' => wp_unslash($_POST['location_label'] ?? ''),
            'latitude' => wp_unslash($_POST['latitude'] ?? ''),
            'longitude' => wp_unslash($_POST['longitude'] ?? ''),
            'reward_label' => wp_unslash($_POST['reward_label'] ?? ''),
            'external_url' => wp_unslash($_POST['external_url'] ?? ''),
            'due_at' => wp_unslash($_POST['due_at'] ?? ''),
            'featured' => !empty($_POST['featured']) ? '1' : '0',
            'terms_accepted' => !empty($_POST['terms_accepted']) ? '1' : '0',
        ];
        $validation = Repository::validate_localmap_submission($board_id, $data);
        if (is_wp_error($validation)) {
            $this->safe_redirect($referrer, ['localmap_engine_status' => $this->validation_status($validation)]);
        }
        if ($this->rate_limited('submission_' . $board_id, absint($board['submission_limit_per_hour'] ?? 10), HOUR_IN_SECONDS)) {
            $this->safe_redirect($referrer, ['localmap_engine_status' => 'rate_limited']);
        }

        $localmap_id = Repository::create_localmap($board_id, $data);
        if (!$localmap_id) {
            $this->safe_redirect($referrer, ['localmap_engine_status' => 'failed']);
        }

        $localmap = Repository::localmap($localmap_id);
        $this->notify_spot_submission($board, $localmap);
        if ($this->requires_checkout($board, $localmap)) {
            try {
                $checkout = $this->start_checkout($board, $localmap, $referrer);
            } catch (\Throwable $error) {
                $checkout = new \WP_Error('mds_lmm_checkout_failed', $error->getMessage());
            }
            if (is_array($checkout) && !empty($checkout['checkout_url'])) {
                $this->checkout_redirect((string) $checkout['checkout_url'], $referrer);
            }
            Repository::update_payment_status($localmap_id, 'failed');
            $this->safe_redirect($referrer, ['localmap_engine_status' => 'checkout_failed']);
        }

        $this->safe_redirect($referrer, ['localmap_engine_status' => 'submitted']);
    }

    public function handle_submit_claim() {
        $localmap_id = absint($_POST['localmap_id'] ?? 0);
        check_admin_referer('mds_local_money_map_submit_claim_' . $localmap_id);
        $localmap = Repository::localmap($localmap_id);
        $board = $localmap ? Repository::board(absint($localmap['board_id'] ?? 0)) : null;
        $referrer = esc_url_raw(wp_unslash($_POST['_wp_http_referer'] ?? wp_get_referer() ?: home_url('/')));
        if (!$localmap || !$board || empty($board['claims_enabled'])) {
            $this->safe_redirect($referrer, ['localmap_engine_status' => 'claims_closed']);
        }
        if (!empty($_POST['company'])) {
            $this->safe_redirect($referrer, ['localmap_engine_status' => 'claim_submitted']);
        }
        $data = [
            'display_name' => wp_unslash($_POST['display_name'] ?? ''),
            'email' => wp_unslash($_POST['email'] ?? ''),
            'proposal' => wp_unslash($_POST['proposal'] ?? ''),
            'work_url' => wp_unslash($_POST['work_url'] ?? ''),
            'terms_accepted' => !empty($_POST['terms_accepted']) ? '1' : '0',
        ];
        $validation = Repository::validate_claim_submission($localmap_id, $data);
        if (is_wp_error($validation)) {
            $this->safe_redirect($referrer, ['localmap_engine_status' => $this->validation_status($validation)]);
        }
        if ($this->rate_limited('submission_' . absint($board['id'] ?? 0), absint($board['submission_limit_per_hour'] ?? 10), HOUR_IN_SECONDS)) {
            $this->safe_redirect($referrer, ['localmap_engine_status' => 'rate_limited']);
        }

        $claim_id = Repository::create_claim($localmap_id, $data);
        if ($claim_id) {
            $this->notify_claim_submission($board, $localmap, Repository::claim($claim_id));
        }

        $this->safe_redirect($referrer, ['localmap_engine_status' => $claim_id ? 'claim_submitted' : 'failed']);
    }

    public function handle_qr_download() {
        $localmap_id = absint($_GET['spot_id'] ?? 0);
        $localmap = Repository::localmap($localmap_id);
        if (!$localmap || empty($localmap['qr_url'])) {
            status_header(404);
            exit;
        }

        $public = $this->localmap_qr_public($localmap);
        if (!$public && !$this->can_manage_qr($localmap_id)) {
            status_header(403);
            exit;
        }

        $svg = QrCode::svg((string) $localmap['qr_url'], 10, 4, sprintf(__('QR code for %s', 'mds-local-money-map'), $localmap['title']));
        if (is_wp_error($svg)) {
            status_header(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo esc_html($svg->get_error_message());
            exit;
        }

        $download = !empty($_GET['download']);
        if ($public && !$download) {
            header('Cache-Control: public, max-age=3600');
        } else {
            nocache_headers();
        }
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        if ($download) {
            $filename = sanitize_title((string) ($localmap['title'] ?? 'local-money-map-qr')) ?: 'local-money-map-qr';
            header('Content-Disposition: attachment; filename="' . $filename . '-qr.svg"');
        }

        echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public function handle_qr_print() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to print Local Money Map QR codes.', 'mds-local-money-map'));
        }

        $board_id = absint($_GET['board_id'] ?? 0);
        check_admin_referer('mds_local_money_map_qr_print_' . $board_id);
        $board = Repository::board($board_id);
        if (!$board) {
            wp_die(esc_html__('Local Money Map campaign not found.', 'mds-local-money-map'));
        }

        $per_page = absint($_GET['per_page'] ?? 4);
        if (!in_array($per_page, [1, 4, 8], true)) {
            $per_page = 4;
        }

        $batch = max(1, absint($_GET['batch'] ?? 1));
        $collection = Repository::spots_page($board_id, $batch, 100, true);
        $spots = array_values(array_filter($collection['items'], function ($localmap) {
            return !empty($localmap['qr_url']);
        }));

        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html(sprintf(__('QR sheet - %s', 'mds-local-money-map'), $board['title'])); ?></title>
            <style>
                :root { color: #111827; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
                body { margin: 24px; background: #f8fafc; }
                .toolbar { align-items: center; display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; margin: 0 0 18px; }
                .toolbar h1 { font-size: 22px; margin: 0; }
                .toolbar p { color: #475569; margin: 4px 0 0; }
                .toolbar-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; }
                .toolbar a, .toolbar button { background: #fff; border: 1px solid #94a3b8; border-radius: 5px; color: #111827; min-height: 34px; padding: 7px 14px; text-decoration: none; }
                .toolbar button { min-height: 34px; padding: 0 14px; }
                .sheet { display: grid; gap: 16px; grid-template-columns: repeat(<?php echo esc_html((string) min(4, $per_page)); ?>, minmax(0, 1fr)); }
                .qr-card { break-inside: avoid; background: #fff; border: 1px solid #dbe3ef; border-radius: 8px; display: grid; gap: 10px; padding: 14px; page-break-inside: avoid; text-align: center; }
                .qr-card img { height: auto; justify-self: center; max-width: 100%; width: <?php echo 1 === $per_page ? '320px' : '160px'; ?>; }
                .qr-card strong { overflow-wrap: anywhere; }
                .qr-card span { color: #475569; display: block; font-size: 12px; overflow-wrap: anywhere; }
                .empty { background: #fff; border: 1px solid #dbe3ef; border-radius: 8px; padding: 16px; }
                @media (max-width: 760px) { .sheet { grid-template-columns: 1fr; } body { margin: 14px; } }
                @media print {
                    body { margin: 0; background: #fff; }
                    .toolbar { display: none; }
                    .sheet { gap: 8mm; grid-template-columns: repeat(<?php echo esc_html((string) (8 === $per_page ? 2 : (4 === $per_page ? 2 : 1))); ?>, minmax(0, 1fr)); }
                    .qr-card { border-color: #bbb; box-shadow: none; min-height: <?php echo 1 === $per_page ? '230mm' : (4 === $per_page ? '120mm' : '62mm'); ?>; }
                    .qr-card img { width: <?php echo 1 === $per_page ? '85mm' : (4 === $per_page ? '45mm' : '30mm'); ?>; }
                }
            </style>
        </head>
        <body>
            <div class="toolbar">
                <div>
                    <h1><?php echo esc_html($board['title']); ?></h1>
                    <p><?php echo esc_html(sprintf(_n('%1$d printable QR code - batch %2$d of %3$d', '%1$d printable QR codes - batch %2$d of %3$d', count($spots), 'mds-local-money-map'), count($spots), absint($collection['page']), absint($collection['pages']))); ?></p>
                </div>
                <div class="toolbar-actions">
                    <?php if (absint($collection['page']) > 1) : ?><a href="<?php echo esc_url($this->qr_print_url($board_id, $per_page, absint($collection['page']) - 1)); ?>"><?php esc_html_e('Previous batch', 'mds-local-money-map'); ?></a><?php endif; ?>
                    <?php if (absint($collection['page']) < absint($collection['pages'])) : ?><a href="<?php echo esc_url($this->qr_print_url($board_id, $per_page, absint($collection['page']) + 1)); ?>"><?php esc_html_e('Next batch', 'mds-local-money-map'); ?></a><?php endif; ?>
                    <button type="button" onclick="window.print()"><?php esc_html_e('Print', 'mds-local-money-map'); ?></button>
                </div>
            </div>
            <?php if (!$spots) : ?>
                <p class="empty"><?php esc_html_e('No approved QR targets are available to print yet.', 'mds-local-money-map'); ?></p>
            <?php else : ?>
                <main class="sheet">
                    <?php foreach ($spots as $localmap) : ?>
                        <article class="qr-card">
                            <img src="<?php echo esc_url($this->qr_image_url($localmap['id'])); ?>" alt="<?php echo esc_attr(sprintf(__('QR code for %s', 'mds-local-money-map'), $localmap['title'])); ?>">
                            <strong><?php echo esc_html($localmap['title']); ?></strong>
                            <?php if (!empty($localmap['reward_label'])) : ?><span><?php echo esc_html($localmap['reward_label']); ?></span><?php endif; ?>
                            <span><?php echo esc_html($localmap['qr_url']); ?></span>
                        </article>
                    <?php endforeach; ?>
                </main>
            <?php endif; ?>
        </body>
        </html>
        <?php
        exit;
    }

    public function shortcode($atts) {
        $atts = shortcode_atts([
            'id' => 0,
            'slug' => '',
            'show_spot_form' => 'true',
            'show_checkin_forms' => 'true',
            'show_notice' => 'true',
        ], $atts, 'mds_local_money_map');
        $show_spot_form = $this->truthy($atts['show_spot_form']);
        $show_checkin_forms = $this->truthy($atts['show_checkin_forms']);
        $show_notice = $this->truthy($atts['show_notice']);
        if (absint($atts['id'])) {
            $board = Repository::board(absint($atts['id']));
        } elseif ('' !== (string) $atts['slug']) {
            $board = Repository::board_by_slug((string) $atts['slug']);
        } else {
            $board = $this->first_active_board();
        }
        if (!$board || 'active' !== (string) ($board['status'] ?? '')) {
            return current_user_can('manage_options') ? '<p class="mds-local-money-map-empty">' . esc_html__('Local Money Map campaign is not active.', 'mds-local-money-map') . '</p>' : '';
        }

        $board['currency'] = Repository::effective_currency($board['currency'] ?? '');
        $page_key = 'mds_lmm_page_' . absint($board['id']);
        $public_page = max(1, absint($_GET[$page_key] ?? 1));
        $spots_collection = Repository::spots_page($board['id'], $public_page, 12, true);
        $spots = $spots_collection['items'];
        $map_spots = [];
        foreach ($spots as $spot) {
            if (null === ($spot['latitude'] ?? null) || null === ($spot['longitude'] ?? null)) {
                continue;
            }
            $map_spots[] = [
                'id' => absint($spot['id'] ?? 0),
                'title' => (string) ($spot['title'] ?? ''),
                'location' => (string) ($spot['location_label'] ?? ''),
                'latitude' => (float) $spot['latitude'],
                'longitude' => (float) $spot['longitude'],
            ];
        }
        if (!empty($board['map_enabled']) && $map_spots) {
            wp_enqueue_style('million-dollar-script-openlayers');
            wp_enqueue_script('mds-local-money-map-map');
        }
        wp_enqueue_style('mds-local-money-map');
        ob_start();
        ?>
        <section class="mds-local-money-map" data-board-id="<?php echo esc_attr((string) $board['id']); ?>">
            <header class="mds-local-money-map__header">
                <span><?php esc_html_e('Local Money Map', 'mds-local-money-map'); ?></span>
                <h2><?php echo esc_html($board['title']); ?></h2>
                <?php if (!empty($board['description'])) : ?>
                    <p><?php echo esc_html($board['description']); ?></p>
                <?php endif; ?>
                <div class="mds-local-money-map__summary">
                    <span><?php echo esc_html(sprintf(_n('%d sponsor spot published', '%d sponsor spots published', absint($spots_collection['total']), 'mds-local-money-map'), absint($spots_collection['total']))); ?></span>
                    <span><?php echo esc_html(sprintf(__('%1$d of %2$d campaign places used', 'mds-local-money-map'), absint($board['used_spots']), absint($board['localmap_limit']))); ?></span>
                    <span><?php echo esc_html(sprintf(__('Spot fee %s', 'mds-local-money-map'), $this->format_money($board['posting_fee'], $board['currency']))); ?></span>
                    <span><?php echo esc_html(sprintf(__('Featured +%s', 'mds-local-money-map'), $this->format_money($board['featured_fee'], $board['currency']))); ?></span>
                </div>
                <?php if (!empty($board['disclosure'])) : ?>
                    <small><?php echo esc_html($board['disclosure']); ?></small>
                <?php endif; ?>
            </header>

            <?php if ($show_notice) : ?>
                <?php $this->public_notice(); ?>
            <?php endif; ?>

            <?php if (!empty($board['map_enabled']) && $map_spots) : ?>
                <section class="mds-local-money-map__map-region" aria-labelledby="mds-lmm-map-title-<?php echo esc_attr((string) absint($board['id'])); ?>">
                    <div class="mds-local-money-map__map-heading">
                        <h3 id="mds-lmm-map-title-<?php echo esc_attr((string) absint($board['id'])); ?>"><?php esc_html_e('Sponsor spot map', 'mds-local-money-map'); ?></h3>
                        <span><?php echo esc_html(sprintf(_n('%d mapped location', '%d mapped locations', count($map_spots), 'mds-local-money-map'), count($map_spots))); ?></span>
                    </div>
                    <div id="mds-lmm-map-<?php echo esc_attr((string) absint($board['id'])); ?>-<?php echo esc_attr((string) $public_page); ?>" class="mds-local-money-map__map" data-mds-local-money-map role="region" aria-label="<?php esc_attr_e('Interactive sponsor spot map', 'mds-local-money-map'); ?>" tabindex="0">
                        <p><?php esc_html_e('Interactive map loading. Sponsor locations are also listed below.', 'mds-local-money-map'); ?></p>
                    </div>
                    <script type="application/json" class="mds-local-money-map__map-data"><?php echo wp_json_encode($map_spots, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
                </section>
            <?php endif; ?>

            <div class="mds-local-money-map__grid">
                <?php foreach ($spots as $localmap) : ?>
                    <article id="mds-lmm-spot-<?php echo esc_attr((string) absint($localmap['id'])); ?>" class="mds-local-money-map__card is-<?php echo esc_attr((string) ($localmap['status'] ?? 'open')); ?> <?php echo !empty($localmap['featured']) ? 'is-featured' : ''; ?>" tabindex="-1">
                        <div class="mds-local-money-map__meta">
                            <?php if (!empty($localmap['featured'])) : ?><span><?php esc_html_e('Featured', 'mds-local-money-map'); ?></span><?php endif; ?>
                            <span><?php echo esc_html(ucwords(str_replace('_', ' ', (string) $localmap['status']))); ?></span>
                            <?php if (!empty($localmap['category'])) : ?><span><?php echo esc_html($localmap['category']); ?></span><?php endif; ?>
                        </div>
                        <h3><?php echo esc_html($localmap['title']); ?></h3>
                        <?php if (!empty($localmap['location_label'])) : ?>
                            <p class="mds-local-money-map__location"><strong><?php esc_html_e('Location:', 'mds-local-money-map'); ?></strong> <?php echo esc_html($localmap['location_label']); ?> <a href="<?php echo esc_url($this->map_search_url($localmap)); ?>" target="_blank" rel="nofollow noopener noreferrer"><?php esc_html_e('Open map', 'mds-local-money-map'); ?></a></p>
                        <?php endif; ?>
                        <p><?php echo esc_html($localmap['description']); ?></p>
                        <?php if (!empty($localmap['reward_label'])) : ?><p class="mds-local-money-map__price"><?php echo esc_html($localmap['reward_label']); ?></p><?php endif; ?>
                        <div class="mds-local-money-map__meta">
                            <?php if (!empty($localmap['due_at'])) : ?><span><?php echo esc_html(sprintf(__('Event date %s', 'mds-local-money-map'), $this->format_date($localmap['due_at']))); ?></span><?php endif; ?>
                            <span><?php echo esc_html(sprintf(_n('%d check-in', '%d check-ins', absint($localmap['claim_count']), 'mds-local-money-map'), absint($localmap['claim_count']))); ?></span>
                        </div>
                        <?php if (!empty($localmap['external_url'])) : ?>
                            <a href="<?php echo esc_url($localmap['external_url']); ?>" rel="nofollow sponsored noopener" target="_blank"><?php esc_html_e('Open details', 'mds-local-money-map'); ?></a>
                        <?php endif; ?>
                        <?php if (!empty($localmap['qr_url'])) : ?>
                            <div class="mds-local-money-map__qr">
                                <img src="<?php echo esc_url($this->qr_image_url($localmap['id'])); ?>" alt="<?php echo esc_attr(sprintf(__('QR code for %s', 'mds-local-money-map'), $localmap['title'])); ?>" width="96" height="96" loading="lazy">
                                <div>
                                    <a href="<?php echo esc_url($localmap['qr_url']); ?>" rel="nofollow sponsored noopener" target="_blank"><?php esc_html_e('QR target link', 'mds-local-money-map'); ?></a>
                                    <a href="<?php echo esc_url($this->qr_image_url($localmap['id'], true)); ?>"><?php esc_html_e('Download QR', 'mds-local-money-map'); ?></a>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($show_checkin_forms && !empty($board['claims_enabled']) && 'completed' !== (string) ($localmap['status'] ?? '')) : ?>
                            <?php $this->claim_form($board, $localmap); ?>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php $this->public_pagination($spots_collection, $page_key); ?>

            <?php if (empty($spots)) : ?>
                <p class="mds-local-money-map__empty"><?php esc_html_e('Approved sponsor spots will appear here after moderation.', 'mds-local-money-map'); ?></p>
            <?php endif; ?>

            <?php if ($show_spot_form && Repository::has_open_localmap_slots(absint($board['id']))) : ?>
                <?php $this->localmap_form($board); ?>
            <?php endif; ?>
        </section>
        <?php
        return trim((string) ob_get_clean());
    }

    private function truthy($value) {
        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off'], true);
    }

    public function register_rest_routes() {
        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/boards', [[
            'methods' => 'GET',
            'callback' => function ($request) {
                $collection = Repository::boards_page(absint($request['page'] ?? 1), absint($request['per_page'] ?? 20), true);
                $collection['items'] = array_values(array_filter(array_map([Repository::class, 'public_board_payload'], $collection['items'])));
                return rest_ensure_response([
                    'boards' => $collection['items'],
                    'pagination' => array_diff_key($collection, ['items' => true]),
                ]);
            },
            'permission_callback' => '__return_true',
            'args' => $this->pagination_args(),
        ]]);

        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/boards/(?P<id>\d+)/spots', [[
            'methods' => 'GET',
            'callback' => function ($request) {
                $board_id = absint($request['id'] ?? 0);
                $board = Repository::board($board_id);
                if (!$board || 'active' !== (string) ($board['status'] ?? '')) {
                    return new \WP_Error('mds_lmm_board_not_found', __('Active Local Money Map campaign not found.', 'mds-local-money-map'), ['status' => 404]);
                }
                $collection = Repository::spots_page($board_id, absint($request['page'] ?? 1), absint($request['per_page'] ?? 20), true);
                $collection['items'] = array_values(array_filter(array_map([Repository::class, 'public_spot_payload'], $collection['items'])));
                return rest_ensure_response([
                    'board' => Repository::public_board_payload($board),
                    'spots' => $collection['items'],
                    'pagination' => array_diff_key($collection, ['items' => true]),
                ]);
            },
            'permission_callback' => '__return_true',
            'args' => array_merge([
                'id' => ['required' => true, 'sanitize_callback' => 'absint'],
            ], $this->pagination_args()),
        ]]);

        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/spots', [[
            'methods' => 'POST',
            'callback' => function ($request) {
                $board_id = absint($request['board_id'] ?? 0);
                $params = (array) $request->get_json_params();
                $validation = Repository::validate_localmap_submission($board_id, $params);
                if (is_wp_error($validation)) {
                    $validation->add_data(['status' => 400]);
                    return $validation;
                }
                $localmap_id = Repository::create_localmap($board_id, $params);
                if (!$localmap_id) {
                    return new \WP_Error('mds_local_money_map_create_failed', __('Sponsor spot could not be created.', 'mds-local-money-map'), ['status' => 400]);
                }
                $board = Repository::board($board_id);
                $localmap = Repository::localmap($localmap_id);
                $this->notify_spot_submission($board, $localmap);
                $response = ['localmap' => $localmap];
                if ($this->requires_checkout($board, $localmap)) {
                    try {
                        $checkout = $this->start_checkout($board, $localmap, home_url('/'));
                    } catch (\Throwable $error) {
                        $checkout = new \WP_Error('mds_lmm_checkout_failed', $error->getMessage());
                    }
                    if (!is_array($checkout) || empty($checkout['checkout_url'])) {
                        Repository::update_payment_status($localmap_id, 'failed');
                        return new \WP_Error('mds_lmm_checkout_failed', __('The sponsor spot was saved, but payment checkout could not be started. No charge was made.', 'mds-local-money-map'), ['status' => 502, 'spot_id' => $localmap_id]);
                    }
                    $response['checkout_url'] = esc_url_raw((string) $checkout['checkout_url']);
                }

                return rest_ensure_response($response);
            },
            'permission_callback' => [$this, 'permission_manage'],
        ]]);

        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/spots/(?P<id>\d+)/moderate', [[
            'methods' => 'POST',
            'callback' => function ($request) {
                $localmap_id = absint($request['id'] ?? 0);
                $status = sanitize_key((string) ($request['status'] ?? ''));
                if (!Repository::update_localmap_status($localmap_id, $status)) {
                    return new \WP_Error('mds_local_money_map_moderate_failed', __('Sponsor spot could not be moderated.', 'mds-local-money-map'), ['status' => 400]);
                }
                $localmap = Repository::localmap($localmap_id);
                $this->notify_spot_moderation(Repository::board(absint($localmap['board_id'] ?? 0)), $localmap);
                return rest_ensure_response(['localmap' => $localmap]);
            },
            'permission_callback' => [$this, 'permission_manage'],
        ]]);

        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/claims', [[
            'methods' => 'POST',
            'callback' => function ($request) {
                $params = (array) $request->get_json_params();
                $localmap_id = absint($params['localmap_id'] ?? 0);
                $validation = Repository::validate_claim_submission($localmap_id, $params);
                if (is_wp_error($validation)) {
                    $validation->add_data(['status' => 400]);
                    return $validation;
                }
                $claim_id = Repository::create_claim($localmap_id, $params);
                if (!$claim_id) {
                    return new \WP_Error('mds_local_money_map_claim_failed', __('Check-in could not be created.', 'mds-local-money-map'), ['status' => 400]);
                }
                $claim = Repository::claim($claim_id);
                $localmap = Repository::localmap($localmap_id);
                $this->notify_claim_submission(Repository::board(absint($localmap['board_id'] ?? 0)), $localmap, $claim);
                return rest_ensure_response(['claim' => $claim]);
            },
            'permission_callback' => [$this, 'permission_manage'],
        ]]);

        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/claims/(?P<id>\d+)/moderate', [[
            'methods' => 'POST',
            'callback' => function ($request) {
                $claim_id = absint($request['id'] ?? 0);
                $status = sanitize_key((string) ($request['status'] ?? ''));
                if (!Repository::update_claim_status($claim_id, $status)) {
                    return new \WP_Error('mds_local_money_map_claim_moderate_failed', __('Check-in could not be moderated.', 'mds-local-money-map'), ['status' => 400]);
                }
                $claim = Repository::claim($claim_id);
                $this->notify_claim_moderation($claim);
                return rest_ensure_response(['claim' => $claim]);
            },
            'permission_callback' => [$this, 'permission_manage'],
        ]]);
    }

    public function permission_manage() {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support')) {
            return \MillionDollarScript\Extensions\Support::can_manage_api();
        }

        return current_user_can('manage_options');
    }

    public function handle_payment_status($source, $source_id, $status, $context = []) {
        if ('local-money-map' !== sanitize_key((string) $source)) {
            return;
        }

        $status = sanitize_key((string) $status);
        if (in_array($status, ['paid', 'completed', 'processing'], true)) {
            Repository::update_payment_status(absint($source_id), 'paid');
            return;
        }

        if (in_array($status, ['failed', 'cancelled', 'expired', 'refunded'], true)) {
            Repository::update_payment_status(absint($source_id), $status);
        }
    }

    public function handle_subscription_term($source, $source_id, $context = [], $result = null) {
        unset($result);
        if ('local-money-map' !== sanitize_key((string) $source) || !Repository::localmap(absint($source_id))) {
            return;
        }
        $subscription = is_array($context['subscription'] ?? null) ? $context['subscription'] : [];
        $period_end = sanitize_text_field((string) ($subscription['current_period_end'] ?? ''));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $period_end, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $period_end) {
            return;
        }
        $current = (string) get_post_meta(absint($source_id), '_mds_lmm_subscription_expires_at', true);
        if (!$current || $period_end > $current) {
            update_post_meta(absint($source_id), '_mds_lmm_subscription_expires_at', $period_end);
        }
        update_post_meta(absint($source_id), '_mds_lmm_subscription_public_id', sanitize_text_field((string) ($subscription['public_id'] ?? '')));
    }

    public function process_privacy_cleanup() {
        Retention::run();
    }

    public function api_endpoint_manifest($manifest) {
        $manifest = is_array($manifest) ? $manifest : [];
        $manifest[] = ['id' => 'local-money-map-boards-read', 'route' => '/million-dollar-script/v1/local-money-map/boards', 'methods' => ['GET'], 'scope' => 'localmap.read', 'minimum_security_level' => 'public_read', 'description' => __('Read public local sponsorship boards without private contact or operational fields.', 'mds-local-money-map')];
        $manifest[] = ['id' => 'local-money-map-spots-read', 'route' => '/million-dollar-script/v1/local-money-map/boards/(?P<id>\d+)/spots', 'methods' => ['GET'], 'scope' => 'localmap.read', 'minimum_security_level' => 'public_read', 'description' => __('Read approved public local spots without requester contact or payment records.', 'mds-local-money-map')];
        $manifest[] = ['id' => 'local-money-map-spots-create', 'route' => '/million-dollar-script/v1/local-money-map/spots', 'methods' => ['POST'], 'scope' => 'localmap.write', 'minimum_security_level' => 'wp_capability', 'description' => __('Create local spot records as an administrator.', 'mds-local-money-map')];
        $manifest[] = ['id' => 'local-money-map-spots-moderate', 'route' => '/million-dollar-script/v1/local-money-map/spots/(?P<id>\d+)/moderate', 'methods' => ['POST'], 'scope' => 'localmap.write', 'minimum_security_level' => 'wp_capability', 'description' => __('Moderate local spot records as an administrator.', 'mds-local-money-map')];
        $manifest[] = ['id' => 'local-money-map-claims-create', 'route' => '/million-dollar-script/v1/local-money-map/claims', 'methods' => ['POST'], 'scope' => 'localmap.write', 'minimum_security_level' => 'wp_capability', 'description' => __('Create private local check-in records as an administrator.', 'mds-local-money-map')];
        $manifest[] = ['id' => 'local-money-map-claims-moderate', 'route' => '/million-dollar-script/v1/local-money-map/claims/(?P<id>\d+)/moderate', 'methods' => ['POST'], 'scope' => 'localmap.write', 'minimum_security_level' => 'wp_capability', 'description' => __('Moderate private local check-in records as an administrator.', 'mds-local-money-map')];

        return $manifest;
    }

    public function privacy_exporters($exporters) {
        $exporters['mds-local-money-map'] = [
            'exporter_friendly_name' => __('Local Money Map', 'mds-local-money-map'),
            'callback' => [$this, 'privacy_exporter'],
        ];

        return $exporters;
    }

    public function privacy_exporter($email_address, $page = 1) {
        return Privacy::exporter($email_address, $page);
    }

    public function privacy_erasers($erasers) {
        $erasers['mds-local-money-map'] = [
            'eraser_friendly_name' => __('Local Money Map', 'mds-local-money-map'),
            'callback' => [$this, 'privacy_eraser'],
        ];

        return $erasers;
    }

    public function privacy_eraser($email_address, $page = 1) {
        return Privacy::eraser($email_address, $page);
    }

    private function localmap_form(array $board) {
        ?>
        <details class="mds-local-money-map__submit">
            <summary><?php esc_html_e('Add a sponsor spot', 'mds-local-money-map'); ?></summary>
            <p class="mds-local-money-map__form-note"><?php esc_html_e('Submit a local sponsor spot for moderation. QR target links are created after approval and point to the sponsor URL you provide.', 'mds-local-money-map'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('mds_local_money_map_submit_localmap_' . absint($board['id'])); ?>
                <input type="hidden" name="action" value="mds_local_money_map_submit_localmap">
                <input type="hidden" name="board_id" value="<?php echo esc_attr((string) $board['id']); ?>">
                <input type="text" name="company" value="" tabindex="-1" autocomplete="off" class="mds-local-money-map__trap" aria-hidden="true">
                <label><span><?php esc_html_e('Business or stop name', 'mds-local-money-map'); ?></span><input type="text" name="title" required maxlength="120" autocomplete="off"></label>
                <label><span><?php esc_html_e('Contact name', 'mds-local-money-map'); ?></span><input type="text" name="requester_name" maxlength="80" autocomplete="name"></label>
                <label><span><?php esc_html_e('Email', 'mds-local-money-map'); ?></span><input type="email" name="requester_email" required autocomplete="email"></label>
                <label><span><?php esc_html_e('Category', 'mds-local-money-map'); ?></span><input type="text" name="category" maxlength="80" autocomplete="off"></label>
                <label class="mds-local-money-map__wide"><span><?php esc_html_e('Location or area', 'mds-local-money-map'); ?></span><input type="text" name="location_label" required maxlength="160" autocomplete="street-address" aria-describedby="mds-lmm-location-help-<?php echo esc_attr((string) absint($board['id'])); ?>"><small id="mds-lmm-location-help-<?php echo esc_attr((string) absint($board['id'])); ?>"><?php esc_html_e('Use the public place, neighbourhood, venue, or address visitors should recognize.', 'mds-local-money-map'); ?></small></label>
                <label><span><?php esc_html_e('Latitude (optional)', 'mds-local-money-map'); ?></span><input type="number" name="latitude" min="-90" max="90" step="0.000001" inputmode="decimal" aria-describedby="mds-lmm-coordinates-help-<?php echo esc_attr((string) absint($board['id'])); ?>"></label>
                <label><span><?php esc_html_e('Longitude (optional)', 'mds-local-money-map'); ?></span><input type="number" name="longitude" min="-180" max="180" step="0.000001" inputmode="decimal" aria-describedby="mds-lmm-coordinates-help-<?php echo esc_attr((string) absint($board['id'])); ?>"></label>
                <small id="mds-lmm-coordinates-help-<?php echo esc_attr((string) absint($board['id'])); ?>" class="mds-local-money-map__wide mds-local-money-map__form-note"><?php esc_html_e('Enter both coordinates to place this sponsor spot on the interactive map. Leave both blank to show its location as text and a map search link.', 'mds-local-money-map'); ?></small>
                <label><span><?php esc_html_e('Offer or perk', 'mds-local-money-map'); ?></span><input type="text" name="reward_label" maxlength="120" autocomplete="off"></label>
                <label><span><?php esc_html_e('Event date', 'mds-local-money-map'); ?></span><input type="date" name="due_at" autocomplete="off"></label>
                <label class="mds-local-money-map__wide"><span><?php esc_html_e('Sponsor URL', 'mds-local-money-map'); ?></span><input type="url" name="external_url" placeholder="https://" autocomplete="url" required></label>
                <label class="mds-local-money-map__wide"><span><?php esc_html_e('Spot description', 'mds-local-money-map'); ?></span><textarea name="description" rows="5" required></textarea></label>
                <label class="mds-local-money-map__check"><input type="checkbox" name="featured" value="1"> <span><?php echo esc_html(sprintf(__('Request featured placement (+%s)', 'mds-local-money-map'), $this->format_money($board['featured_fee'], $board['currency']))); ?></span></label>
                <label class="mds-local-money-map__check"><input type="checkbox" name="terms_accepted" value="1" required> <span><?php esc_html_e('I accept the campaign terms and sponsored-placement disclosure, and understand that my contact details will be used for moderation and payment support.', 'mds-local-money-map'); ?></span></label>
                <?php if (!empty($board['terms_url'])) : ?><p class="mds-local-money-map__terms"><a href="<?php echo esc_url($board['terms_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Review campaign terms', 'mds-local-money-map'); ?></a></p><?php endif; ?>
                <p class="mds-local-money-map__price"><?php echo esc_html(sprintf(__('Spot fee: %s. QR target links are created after moderation.', 'mds-local-money-map'), $this->format_money($board['posting_fee'], $board['currency']))); ?></p>
                <button type="submit"><?php esc_html_e('Submit spot for review', 'mds-local-money-map'); ?></button>
            </form>
        </details>
        <?php
    }

    private function claim_form(array $board, array $localmap) {
        ?>
        <details class="mds-local-money-map__submit mds-local-money-map__claim-form">
            <summary><?php esc_html_e('Submit a check-in', 'mds-local-money-map'); ?></summary>
            <p class="mds-local-money-map__form-note"><?php esc_html_e('Check-ins are reviewed before they are shared with the campaign owner or shown publicly.', 'mds-local-money-map'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('mds_local_money_map_submit_claim_' . absint($localmap['id'])); ?>
                <input type="hidden" name="action" value="mds_local_money_map_submit_claim">
                <input type="hidden" name="localmap_id" value="<?php echo esc_attr((string) $localmap['id']); ?>">
                <input type="text" name="company" value="" tabindex="-1" autocomplete="off" class="mds-local-money-map__trap" aria-hidden="true">
                <label><span><?php esc_html_e('Name', 'mds-local-money-map'); ?></span><input type="text" name="display_name" required maxlength="80" autocomplete="name"></label>
                <label><span><?php esc_html_e('Email', 'mds-local-money-map'); ?></span><input type="email" name="email" required autocomplete="email"></label>
                <label class="mds-local-money-map__wide"><span><?php esc_html_e('Visit or proof URL', 'mds-local-money-map'); ?></span><input type="url" name="work_url" placeholder="https://" autocomplete="url"></label>
                <label class="mds-local-money-map__wide"><span><?php esc_html_e('Check-in note', 'mds-local-money-map'); ?></span><textarea name="proposal" rows="4" required></textarea></label>
                <label class="mds-local-money-map__check"><input type="checkbox" name="terms_accepted" value="1" required> <span><?php esc_html_e('I confirm this check-in is accurate and may be reviewed and retained under the campaign terms.', 'mds-local-money-map'); ?></span></label>
                <?php if (!empty($board['terms_url'])) : ?><p class="mds-local-money-map__terms"><a href="<?php echo esc_url($board['terms_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Review campaign terms', 'mds-local-money-map'); ?></a></p><?php endif; ?>
                <button type="submit"><?php esc_html_e('Submit check-in', 'mds-local-money-map'); ?></button>
            </form>
        </details>
        <?php
    }

    private function requires_checkout($board, $localmap) {
        return $board && $localmap
            && 'provider' === (string) ($board['payment_mode'] ?? '')
            && (float) ($localmap['amount'] ?? 0) > 0;
    }

    private function start_checkout(array $board, array $localmap, $fallback_url) {
        if (!class_exists('\\MillionDollarScript\\Commerce\\Payments')) {
            return new \WP_Error('mds_lmm_payment_provider_missing', __('No Million Dollar Script payment provider is available.', 'mds-local-money-map'));
        }

        if (
            class_exists('\MillionDollarScript\Extensions\Subscriptions\Entitlements')
            && \MillionDollarScript\Extensions\Subscriptions\Entitlements::consume(
                'local-money-map',
                'spots',
                'spot:' . absint($localmap['id'] ?? 0),
                [
                    'user_id' => get_current_user_id(),
                    'email' => sanitize_email((string) ($localmap['requester_email'] ?? '')),
                ],
                (string) absint($board['id'] ?? 0)
            )
        ) {
            \MillionDollarScript\Commerce\Payments::mark_source_paid('local-money-map', absint($localmap['id'] ?? 0), [
                'provider' => 'subscription_allowance',
            ]);
            return [
                'provider' => 'subscription_allowance',
                'status' => 'paid',
                'checkout_url' => add_query_arg('localmap_engine_status', 'submitted', (string) $fallback_url),
            ];
        }

        $amount = (float) ($localmap['amount'] ?? 0);
        $transaction = [
            'source' => 'local-money-map',
            'source_id' => absint($localmap['id'] ?? 0),
            'source_key' => 'localmap-' . absint($localmap['id'] ?? 0),
            'user_id' => get_current_user_id(),
            'email' => sanitize_email($localmap['requester_email'] ?? ''),
            'currency' => Repository::effective_currency($localmap['currency'] ?? $board['currency'] ?? ''),
            'subtotal' => $amount,
            'total' => $amount,
            'items' => [[
                'name' => sprintf(__('Local Money Map spot: %s', 'mds-local-money-map'), $localmap['title']),
                'quantity' => 1,
                'unit_price' => $amount,
                'total' => $amount,
            ]],
            'manage_url' => esc_url_raw((string) $fallback_url),
            'metadata' => [
                'board_id' => absint($board['id'] ?? 0),
                'localmap_id' => absint($localmap['id'] ?? 0),
            ],
        ];

        return \MillionDollarScript\Commerce\Payments::create_checkout($transaction);
    }

    private function qr_image_url($localmap_id, $download = false) {
        $localmap_id = absint($localmap_id);
        $args = [
            'action' => 'mds_local_money_map_qr',
            'spot_id' => $localmap_id,
        ];
        if ($download) {
            $args['download'] = 1;
        }

        $localmap = Repository::localmap($localmap_id);
        if ($localmap && !$this->localmap_qr_public($localmap) && current_user_can('manage_options')) {
            $args['_wpnonce'] = wp_create_nonce('mds_local_money_map_qr_' . $localmap_id);
        }

        return add_query_arg($args, admin_url('admin-post.php'));
    }

    private function qr_print_url($board_id, $per_page = 4, $batch = 1) {
        $board_id = absint($board_id);
        return wp_nonce_url(add_query_arg([
            'action' => 'mds_local_money_map_qr_print',
            'board_id' => $board_id,
            'per_page' => absint($per_page),
            'batch' => max(1, absint($batch)),
        ], admin_url('admin-post.php')), 'mds_local_money_map_qr_print_' . $board_id);
    }

    private function localmap_qr_public(array $localmap) {
        return Repository::is_spot_public(absint($localmap['id'] ?? 0));
    }

    private function can_manage_qr($localmap_id) {
        return current_user_can('manage_options')
            && wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'mds_local_money_map_qr_' . absint($localmap_id));
    }

    private function field($name, $label, $value, $type = 'text', $required = false, $readonly = false, $help = '') {
        $wide = in_array($type, ['textarea'], true) ? ' mds-local-money-map-field-wide' : '';
        $integer = in_array((string) $name, ['localmap_limit', 'retention_days', 'submission_limit_per_hour'], true);
        $help = $help ?: $this->field_help($name);
        $help_id = 'mds-local-money-map-help-' . sanitize_html_class((string) $name);
        ?>
        <label class="mds-local-money-map-field<?php echo esc_attr($wide); ?>">
            <span><?php echo esc_html($label); ?></span>
            <?php if ('textarea' === $type) : ?>
                <textarea name="<?php echo esc_attr($name); ?>" rows="4" <?php echo $help ? 'aria-describedby="' . esc_attr($help_id) . '"' : ''; ?> <?php echo $required ? 'required' : ''; ?>><?php echo esc_textarea((string) $value); ?></textarea>
            <?php else : ?>
                <input type="<?php echo esc_attr($type); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>" <?php echo $help ? 'aria-describedby="' . esc_attr($help_id) . '"' : ''; ?> <?php echo $required ? 'required' : ''; ?> <?php echo $readonly ? 'readonly' : ''; ?> <?php echo 'number' === $type ? ($integer ? 'min="1" step="1"' : 'min="0" step="0.01"') : ''; ?>>
            <?php endif; ?>
            <?php if ($help) : ?>
                <small id="<?php echo esc_attr($help_id); ?>" class="mds-local-money-map-field-help"><?php echo esc_html($help); ?></small>
            <?php endif; ?>
        </label>
        <?php
    }

    private function select($name, $label, $value, array $options, $help = '') {
        $help = $help ?: $this->field_help($name);
        $help_id = 'mds-local-money-map-help-' . sanitize_html_class((string) $name);
        ?>
        <label class="mds-local-money-map-field">
            <span><?php echo esc_html($label); ?></span>
            <select name="<?php echo esc_attr($name); ?>" <?php echo $help ? 'aria-describedby="' . esc_attr($help_id) . '"' : ''; ?>>
                <?php foreach ($options as $option_value => $option_label) : ?>
                    <option value="<?php echo esc_attr((string) $option_value); ?>" <?php selected((string) $value, (string) $option_value); ?>><?php echo esc_html($option_label); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($help) : ?>
                <small id="<?php echo esc_attr($help_id); ?>" class="mds-local-money-map-field-help"><?php echo esc_html($help); ?></small>
            <?php endif; ?>
        </label>
        <?php
    }

    private function field_help($name) {
        $help = [
            'title' => __('The public campaign name shown above sponsor spots.', 'mds-local-money-map'),
            'slug' => __('Optional URL-safe identifier for shortcodes and integrations. Leave blank to generate one from the title.', 'mds-local-money-map'),
            'description' => __('Short public summary explaining the local trail, sponsors, or offers.', 'mds-local-money-map'),
            'status' => __('Only active campaigns accept public sponsor spot submissions.', 'mds-local-money-map'),
            'currency' => __('Three-letter currency code. WooCommerce or the active payment provider may lock this value.', 'mds-local-money-map'),
            'posting_fee' => __('Amount charged for each sponsor spot when paid submissions are enabled.', 'mds-local-money-map'),
            'featured_fee' => __('Extra amount added when a requester asks for featured placement.', 'mds-local-money-map'),
            'localmap_limit' => __('Maximum number of sponsor spots this campaign can hold.', 'mds-local-money-map'),
            'payment_mode' => __('Choose manual review or send paid spots through the active MDS payment provider.', 'mds-local-money-map'),
            'contact_email' => __('Private admin contact used for sponsor questions and moderation follow-up.', 'mds-local-money-map'),
            'retention_days' => __('How long private sponsor and check-in contact data should be retained.', 'mds-local-money-map'),
            'submission_limit_per_hour' => __('Combined sponsor spot and check-in submissions accepted from one visitor in an hour.', 'mds-local-money-map'),
            'terms_url' => __('Optional public terms page for spot rules, offers, QR links, refunds, and moderation.', 'mds-local-money-map'),
            'disclosure' => __('Public disclosure explaining sponsored offers, redemption, and fulfillment expectations.', 'mds-local-money-map'),
        ];

        return (string) ($help[(string) $name] ?? '');
    }

    private function first_active_board() {
        $boards = Repository::boards_page(1, 1, true);

        return $boards['items'][0] ?? null;
    }

    private function localmap_button($localmap_id, $status, $label) {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('mds_local_money_map_moderate_localmap_' . absint($localmap_id)); ?>
            <input type="hidden" name="action" value="mds_local_money_map_moderate_localmap">
            <input type="hidden" name="localmap_id" value="<?php echo esc_attr((string) absint($localmap_id)); ?>">
            <input type="hidden" name="moderation" value="<?php echo esc_attr((string) $status); ?>">
            <button class="button" type="submit"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    private function claim_button($claim_id, $status, $label) {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('mds_local_money_map_moderate_claim_' . absint($claim_id)); ?>
            <input type="hidden" name="action" value="mds_local_money_map_moderate_claim">
            <input type="hidden" name="claim_id" value="<?php echo esc_attr((string) absint($claim_id)); ?>">
            <input type="hidden" name="moderation" value="<?php echo esc_attr((string) $status); ?>">
            <button class="button" type="submit"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    private function admin_notice() {
        $status = sanitize_key((string) ($_GET['mds_lmm_status'] ?? ''));
        $messages = [
            'saved' => __('Campaign saved.', 'mds-local-money-map'),
            'moderated' => __('Sponsor spot updated.', 'mds-local-money-map'),
            'claim_moderated' => __('Check-in updated.', 'mds-local-money-map'),
            'error' => __('Local Money Map could not complete that action.', 'mds-local-money-map'),
        ];
        if (isset($messages[$status])) {
            echo '<div class="notice notice-' . ('error' === $status ? 'error' : 'success') . '" role="status"><p>' . esc_html($messages[$status]) . '</p></div>';
        }
    }

    private function public_notice() {
        $status = sanitize_key((string) ($_GET['localmap_engine_status'] ?? ''));
        $messages = [
            'submitted' => __('Sponsor spot submitted for review.', 'mds-local-money-map'),
            'claim_submitted' => __('Check-in submitted for review.', 'mds-local-money-map'),
            'closed' => __('This campaign is not accepting new sponsor spots right now.', 'mds-local-money-map'),
            'claims_closed' => __('Check-ins are not available for this sponsor spot right now.', 'mds-local-money-map'),
            'invalid' => __('Please check the required fields and try again.', 'mds-local-money-map'),
            'mds_lmm_closed' => __('This campaign is not accepting sponsor spots.', 'mds-local-money-map'),
            'mds_lmm_full' => __('This campaign has reached its sponsor spot capacity.', 'mds-local-money-map'),
            'mds_lmm_invalid_submission' => __('Add a business or stop name, location, description, valid email, and HTTP or HTTPS sponsor URL.', 'mds-local-money-map'),
            'mds_lmm_terms_required' => __('Accept the campaign terms and sponsored-placement disclosure before submitting.', 'mds-local-money-map'),
            'mds_lmm_invalid_date' => __('Use a real calendar date for the event date.', 'mds-local-money-map'),
            'mds_lmm_claims_closed' => __('Check-ins are not available for this sponsor spot right now.', 'mds-local-money-map'),
            'mds_lmm_invalid_claim' => __('Add a name, valid email, and check-in note.', 'mds-local-money-map'),
            'mds_lmm_invalid_work_url' => __('Use a valid HTTP or HTTPS proof URL.', 'mds-local-money-map'),
            'mds_lmm_claim_terms_required' => __('Confirm that the check-in information may be reviewed and retained under the campaign terms.', 'mds-local-money-map'),
            'rate_limited' => __('Please wait before submitting again.', 'mds-local-money-map'),
            'failed' => __('Your submission could not be saved.', 'mds-local-money-map'),
            'checkout_failed' => __('Your sponsor spot was saved, but payment checkout could not be started. No charge was made. Contact the site administrator before trying again.', 'mds-local-money-map'),
        ];
        if (isset($messages[$status])) {
            $success = in_array($status, ['submitted', 'claim_submitted'], true);
            echo '<p class="mds-local-money-map__notice mds-local-money-map__notice--' . ($success ? 'success' : 'error') . '" role="status">' . esc_html($messages[$status]) . '</p>';
        }
    }

    private function validation_status(\WP_Error $error) {
        $allowed = [
            'mds_lmm_closed',
            'mds_lmm_full',
            'mds_lmm_invalid_submission',
            'mds_lmm_terms_required',
            'mds_lmm_invalid_date',
            'mds_lmm_claims_closed',
            'mds_lmm_invalid_claim',
            'mds_lmm_invalid_work_url',
            'mds_lmm_claim_terms_required',
        ];
        $code = sanitize_key((string) $error->get_error_code());

        return in_array($code, $allowed, true) ? $code : 'failed';
    }

    private function rate_limited($scope, $limit, $window) {
        $scope = sanitize_key((string) $scope) ?: 'global';
        $limit = min(500, max(1, absint($limit)));
        $window = max(MINUTE_IN_SECONDS, absint($window));
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        $bucket = (string) floor(time() / $window);
        $hash = hash_hmac('sha256', ($ip ?: 'unknown') . '|' . $scope . '|' . $bucket, wp_salt('nonce'));
        $transient = 'mds_lmm_rate_' . substr($hash, 0, 32);
        $count = absint(get_transient($transient));
        if ($count >= $limit) {
            return true;
        }

        set_transient($transient, $count + 1, $window + MINUTE_IN_SECONDS);
        return false;
    }

    private function notify_spot_submission(array $board, array $spot) {
        $site_name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $admin_email = sanitize_email((string) ($board['contact_email'] ?? ''));
        $requester_email = sanitize_email((string) ($spot['requester_email'] ?? ''));
        if (is_email($admin_email)) {
            $message = sprintf(__('A new sponsor spot was submitted to %s.', 'mds-local-money-map'), (string) ($board['title'] ?? ''))
                . "\n\n" . sprintf(__('Sponsor spot: %s', 'mds-local-money-map'), (string) ($spot['title'] ?? ''))
                . "\n" . __('Status: Awaiting moderation', 'mds-local-money-map')
                . "\n\n" . sprintf(__('Review it: %s', 'mds-local-money-map'), $this->admin_url(['board_id' => absint($board['id'] ?? 0)]));
            wp_mail($admin_email, sprintf(__('[%1$s] New sponsor spot awaiting review: %2$s', 'mds-local-money-map'), $site_name, (string) ($spot['title'] ?? '')), $message);
        }
        if (is_email($requester_email)) {
            wp_mail(
                $requester_email,
                sprintf(__('[%s] Sponsor spot submission received', 'mds-local-money-map'), $site_name),
                sprintf(__('We received your sponsor spot "%1$s" for moderation on %2$s. It will not appear publicly until its moderation and payment requirements are complete.', 'mds-local-money-map'), (string) ($spot['title'] ?? ''), $site_name)
            );
        }
    }

    private function notify_claim_submission(array $board, array $spot, array $claim) {
        $site_name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $admin_email = sanitize_email((string) ($board['contact_email'] ?? ''));
        $claimant_email = sanitize_email((string) ($claim['email'] ?? ''));
        if (is_email($admin_email)) {
            $message = sprintf(__('A new check-in was submitted for %s.', 'mds-local-money-map'), (string) ($spot['title'] ?? ''))
                . "\n\n" . sprintf(__('Review it: %s', 'mds-local-money-map'), $this->admin_url(['board_id' => absint($board['id'] ?? 0)]));
            wp_mail($admin_email, sprintf(__('[%1$s] New check-in awaiting review: %2$s', 'mds-local-money-map'), $site_name, (string) ($spot['title'] ?? '')), $message);
        }
        if (is_email($claimant_email)) {
            wp_mail(
                $claimant_email,
                sprintf(__('[%s] Check-in received', 'mds-local-money-map'), $site_name),
                sprintf(__('We received your check-in for "%1$s" on %2$s. It is awaiting moderation.', 'mds-local-money-map'), (string) ($spot['title'] ?? ''), $site_name)
            );
        }
    }

    private function notify_spot_moderation($board, $spot) {
        if (!is_array($board) || !is_array($spot)) {
            return;
        }
        $email = sanitize_email((string) ($spot['requester_email'] ?? ''));
        if (!is_email($email)) {
            return;
        }
        $site_name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $status = ucwords(str_replace('_', ' ', sanitize_key((string) ($spot['status'] ?? 'updated'))));
        wp_mail(
            $email,
            sprintf(__('[%s] Sponsor spot status updated', 'mds-local-money-map'), $site_name),
            sprintf(__('Your sponsor spot "%1$s" in "%2$s" is now marked %3$s.', 'mds-local-money-map'), (string) ($spot['title'] ?? ''), (string) ($board['title'] ?? ''), $status)
        );
    }

    private function notify_claim_moderation($claim) {
        if (!is_array($claim)) {
            return;
        }
        $email = sanitize_email((string) ($claim['email'] ?? ''));
        if (!is_email($email)) {
            return;
        }
        $spot = Repository::localmap(absint($claim['localmap_id'] ?? 0));
        $site_name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $status = ucwords(str_replace('_', ' ', sanitize_key((string) ($claim['status'] ?? 'updated'))));
        wp_mail(
            $email,
            sprintf(__('[%s] Check-in status updated', 'mds-local-money-map'), $site_name),
            sprintf(__('Your check-in for "%1$s" is now marked %2$s.', 'mds-local-money-map'), (string) ($spot['title'] ?? __('Sponsor spot', 'mds-local-money-map')), $status)
        );
    }

    private function admin_pagination(array $collection, $query_key, array $context = []) {
        $current = max(1, absint($collection['page'] ?? 1));
        $pages = max(1, absint($collection['pages'] ?? 1));
        if ($pages <= 1) {
            return;
        }
        ?>
        <nav class="mds-local-money-map-pagination" aria-label="<?php esc_attr_e('Local Money Map pages', 'mds-local-money-map'); ?>">
            <?php if ($current > 1) : ?><a class="button" href="<?php echo esc_url($this->admin_url(array_merge($context, [$query_key => $current - 1]))); ?>"><?php esc_html_e('Previous', 'mds-local-money-map'); ?></a><?php endif; ?>
            <span><?php echo esc_html(sprintf(__('Page %1$d of %2$d', 'mds-local-money-map'), $current, $pages)); ?></span>
            <?php if ($current < $pages) : ?><a class="button" href="<?php echo esc_url($this->admin_url(array_merge($context, [$query_key => $current + 1]))); ?>"><?php esc_html_e('Next', 'mds-local-money-map'); ?></a><?php endif; ?>
        </nav>
        <?php
    }

    private function public_pagination(array $collection, $query_key) {
        $current = max(1, absint($collection['page'] ?? 1));
        $pages = max(1, absint($collection['pages'] ?? 1));
        if ($pages <= 1) {
            return;
        }
        $request_url = home_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
        $base_url = remove_query_arg([$query_key, 'localmap_engine_status'], $request_url);
        ?>
        <nav class="mds-local-money-map__pagination" aria-label="<?php esc_attr_e('Sponsor spot pages', 'mds-local-money-map'); ?>">
            <?php if ($current > 1) : ?><a href="<?php echo esc_url(add_query_arg($query_key, $current - 1, $base_url)); ?>"><?php esc_html_e('Previous', 'mds-local-money-map'); ?></a><?php endif; ?>
            <span><?php echo esc_html(sprintf(__('Page %1$d of %2$d', 'mds-local-money-map'), $current, $pages)); ?></span>
            <?php if ($current < $pages) : ?><a href="<?php echo esc_url(add_query_arg($query_key, $current + 1, $base_url)); ?>"><?php esc_html_e('Next', 'mds-local-money-map'); ?></a><?php endif; ?>
        </nav>
        <?php
    }

    private function pagination_args() {
        return [
            'page' => [
                'default' => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => static function ($value) {
                    return absint($value) >= 1;
                },
            ],
            'per_page' => [
                'default' => 20,
                'sanitize_callback' => static function ($value) {
                    return min(100, max(1, absint($value)));
                },
                'validate_callback' => static function ($value) {
                    return absint($value) >= 1 && absint($value) <= 100;
                },
            ],
        ];
    }

    private function format_money($amount, $currency) {
        $currency = Repository::effective_currency($currency);
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency')) {
            return \MillionDollarScript\Commerce\Currency::format((float) $amount, $currency);
        }

        return trim(sprintf('%s %s', $currency, number_format_i18n((float) $amount, 2)));
    }

    private function status_label($status) {
        return ucwords(str_replace(['_', '-'], ' ', sanitize_key((string) $status)));
    }

    private function format_date($date) {
        $date = sanitize_text_field((string) $date);
        if (!$date) {
            return '';
        }

        return date_i18n(get_option('date_format'), strtotime($date . ' 00:00:00'));
    }

    private function map_search_url(array $spot) {
        if (null !== ($spot['latitude'] ?? null) && null !== ($spot['longitude'] ?? null)) {
            $latitude = number_format((float) $spot['latitude'], 6, '.', '');
            $longitude = number_format((float) $spot['longitude'], 6, '.', '');

            return 'https://www.openstreetmap.org/?mlat=' . rawurlencode($latitude)
                . '&mlon=' . rawurlencode($longitude)
                . '#map=16/' . rawurlencode($latitude) . '/' . rawurlencode($longitude);
        }

        return add_query_arg('query', (string) ($spot['location_label'] ?? ''), 'https://www.openstreetmap.org/search');
    }

    private function public_target_url($url) {
        $url = esc_url_raw((string) $url, ['http', 'https']);
        if (!$url) {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true) ? $url : '';
    }

    private function admin_url(array $args = []) {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support')) {
            return \MillionDollarScript\Extensions\Support::admin_url('mds-local-money-map', $args);
        }

        return add_query_arg(array_merge(['page' => 'mds-local-money-map'], $args), admin_url('admin.php'));
    }

    private function redirect(array $args = []) {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support')) {
            \MillionDollarScript\Extensions\Support::redirect_admin('mds-local-money-map', $args);
        }

        wp_safe_redirect($this->admin_url($args));
        exit;
    }

    private function checkout_redirect($url, $fallback_url = '') {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support') && method_exists('\\MillionDollarScript\\Extensions\\Support', 'external_redirect')) {
            \MillionDollarScript\Extensions\Support::external_redirect($url, $fallback_url ?: home_url('/'));
        }

        $target = esc_url_raw((string) $url, ['http', 'https']);
        $parts = wp_parse_url($target);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!$target || empty($parts['host']) || !in_array($scheme, ['http', 'https'], true)) {
            $this->safe_redirect($fallback_url ?: home_url('/'));
        }

        wp_redirect($target, 302, 'Million Dollar Script - Local Money Map');
        exit;
    }

    private function safe_redirect($url, array $args = []) {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support')) {
            \MillionDollarScript\Extensions\Support::safe_redirect($url, $args);
        }

        $url = $args ? add_query_arg($args, $url) : $url;
        wp_safe_redirect(esc_url_raw($url));
        exit;
    }

    private function admin_parent_slug() {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support')) {
            return \MillionDollarScript\Extensions\Support::admin_parent_slug();
        }

        global $admin_page_hooks;
        if (isset($admin_page_hooks['mds3'])) {
            return 'mds3';
        }
        if (isset($admin_page_hooks['million-dollar-script'])) {
            return 'million-dollar-script';
        }

        return 'options-general.php';
    }
}

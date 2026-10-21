<?php
/**
 * Plugin Name: Million Dollar Script - Local Money Map
 * Plugin URI: https://milliondollarscript.com/extensions/local-money-map
 * Description: Run local sponsorship maps, trails, offers, QR target links, and moderated sponsor spot campaigns.
 * Version: 0.2.2
 * Author: Million Dollar Script
 * Author URI: https://milliondollarscript.com
 * Text Domain: mds-local-money-map
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 7.1.3
 * Requires PHP: 8.1
 * License: GPL-3.0+
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires Plugins: million-dollar-script
 *
 * Requires MDS: 3.0.0
 * Requires MDS API: 1
 * Requires License: No
 * MDS Provides: inventory.local-money-map sponsorship.inventory
 * MDS Requires: platform.core
 * MDS Recommends: mds-woocommerce
 * MDS Requires Service: no
 * MDS Setup Category: monetization
 * MDS Minimum Security Level: api_key_read
 * MDS LLM Safe Actions: read_boards read_spots
 *
 * @package MillionDollarScript\Extensions\LocalMoneyMap
 */

namespace MillionDollarScript\Extensions\LocalMoneyMap;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('MDS_LOCAL_MONEY_MAP_VERSION')) {
    define('MDS_LOCAL_MONEY_MAP_VERSION', '0.2.2');
}

if (!defined('MDS_LOCAL_MONEY_MAP_FILE')) {
    define('MDS_LOCAL_MONEY_MAP_FILE', __FILE__);
}

if (!defined('MDS_LOCAL_MONEY_MAP_PATH')) {
    define('MDS_LOCAL_MONEY_MAP_PATH', plugin_dir_path(__FILE__));
}

if (!defined('MDS_LOCAL_MONEY_MAP_URL')) {
    define('MDS_LOCAL_MONEY_MAP_URL', plugin_dir_url(__FILE__));
}

if (!defined('MDS_LOCAL_MONEY_MAP_BASENAME')) {
    define('MDS_LOCAL_MONEY_MAP_BASENAME', plugin_basename(__FILE__));
}

function mds_local_money_map_check_mds_active() {
    return class_exists('\\MillionDollarScript\\Core\\Runtime')
        && \MillionDollarScript\Core\Runtime::is_ready();
}

function mds_local_money_map_missing_mds_notice() {
    echo '<div class="notice notice-error"><p>';
    echo '<strong>' . esc_html__('Million Dollar Script - Local Money Map', 'mds-local-money-map') . '</strong>: ';
    echo esc_html__('This extension requires the Million Dollar Script plugin to be installed and activated.', 'mds-local-money-map');
    echo ' <a href="' . esc_url(admin_url('plugins.php')) . '">' . esc_html__('Manage Plugins', 'mds-local-money-map') . '</a>';
    echo '</p></div>';
}

function mds_local_money_map_load_files() {
    $autoload = MDS_LOCAL_MONEY_MAP_PATH . 'vendor/autoload.php';
    if (is_readable($autoload)) {
        require_once $autoload;
    }

    require_once MDS_LOCAL_MONEY_MAP_PATH . 'includes/Repository.php';
    require_once MDS_LOCAL_MONEY_MAP_PATH . 'includes/QrCode.php';
    require_once MDS_LOCAL_MONEY_MAP_PATH . 'includes/Privacy.php';
    require_once MDS_LOCAL_MONEY_MAP_PATH . 'includes/Retention.php';
    require_once MDS_LOCAL_MONEY_MAP_PATH . 'includes/Main.php';
}

function mds_local_money_map_init() {
    if (!mds_local_money_map_check_mds_active()) {
        add_action('admin_notices', __NAMESPACE__ . '\\mds_local_money_map_missing_mds_notice');
        return;
    }

    mds_local_money_map_load_files();
    Main::instance()->register();
    Retention::register();
}

function mds_local_money_map_register_extension() {
    if (!mds_local_money_map_check_mds_active()) {
        return;
    }

    if (class_exists('\\MillionDollarScript\\Extensions\\Registry')) {
        \MillionDollarScript\Extensions\Registry::register([
            'id' => 'mds-local-money-map',
            'name' => 'Local Money Map',
            'description' => 'Run local sponsorship maps, trails, offers, QR target links, and moderated sponsor spot campaigns.',
            'version' => MDS_LOCAL_MONEY_MAP_VERSION,
            'author' => 'Million Dollar Script',
            'url' => 'https://milliondollarscript.com/extensions/local-money-map',
            'main_file' => __FILE__,
            'premium' => false,
            'license_required' => false,
            'cleanup' => [
                'description' => 'Local campaigns, sponsor spots, check-ins, and extension settings.',
            ],
            'capabilities' => [
                'admin_pages' => true,
                'localmap_inventory' => true,
                'frontend_assets' => true,
                'api' => true,
            ],
        ]);
    }
}

function mds_local_money_map_activate() {
    if (!mds_local_money_map_check_mds_active()) {
        deactivate_plugins(MDS_LOCAL_MONEY_MAP_BASENAME);
        wp_die(
            esc_html__('Million Dollar Script - Local Money Map requires the Million Dollar Script plugin to be installed and activated.', 'mds-local-money-map'),
            esc_html__('Plugin Activation Error', 'mds-local-money-map'),
            ['back_link' => true]
        );
    }

    mds_local_money_map_load_files();
    Main::register_post_types();
    Repository::maybe_seed();
    Retention::activate();
    flush_rewrite_rules();
}

function mds_local_money_map_deactivate() {
    mds_local_money_map_load_files();
    Retention::deactivate();
    flush_rewrite_rules();
}

register_activation_hook(__FILE__, __NAMESPACE__ . '\\mds_local_money_map_activate');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\mds_local_money_map_deactivate');

add_action('plugins_loaded', __NAMESPACE__ . '\\mds_local_money_map_init', 20);
add_action('million-dollar-script/register/extensions', __NAMESPACE__ . '\\mds_local_money_map_register_extension');

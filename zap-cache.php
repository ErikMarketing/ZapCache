<?php
/**
 * Plugin Name: Zap Cache
 * Plugin URI: https://github.com/ErikMarketing/zap-cache
 * Description: Lightning-fast cache cleaning with one click. Supports Hostinger, WP Engine, Kinsta, SiteGround, Cloudways and major caching plugins
 * Version: 1.1.1
 * Author: ErikMarketing
 * Author URI: https://erik.marketing
 * License: GPL-3.0+
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 * Text Domain: zap-cache
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 *
 * @package Zap_Cache
 * @author ErikMarketing
 * @copyright Copyright (c) 2024, ErikE
 * @license GPL-3.0+
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ZAP_VERSION', '1.1.1');
define('ZAP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZAP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ZAP_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Load plugin textdomain
add_action('plugins_loaded', 'zap_load_textdomain');
function zap_load_textdomain() {
    load_plugin_textdomain('zap-cache', false, dirname(ZAP_PLUGIN_BASENAME) . '/languages');
}

// Add the cache purge button to the admin bar
add_action('admin_bar_menu', 'zap_add_purge_button', 999);
function zap_add_purge_button($wp_admin_bar) {
    if (!current_user_can('manage_options')) {
        return;
    }

    $wp_admin_bar->add_node(array(
        'id' => 'zap-cache',
        'title' => '<span class="ab-icon dashicons dashicons-superhero"></span>' . 
                   __('Zap Cache', 'zap-cache'),
        'href' => '#',
        'meta' => array(
            'class' => 'zap-cache-button',
            'title' => __('⚡ Instantly clear all cache', 'zap-cache')
        )
    ));
}

// Add the necessary styles for the admin bar button
add_action('admin_head', 'zap_add_custom_css');
function zap_add_custom_css() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <style>
        #wp-admin-bar-zap-cache .ab-icon {
            top: 2px;
            margin-right: 5px;
        }
        #wp-admin-bar-zap-cache .ab-icon:before {
            color: #f0f0f1;
        }
        #wp-admin-bar-zap-cache:hover .ab-icon:before {
            color: #00b9eb;
        }
    </style>
    <?php
}

// Add the JavaScript to handle the cache purging
add_action('admin_footer', 'zap_add_purge_script');
function zap_add_purge_script() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('#wp-admin-bar-zap-cache').click(function(e) {
            e.preventDefault();
            
            const confirmMsg = '<?php echo esc_js(__('⚡ Ready to zap all cache?', 'zap-cache')); ?>';
            
            if (confirm(confirmMsg)) {
                const $button = $(this);
                const originalHtml = $button.find('.ab-item').html();
                
                // Show loading state
                $button.find('.ab-item').html(
                    '<span class="ab-icon dashicons dashicons-update-alt"></span>' +
                    '<?php echo esc_js(__('Zapping...', 'zap-cache')); ?>'
                );
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'zap_purge_cache',
                        nonce: '<?php echo wp_create_nonce('zap_purge_cache_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Show success message and restore button
                            $button.find('.ab-item').html(
                                '<span class="ab-icon dashicons dashicons-yes-alt"></span>' +
                                '<?php echo esc_js(__('Cache Zapped! ⚡', 'zap-cache')); ?>'
                            );
                            setTimeout(() => {
                                $button.find('.ab-item').html(originalHtml);
                            }, 2000);
                        } else {
                            alert('<?php echo esc_js(__('Error:', 'zap-cache')); ?> ' + response.data);
                            $button.find('.ab-item').html(originalHtml);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error(error);
                        alert('<?php echo esc_js(__('Failed to zap cache. Please try again.', 'zap-cache')); ?>');
                        $button.find('.ab-item').html(originalHtml);
                    }
                });
            }
        });
    });
    </script>
    <?php
}

// Handle the AJAX request to purge cache
add_action('wp_ajax_zap_purge_cache', 'zap_purge_cache_callback');
function zap_purge_cache_callback() {
    // Verify nonce and capabilities
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'zap_purge_cache_nonce')) {
        wp_send_json_error(__('Security check failed', 'zap-cache'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied', 'zap-cache'));
    }

    $results = array();

    try {
        // WordPress Core Cache
        wp_cache_flush();
        $results['wp_cache'] = true;

        // Fire action for other plugins to hook into
        do_action('zap_before_cache_purge');

        // Hostinger Support
        if (defined('LSCWP_V')) {
            // Clear LiteSpeed Cache
            if (class_exists('LiteSpeed_Cache_API')) {
                LiteSpeed_Cache_API::purge_all();
                $results['hostinger_litespeed'] = true;
            }
            
            // Clear Redis if active
            if (class_exists('WP_Redis')) {
                wp_cache_flush();
                $results['hostinger_redis'] = true;
            }
        }

        // WP Engine Support
        if (class_exists('WpeCommon')) {
            if (method_exists('WpeCommon', 'purge_memcached')) {
                WpeCommon::purge_memcached();
                $results['wpe_memcached'] = true;
            }
            if (method_exists('WpeCommon', 'purge_varnish_cache')) {
                WpeCommon::purge_varnish_cache();
                $results['wpe_varnish'] = true;
            }
        }

        // Kinsta Support
        if (isset($GLOBALS['kinsta_cache']) && class_exists('Kinsta\Cache')) {
            if (method_exists($GLOBALS['kinsta_cache'], 'purge_complete_caches')) {
                $GLOBALS['kinsta_cache']->purge_complete_caches();
                $results['kinsta_cache'] = true;
            }
        }

        // SiteGround Support
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
            $results['siteground_cache'] = true;
        }

        // Cloudways Support
        if (class_exists('Breeze_Admin')) {
            do_action('breeze_clear_all_cache');
            $results['cloudways_breeze'] = true;
        }

        // Common Cache Plugins Support
        $cache_plugins = array(
            'w3tc' => 'w3tc_flush_all',
            'wp_super_cache' => 'wp_cache_clear_cache',
            'wp_rocket' => 'rocket_clean_domain',
            'wp_fastest_cache' => array('WpFastestCache', 'deleteCache'),
            'autoptimize' => array('autoptimizeCache', 'clearall'),
            'litespeed_cache' => array('LiteSpeed_Cache_API', 'purge_all'),
            'swift_performance' => array('Swift_Performance_Cache', 'clear_all_cache'),
            'hummingbird' => array('Hummingbird\Core\Cache', 'clear_page_cache')
        );

        foreach ($cache_plugins as $plugin => $function) {
            if (is_array($function)) {
                if (class_exists($function[0]) && method_exists($function[0], $function[1])) {
                    call_user_func(array($function[0], $function[1]));
                    $results[$plugin] = true;
                }
            } elseif (function_exists($function)) {
                call_user_func($function);
                $results[$plugin] = true;
            }
        }

        // Clear Nginx Helper Cache
        if (class_exists('Nginx_Helper')) {
            do_action('rt_nginx_helper_purge_all');
            $results['nginx_helper'] = true;
        }

        // Clear OPcache if enabled
        if (function_exists('opcache_reset')) {
            opcache_reset();
            $results['opcache'] = true;
        }

        // Clear object cache for common providers
        $object_cache_plugins = array(
            'redis' => 'WP_Redis',
            'memcached' => 'WP_Object_Cache'
        );

        foreach ($object_cache_plugins as $cache => $class) {
            if (class_exists($class)) {
                wp_cache_flush();
                $results[$cache . '_object_cache'] = true;
                break;
            }
        }

        // Fire action after cache purge
        do_action('zap_after_cache_purge');

        // Add debug information if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $results['debug_info'] = array(
                'hosting_provider' => zap_detect_hosting_provider(),
                'cleared_caches' => array_keys(array_filter($results))
            );
        }

        wp_send_json_success($results);

    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
}

/**
 * Detect hosting provider
 * @return string
 */
function zap_detect_hosting_provider() {
    if (defined('LSCWP_V')) {
        return 'Hostinger';
    } elseif (class_exists('WpeCommon')) {
        return 'WP Engine';
    } elseif (isset($GLOBALS['kinsta_cache'])) {
        return 'Kinsta';
    } elseif (function_exists('sg_cachepress_purge_cache')) {
        return 'SiteGround';
    } elseif (class_exists('Breeze_Admin')) {
        return 'Cloudways';
    } elseif (defined('IS_PRESSABLE') && IS_PRESSABLE) {
        return 'Pressable';
    } elseif (defined('FLYWHEEL_CONFIG_DIR')) {
        return 'Flywheel';
    } elseif (defined('GD_SYSTEM_PLUGIN_DIR')) {
        return 'GoDaddy';
    }
    return 'Unknown';
}

// Add plugin action links
add_filter('plugin_action_links_' . ZAP_PLUGIN_BASENAME, 'zap_add_action_links');
function zap_add_action_links($links) {
    $plugin_links = array(
        '<a href="https://github.com/ErikMarketing/zap-cache" target="_blank">' . 
        __('View on GitHub', 'zap-cache') . '</a>'
    );
    return array_merge($plugin_links, $links);
}

// Register activation hook
register_activation_hook(__FILE__, 'zap_activate');
function zap_activate() {
    // Log activation if debug is enabled
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Zap Cache activated on ' . zap_detect_hosting_provider() . ' hosting');
    }
}

// Register deactivation hook
register_deactivation_hook(__FILE__, 'zap_deactivate');
function zap_deactivate() {
    // Cleanup tasks if needed
}

// Load scripts in frontend
add_action('wp_footer', 'zap_add_purge_script');

// Ensure dashicons are loaded in frontend
add_action('wp_enqueue_scripts', 'zap_enqueue_dashicons');
function zap_enqueue_dashicons() {
    if (is_user_logged_in()) {
        wp_enqueue_style('dashicons');
    }
}

// Add the necessary styles for the frontend admin bar button
add_action('wp_head', 'zap_add_custom_css');

// Define ajaxurl in frontend
add_action('wp_head', 'zap_add_ajax_url');
function zap_add_ajax_url() {
    if (current_user_can('manage_options')) {
        ?>
        <script type="text/javascript">
            var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
        </script>
        <?php
    }
}

<?php
/**
 * Plugin Name: Zap Cache
 * Plugin URI: https://github.com/ErikMarketing/zap-cache
 * Description: Lightning-fast cache cleaning with one click. Automatic detection for Hostinger, WP Engine, Kinsta, SiteGround, Cloudways, Nitropack and major caching plugins
 * Version: 1.2.0
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
define('ZAP_VERSION', '1.2.0');
define('ZAP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ZAP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ZAP_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Log cache clearing operations
 * @param array $results Cache clearing results
 * @return bool Whether the log was saved successfully
 */
function zap_log_operation($results) {
    try {
        // Get current user
        $current_user = wp_get_current_user();
        
        // Prepare log entry
        $log = array(
            'timestamp' => current_time('mysql'),
            'user' => $current_user->user_login,
            'results' => $results,
            'memory_used' => memory_get_peak_usage(true),
            'host' => zap_detect_hosting_provider()
        );
        
        // Get existing logs
        $logs = get_option('zap_cache_logs', array());
        
        // Add new log to start
        array_unshift($logs, $log);
        
        // Keep only last 100 entries
        $logs = array_slice($logs, 0, 100);
        
        // Save updated logs
        $update_result = update_option('zap_cache_logs', $logs);
        
        // Debug logging if enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Zap Cache: Log operation ' . ($update_result ? 'successful' : 'failed'));
            error_log('Zap Cache: Total logs: ' . count($logs));
            error_log('Zap Cache: Memory used: ' . size_format($log['memory_used']));
            error_log('Zap Cache: Host detected: ' . $log['host']);
        }
        
        return $update_result;
        
    } catch (Exception $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Zap Cache Error: Failed to log operation - ' . $e->getMessage());
        }
        return false;
    }
}

/**
 * Get formatted logs for display
 * @return array Formatted logs
 */
function zap_get_formatted_logs() {
    $logs = get_option('zap_cache_logs', array());
    
    if (empty($logs)) {
        return array();
    }
    
    // Format logs for display
    foreach ($logs as &$log) {
        $log['formatted_time'] = wp_date(
            get_option('date_format') . ' ' . get_option('time_format'),
            strtotime($log['timestamp'])
        );
        $log['formatted_memory'] = size_format($log['memory_used']);
        $log['cache_count'] = count(array_filter($log['results']));
        
        if (isset($log['results']['performance'])) {
            $log['time_taken'] = $log['results']['performance']['time_taken'];
        }
    }
    
    return $logs;
}

/**
 * Check rate limiting
 * @return bool Whether operation should proceed
 */
function zap_check_rate_limit() {
    $last_clear = get_transient('zap_last_cache_clear');
    if ($last_clear) {
        $time_passed = time() - $last_clear;
        if ($time_passed < 60) { // 1 minute limit
            return false;
        }
    }
    set_transient('zap_last_cache_clear', time(), HOUR_IN_SECONDS);
    return true;
}

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
    // Start performance tracking
    $start_time = microtime(true);
    
    // Verify nonce and capabilities
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'zap_purge_cache_nonce')) {
        wp_send_json_error(__('Security check failed', 'zap-cache'));
    }

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied', 'zap-cache'));
    }

    // Check rate limiting
    if (!zap_check_rate_limit()) {
        wp_send_json_error(__('Please wait a minute before clearing cache again', 'zap-cache'));
    }

    $results = array();
    $cache_count = 0;

    try {
        // WordPress Core Cache
        wp_cache_flush();
        $results['wp_cache'] = true;
        $cache_count++;

        // Fire action for other plugins to hook into
        do_action('zap_before_cache_purge');

        // Hostinger Support
        if (defined('LSCWP_V')) {
            // Clear LiteSpeed Cache
            if (class_exists('LiteSpeed_Cache_API')) {
                LiteSpeed_Cache_API::purge_all();
                $results['hostinger_litespeed'] = true;
                $cache_count++;
            }
            
            // Clear Redis if active
            if (class_exists('WP_Redis')) {
                wp_cache_flush();
                $results['hostinger_redis'] = true;
                $cache_count++;
            }
        }

        // WP Engine Support
        if (class_exists('WpeCommon')) {
            if (method_exists('WpeCommon', 'purge_memcached')) {
                WpeCommon::purge_memcached();
                $results['wpe_memcached'] = true;
                $cache_count++;
            }
            if (method_exists('WpeCommon', 'purge_varnish_cache')) {
                WpeCommon::purge_varnish_cache();
                $results['wpe_varnish'] = true;
                $cache_count++;
            }
        }

        // Kinsta Support
        if (isset($GLOBALS['kinsta_cache']) && class_exists('Kinsta\Cache')) {
            if (method_exists($GLOBALS['kinsta_cache'], 'purge_complete_caches')) {
                $GLOBALS['kinsta_cache']->purge_complete_caches();
                $results['kinsta_cache'] = true;
                $cache_count++;
            }
        }

        // SiteGround Support
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
            $results['siteground_cache'] = true;
            $cache_count++;
        }

        // Cloudways Support
        if (class_exists('Breeze_Admin')) {
            do_action('breeze_clear_all_cache');
            $results['cloudways_breeze'] = true;
            $cache_count++;
        }

        // Nitropack Support
        if (defined('NITROPACK_VERSION') && class_exists('\NitroPack\SDK\Integrations\Nitropack')) {
            try {
                // Clear all Nitropack cache
                do_action('nitropack_integration_purge_all');
                $results['nitropack_all'] = true;
                $cache_count++;
                
                // Clear just page cache
                do_action('nitropack_integration_purge_cache');
                $results['nitropack_page'] = true;
                $cache_count++;
                
            } catch (Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Nitropack cache clearing failed: ' . $e->getMessage());
                }
            }
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
            'hummingbird' => array('Hummingbird\Core\Cache', 'clear_page_cache'),
            'nitropack' => array('NitroPack\SDK\Integrations\Nitropack', 'purgeCache')
        );

        foreach ($cache_plugins as $plugin => $function) {
            if (is_array($function)) {
                if (class_exists($function[0]) && method_exists($function[0], $function[1])) {
                    call_user_func(array($function[0], $function[1]));
                    $results[$plugin] = true;
                    $cache_count++;
                }
            } elseif (function_exists($function)) {
                call_user_func($function);
                $results[$plugin] = true;
                $cache_count++;
            }
        }

        // Clear Nginx Helper Cache
        if (class_exists('Nginx_Helper')) {
            do_action('rt_nginx_helper_purge_all');
            $results['nginx_helper'] = true;
            $cache_count++;
        }

        // Clear OPcache if enabled
        if (function_exists('opcache_reset')) {
            opcache_reset();
            $results['opcache'] = true;
            $cache_count++;
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
                $cache_count++;
                break;
            }
        }

        // Fire action after cache purge
        do_action('zap_after_cache_purge');

        // Add performance metrics
        $results['performance'] = array(
            'time_taken' => round(microtime(true) - $start_time, 3),
            'memory_peak' => memory_get_peak_usage(true),
            'caches_cleared' => $cache_count,
            'php_version' => phpversion(),
            'wp_version' => get_bloginfo('version')
        );

        // Add debug information
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $results['debug_info'] = array(
                'hosting_provider' => zap_detect_hosting_provider(),
                'cleared_caches' => array_keys(array_filter($results))
            );
        }

        // Log the operation
        zap_log_operation($results);

        wp_send_json_success($results);

    } catch (Exception $e) {
        error_log('Zap Cache Error: ' . $e->getMessage());
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

/**
 * Add admin menu page
 */
add_action('admin_menu', 'zap_add_admin_menu');
function zap_add_admin_menu() {
    add_submenu_page(
        'tools.php',
        __('Zap Cache Logs', 'zap-cache'),
        __('Zap Cache', 'zap-cache'),
        'manage_options',
        'zap-cache',
        'zap_admin_page'
    );
}

/**
 * Format time according to WordPress settings
 */
function zap_format_time($timestamp) {
    return wp_date(
        get_option('date_format') . ' ' . get_option('time_format'),
        strtotime($timestamp)
    );
}

/**
 * Render admin page
 */
function zap_admin_page() {
    // Get logs
    $logs = get_option('zap_cache_logs', array());
    
    // Add admin page styles
    ?>
    <style>
        .zap-cache-logs .widefat {
            margin-top: 10px;
        }
        .zap-cache-logs .tablenav {
            margin: 15px 0;
        }
        .zap-cache-logs .notice {
            margin: 15px 0;
        }
        .zap-cache-count {
            display: inline-block;
            padding: 0 8px;
            border-radius: 10px;
            background: #2271b1;
            color: #fff;
        }
    </style>
    
    <div class="wrap zap-cache-logs">
        <h1><?php _e('Zap Cache Logs', 'zap-cache'); ?></h1>
        
        <div class="tablenav top">
            <div class="tablenav-pages">
                <span class="displaying-num">
                    <?php echo sprintf(
                        __('%s operations logged', 'zap-cache'),
                        number_format_i18n(count($logs))
                    ); ?>
                </span>
            </div>
        </div>

        <?php if (empty($logs)): ?>
            <div class="notice notice-info">
                <p><?php _e('No cache clearing operations logged yet. Clear your cache to see logs here.', 'zap-cache'); ?></p>
            </div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="20%"><?php _e('Time', 'zap-cache'); ?></th>
                        <th width="15%"><?php _e('User', 'zap-cache'); ?></th>
                        <th width="15%"><?php _e('Host', 'zap-cache'); ?></th>
                        <th width="15%"><?php _e('Caches Cleared', 'zap-cache'); ?></th>
                        <th width="15%"><?php _e('Time Taken', 'zap-cache'); ?></th>
                        <th width="20%"><?php _e('Memory Used', 'zap-cache'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html(zap_format_time($log['timestamp'])); ?></td>
                            <td><?php echo esc_html($log['user']); ?></td>
                            <td><?php echo esc_html($log['host']); ?></td>
                            <td>
                                <?php 
                                $cache_count = 0;
                                if (isset($log['results']) && is_array($log['results'])) {
                                    $cache_count = count(array_filter($log['results'], function($result) {
                                        return $result === true;
                                    }));
                                }
                                ?>
                                <span class="zap-cache-count">
                                    <?php echo esc_html($cache_count); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                if (isset($log['results']['performance']['time_taken'])) {
                                    echo sprintf(
                                        __('%s seconds', 'zap-cache'),
                                        number_format_i18n($log['results']['performance']['time_taken'], 3)
                                    );
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                            <td>
                                <?php 
                                if (isset($log['memory_used'])) {
                                    echo size_format($log['memory_used']);
                                } else {
                                    echo '—';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}

// Add plugin action links
add_filter('plugin_action_links_' . ZAP_PLUGIN_BASENAME, 'zap_add_action_links');
function zap_add_action_links($links) {
    $plugin_links = array(
        '<a href="' . admin_url('tools.php?page=zap-cache') . '">' . __('View Logs', 'zap-cache') . '</a>',
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
    // Clean up logs and transients
    delete_option('zap_cache_logs');
    delete_transient('zap_last_cache_clear');
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

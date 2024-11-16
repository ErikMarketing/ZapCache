<?php
/**
 * Plugin Name: Zap Cache
 * Plugin URI: https://github.com/ErikMarketing/zap-cache
 * Description: Lightning-fast cache cleaning with one click from your WordPress admin bar
 * Version: 1.0.0
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
 *
 * Zap Cache is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Zap Cache is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Zap Cache. If not, see https://www.gnu.org/licenses/gpl-3.0.txt.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ZAP_VERSION', '1.0.0');
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

        // Common Cache Plugins Support
        $cache_plugins = array(
            'w3tc' => 'w3tc_flush_all',
            'wp_super_cache' => 'wp_cache_clear_cache',
            'wp_rocket' => 'rocket_clean_domain',
            'autoptimize' => array('autoptimizeCache', 'clearall')
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

        // Fire action after cache purge
        do_action('zap_after_cache_purge');

        wp_send_json_success($results);

    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
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
    // Activation tasks if needed
}

// Register deactivation hook
register_deactivation_hook(__FILE__, 'zap_deactivate');
function zap_deactivate() {
    // Cleanup tasks if needed
}
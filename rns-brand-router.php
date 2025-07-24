<?php
/**
 * Plugin Name: RNS Brand Router by Falls Tech
 * Description: A shortcode generation tool that generates a brand page with a grid of brands, filtered by brand category from the URL. It also includes a slider for the top brands based on product count. As of v1.2 - Includes Automatic Updates.
 * Version: 1.2.0
 * Author: Ryan T. M. Reiffenberger
 * Author URI: https://www.fallstech.group
 * Plugin URL: https://docs.reiffenberger.net
 * Requires at least: 5.0
 * Tested up to: 6.7
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: rns-brand-router
 * Domain Path: /languages
 *
 * @package RNS_Brand_Router
 * @author Ryan T. M. Reiffenberger
 * @since 1.0.0
 */

defined('ABSPATH') || exit;

/**
 * Plugin constants
 */
define('RNS_BRAND_ROUTER_VERSION', '1.2.0');
define('RNS_BRAND_ROUTER_PLUGIN_FILE', __FILE__);
define('RNS_BRAND_ROUTER_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('RNS_BRAND_ROUTER_GITHUB_REPO', 'ReclaimerGold/rns-brand-router');

/**
 * Initialize the update checker early to ensure AJAX handlers are registered
 */
add_action('init', 'rns_brand_router_init_updater');

/**
 * Initialize the RNS Brand Router updater
 *
 * @since 1.2.0
 */
function rns_brand_router_init_updater() {
    $updater_file = plugin_dir_path(__FILE__) . 'includes/class-rns-updater.php';
    if (file_exists($updater_file)) {
        require_once($updater_file);
        if (class_exists('RNS_Brand_Router_Updater')) {
            new RNS_Brand_Router_Updater();
        }
    }
}

/**
 * Register AJAX handlers early to ensure they're available
 */
add_action('wp_ajax_rns_dismiss_update_notice', 'rns_dismiss_update_notice_handler');
add_action('wp_ajax_rns_check_update', 'rns_check_update_fallback_handler');

/**
 * AJAX handler for dismissing update notices
 *
 * @since 1.2.0
 */
function rns_dismiss_update_notice_handler() {
    check_ajax_referer('rns_dismiss_notice', 'nonce');
    update_option('rns_brand_router_update_notice_dismissed', true);
    wp_die();
}

/**
 * Fallback AJAX handler for update checking
 * Ensures the AJAX endpoint is available even if the class isn't loaded yet
 *
 * @since 1.2.0
 */
function rns_check_update_fallback_handler() {
    check_ajax_referer('rns_check_update_nonce', 'nonce');
    
    if (!current_user_can('update_plugins')) {
        wp_send_json_error(array('message' => 'You do not have sufficient permissions to update plugins.'));
        return;
    }
    
    rns_brand_router_init_updater();
    
    if (class_exists('RNS_Brand_Router_Updater')) {
        $updater = new RNS_Brand_Router_Updater();
        if (method_exists($updater, 'ajax_check_update')) {
            $updater->ajax_check_update();
            return;
        }
    }
    
    wp_send_json_error(array('message' => 'Update checker is not available.'));
}

/**
 * Initialize plugin hooks and shortcodes
 */
add_action('wp_enqueue_scripts', 'rns_brand_router_styles');
add_shortcode('rns_brand_router', 'rns_brand_router_shortcode');
add_shortcode('rns_brand_slider', 'rns_brand_slider_shortcode');

/**
 * Generate brand grid shortcode
 * 
 * Creates a responsive grid of brands with product counts, optionally filtered by category
 *
 * @since 1.0.0
 * @param array $atts Shortcode attributes
 * @return string HTML output for brand grid
 */
function rns_brand_router_shortcode($atts) {
    $brand_cat_slug = isset($_GET['brand_cat']) ? sanitize_text_field($_GET['brand_cat']) : '';

    $product_ids_in_category = [];
    $fetch_all_brands = false;

    if (!empty($brand_cat_slug)) {
        $brand_cat_term = get_term_by('slug', $brand_cat_slug, 'product_cat');
        if (!$brand_cat_term) {
            return '<p>Invalid category specified.</p>';
        }

        $product_args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'tax_query' => [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'id',
                    'terms'    => $brand_cat_term->term_id,
                ],
            ],
            'fields' => 'ids',
        ];
        $product_ids_in_category = get_posts($product_args);

        if (empty($product_ids_in_category)) {
            return '<p>No products found for this category.</p>';
        }
    } else {
        $fetch_all_brands = true;
        $all_product_args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'status' => 'publish'
        ];
        $product_ids_in_category = get_posts($all_product_args);

        if (empty($product_ids_in_category)) {
            return '<p>No products found on the site.</p>';
        }
    }

    $brand_product_counts = [];
    foreach ($product_ids_in_category as $product_id) {
        $product_brands = wp_get_post_terms($product_id, 'product_brand');
        foreach ($product_brands as $brand) {
            if (!isset($brand_product_counts[$brand->term_id])) {
                $brand_product_counts[$brand->term_id] = 0;
            }
            $brand_product_counts[$brand->term_id]++;
        }
    }

    $brand_ids = array_keys($brand_product_counts);

    $brands = get_terms([
        'taxonomy' => 'product_brand',
        'include'  => $brand_ids,
        'hide_empty' => false,
    ]);

    if (empty($brands) || is_wp_error($brands)) {
        return '<p>No brands found.</p>';
    }

    usort($brands, function($a, $b) {
        return strcmp($a->name, $b->name);
    });

    $output = '<div class="rns-brand-grid">';
    foreach ($brands as $brand) {
        $thumbnail_id = get_term_meta($brand->term_id, 'thumbnail_id', true);
        $image = wp_get_attachment_image_url($thumbnail_id, 'medium');
        $product_count = isset($brand_product_counts[$brand->term_id]) ? $brand_product_counts[$brand->term_id] : 0;

        if (!empty($brand_cat_slug)) {
            $brand_link = home_url("/shop/brand-" . esc_attr($brand->slug) . "/prodcat-" . esc_attr($brand_cat_slug) . "/");
        } else {
            $brand_link = get_term_link($brand);
        }

        if ($product_count > 0 || $fetch_all_brands) {
            $output .= '<div class="rns-brand-box">';
            $output .= '<a href="' . esc_url($brand_link) . '" class="rns-brand-link-block">';
            
            $output .= '<div class="rns-brand-logo-wrapper">';
            if ($image) {
                $output .= '<img src="' . esc_url($image) . '" alt="' . esc_attr($brand->name) . '">';
            }
            $output .= '</div>';

            $output .= '<p class="rns-brand-title">' . esc_html($brand->name);
            if ($product_count > 0) {
                $output .= ' <span class="rns-brand-count">(' . $product_count . ')</span>';
            }
            $output .= '</p>';
            $output .= '</a>';
            $output .= '</div>';
        }
    }
    $output .= '</div>';
    
    return $output;
}

/**
 * Generate brand slider shortcode
 * 
 * Creates a slider displaying up to 40 top brands with images, randomized order
 *
 * @since 1.1.2
 * @param array $atts Shortcode attributes
 * @return string HTML output for brand slider
 */
function rns_brand_slider_shortcode($atts) {
    $brand_cat_slug = isset($_GET['brand_cat']) ? sanitize_text_field($_GET['brand_cat']) : '';

    $product_ids_in_category = [];
    $fetch_all_brands = false;

    if (!empty($brand_cat_slug)) {
        $brand_cat_term = get_term_by('slug', $brand_cat_slug, 'product_cat');
        if (!$brand_cat_term) {
            return '<p>Invalid category specified.</p>';
        }

        $product_args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'tax_query' => [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'id',
                    'terms'    => $brand_cat_term->term_id,
                ],
            ],
            'fields' => 'ids',
        ];
        $product_ids_in_category = get_posts($product_args);

        if (empty($product_ids_in_category)) {
            return '<p>No products found for this category.</p>';
        }
    } else {
        $fetch_all_brands = true;
        $all_product_args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'status' => 'publish'
        ];
        $product_ids_in_category = get_posts($all_product_args);

        if (empty($product_ids_in_category)) {
            return '<p>No products found on the site.</p>';
        }
    }

    $brand_product_counts = [];
    foreach ($product_ids_in_category as $product_id) {
        $product_brands = wp_get_post_terms($product_id, 'product_brand');
        foreach ($product_brands as $brand) {
            if (!isset($brand_product_counts[$brand->term_id])) {
                $brand_product_counts[$brand->term_id] = 0;
            }
            $brand_product_counts[$brand->term_id]++;
        }
    }

    arsort($brand_product_counts);
    
    $extended_brand_ids = array_slice(array_keys($brand_product_counts), 0, 50, true);

    if (empty($extended_brand_ids)) {
        return '<p>No brands found.</p>';
    }

    $all_brands = get_terms([
        'taxonomy' => 'product_brand',
        'include'  => $extended_brand_ids,
        'hide_empty' => false,
    ]);

    if (empty($all_brands) || is_wp_error($all_brands)) {
        return '<p>No brands found.</p>';
    }

    $brands_with_images = [];
    foreach ($all_brands as $brand) {
        $thumbnail_id = get_term_meta($brand->term_id, 'thumbnail_id', true);
        $image = wp_get_attachment_image_url($thumbnail_id, 'medium');
        
        if ($image) {
            $brands_with_images[] = $brand;
            if (count($brands_with_images) >= 40) {
                break;
            }
        }
    }

    if (empty($brands_with_images)) {
        return '<p>No brands with images found.</p>';
    }

    shuffle($brands_with_images);

    $output = '<div class="rns-brand-slider-container">';
    $output .= '<div class="rns-brand-slider">';
    
    foreach ($brands_with_images as $brand) {
        $thumbnail_id = get_term_meta($brand->term_id, 'thumbnail_id', true);
        $image = wp_get_attachment_image_url($thumbnail_id, 'medium');
        $product_count = isset($brand_product_counts[$brand->term_id]) ? $brand_product_counts[$brand->term_id] : 0;

        if (!empty($brand_cat_slug)) {
            $brand_link = home_url("/shop/brand-" . esc_attr($brand->slug) . "/prodcat-" . esc_attr($brand_cat_slug) . "/");
        } else {
            $brand_link = get_term_link($brand);
        }

        $output .= '<div class="rns-brand-slide">';
        $output .= '<a href="' . esc_url($brand_link) . '" class="rns-brand-slide-link">';
        
        $output .= '<div class="rns-brand-slide-logo-wrapper">';
        $output .= '<img src="' . esc_url($image) . '" alt="' . esc_attr($brand->name) . '">';
        $output .= '</div>';
        
        $output .= '</a>';
        $output .= '</div>';
    }
    
    $output .= '</div>';
    $output .= '</div>';
    
    return $output;
}

/**
 * Enqueue frontend styles and scripts
 *
 * @since 1.0.0
 */
function rns_brand_router_styles() {
    if (!is_admin()) {
        wp_enqueue_style('rns-brand-router-style', plugins_url('style.css', __FILE__), array(), '1.5');
        wp_enqueue_script('rns-brand-router-script', plugins_url('script.js', __FILE__), array(), '1.0', true);
    }
}

/**
 * Enqueue admin scripts for update checker
 */
add_action('admin_enqueue_scripts', 'rns_brand_router_admin_scripts');

/**
 * Enqueue admin scripts and localize variables
 *
 * @since 1.2.0
 * @param string $hook Current admin page hook
 */
function rns_brand_router_admin_scripts($hook) {
    if ($hook === 'plugins.php') {
        wp_enqueue_script('rns-brand-router-admin', plugins_url('script.js', __FILE__), array('jquery'), '1.1', true);
        
        wp_localize_script('rns-brand-router-admin', 'rns_updater_vars', array(
            'nonce' => wp_create_nonce('rns_check_update_nonce'),
            'current_version' => RNS_BRAND_ROUTER_VERSION,
            'ajaxurl' => admin_url('admin-ajax.php')
        ));
    }
}
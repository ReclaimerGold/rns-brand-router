<?php
/*
Plugin Name: RNS Brand Router
Description: A shortcode generation tool that generates a brand page with a grid of brands, filtered by brand category from the URL. It also includes a slider for the top brands based on product count. As of v1.2.0 - Includes Automatic Updates.
Version: 1.2.0-rc1.1
Author: Ryan T. M. Reiffenberger
Author URI: https://www.fallstech.group
Plugin URL: https://docs.reiffenberger.net
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('RNS_BRAND_ROUTER_VERSION', '1.2.0-rc1.1');
define('RNS_BRAND_ROUTER_PLUGIN_FILE', __FILE__);
define('RNS_BRAND_ROUTER_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('RNS_BRAND_ROUTER_GITHUB_REPO', 'ReclaimerGold/rns-brand-router');

// Initialize the update checker
add_action('plugins_loaded', 'rns_brand_router_init_updater');
function rns_brand_router_init_updater() {
    $updater_file = plugin_dir_path(__FILE__) . 'includes/class-rns-updater.php';
    if (file_exists($updater_file)) {
        require_once($updater_file);
        if (class_exists('RNS_Brand_Router_Updater')) {
            new RNS_Brand_Router_Updater();
        }
    }
}

// Also register the AJAX handler separately to ensure it's always available
add_action('wp_ajax_rns_dismiss_update_notice', 'rns_dismiss_update_notice_handler');

function rns_dismiss_update_notice_handler() {
    check_ajax_referer('rns_dismiss_notice', 'nonce');
    update_option('rns_brand_router_update_notice_dismissed', true);
    wp_die();
}

// Enqueue styles directly, not inside the shortcode function
add_action('wp_enqueue_scripts', 'rns_brand_router_styles');
add_shortcode('rns_brand_router', 'rns_brand_router_shortcode');
add_shortcode('rns_brand_slider', 'rns_brand_slider_shortcode');

function rns_brand_router_shortcode($atts) {
    // Get the brand_cat parameter from the URL
    $brand_cat_slug = isset($_GET['brand_cat']) ? sanitize_text_field($_GET['brand_cat']) : '';

    $product_ids_in_category = [];
    $fetch_all_brands = false;

    // Check if a specific brand category is requested
    if (!empty($brand_cat_slug)) {
        // Get term ID of the specified category
        $brand_cat_term = get_term_by('slug', $brand_cat_slug, 'product_cat');
        if (!$brand_cat_term) {
            return '<p>Invalid category specified.</p>';
        }

        // Get products in the specified category
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
            'fields' => 'ids', // Only get product IDs for efficiency
        ];
        $product_ids_in_category = get_posts($product_args);

        if (empty($product_ids_in_category)) {
            return '<p>No products found for this category.</p>';
        }
    } else {
        // If no brand_cat is specified, we'll fetch all brands
        $fetch_all_brands = true;
        // If fetching all brands, we need to get all product IDs to count brands
        $all_product_args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'status' => 'publish' // Only get published products
        ];
        $product_ids_in_category = get_posts($all_product_args);

        if (empty($product_ids_in_category)) {
            return '<p>No products found on the site.</p>';
        }
    }

    // Get brands associated with these products and count them
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

    // Filter brand_ids to only include brands that actually have products (either in category or globally)
    $brand_ids = array_keys($brand_product_counts);

    // Fetch the brand details
    $brands = get_terms([
        'taxonomy' => 'product_brand',
        'include'  => $brand_ids,
        'hide_empty' => false, // Set to true if you only want brands with products to show when listing all
    ]);

    if (empty($brands) || is_wp_error($brands)) {
        return '<p>No brands found.</p>';
    }

    // Sort brands alphabetically by name
    usort($brands, function($a, $b) {
        return strcmp($a->name, $b->name);
    });

    $output = '<div class="rns-brand-grid">';
    foreach ($brands as $brand) {
        $thumbnail_id = get_term_meta($brand->term_id, 'thumbnail_id', true);
        $image = wp_get_attachment_image_url($thumbnail_id, 'medium');
        $product_count = isset($brand_product_counts[$brand->term_id]) ? $brand_product_counts[$brand->term_id] : 0;

        // Construct the custom brand link
        // If a brand category is specified in the URL, use it in the link.
        // Otherwise, you might want a default or just the brand link.
        if (!empty($brand_cat_slug)) {
            $brand_link = home_url("/shop/brand-" . esc_attr($brand->slug) . "/prodcat-" . esc_attr($brand_cat_slug) . "/");
        } else {
            // Fallback: If no product category is in the URL, link directly to the brand's archive.
            // You might want to adjust this fallback based on your preference.
            $brand_link = get_term_link($brand);
        }

        // Only display brands that have at least one product associated with them
        if ($product_count > 0 || $fetch_all_brands) { // If fetching all, we want to show all available brands regardless of this specific category
            $output .= '<div class="rns-brand-box">';
            $output .= '<a href="' . esc_url($brand_link) . '" class="rns-brand-link-block">';
            
            $output .= '<div class="rns-brand-logo-wrapper">';
            if ($image) {
                $output .= '<img src="' . esc_url($image) . '" alt="' . esc_attr($brand->name) . '">';
            }
            $output .= '</div>'; // Close rns-brand-logo-wrapper

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

function rns_brand_slider_shortcode($atts) {
    // Get the brand_cat parameter from the URL
    $brand_cat_slug = isset($_GET['brand_cat']) ? sanitize_text_field($_GET['brand_cat']) : '';

    $product_ids_in_category = [];
    $fetch_all_brands = false;

    // Check if a specific brand category is requested
    if (!empty($brand_cat_slug)) {
        // Get term ID of the specified category
        $brand_cat_term = get_term_by('slug', $brand_cat_slug, 'product_cat');
        if (!$brand_cat_term) {
            return '<p>Invalid category specified.</p>';
        }

        // Get products in the specified category
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
            'fields' => 'ids', // Only get product IDs for efficiency
        ];
        $product_ids_in_category = get_posts($product_args);

        if (empty($product_ids_in_category)) {
            return '<p>No products found for this category.</p>';
        }
    } else {
        // If no brand_cat is specified, we'll fetch all brands
        $fetch_all_brands = true;
        // If fetching all brands, we need to get all product IDs to count brands
        $all_product_args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'status' => 'publish' // Only get published products
        ];
        $product_ids_in_category = get_posts($all_product_args);

        if (empty($product_ids_in_category)) {
            return '<p>No products found on the site.</p>';
        }
    }

    // Get brands associated with these products and count them
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

    // Sort brands by product count (descending) and filter for brands with images
    arsort($brand_product_counts);
    
    // Get more brands initially to ensure we have 12 with images
    $extended_brand_ids = array_slice(array_keys($brand_product_counts), 0, 50, true); // Get top 50 initially

    if (empty($extended_brand_ids)) {
        return '<p>No brands found.</p>';
    }

    // Fetch the brand details for extended list
    $all_brands = get_terms([
        'taxonomy' => 'product_brand',
        'include'  => $extended_brand_ids,
        'hide_empty' => false,
    ]);

    if (empty($all_brands) || is_wp_error($all_brands)) {
        return '<p>No brands found.</p>';
    }

    // Filter brands to only include those with images and get exactly 40
    $brands_with_images = [];
    foreach ($all_brands as $brand) {
        $thumbnail_id = get_term_meta($brand->term_id, 'thumbnail_id', true);
        $image = wp_get_attachment_image_url($thumbnail_id, 'medium');
        
        if ($image) {
            $brands_with_images[] = $brand;
            // Stop when we have 40 brands with images
            if (count($brands_with_images) >= 40) {
                break;
            }
        }
    }

    if (empty($brands_with_images)) {
        return '<p>No brands with images found.</p>';
    }

    // Randomize the order of the selected brands
    shuffle($brands_with_images);

    $output = '<div class="rns-brand-slider-container">';
    $output .= '<div class="rns-brand-slider">';
    
    foreach ($brands_with_images as $brand) {
        $thumbnail_id = get_term_meta($brand->term_id, 'thumbnail_id', true);
        $image = wp_get_attachment_image_url($thumbnail_id, 'medium');
        $product_count = isset($brand_product_counts[$brand->term_id]) ? $brand_product_counts[$brand->term_id] : 0;

        // Construct the custom brand link (same logic as grid shortcode)
        if (!empty($brand_cat_slug)) {
            $brand_link = home_url("/shop/brand-" . esc_attr($brand->slug) . "/prodcat-" . esc_attr($brand_cat_slug) . "/");
        } else {
            $brand_link = get_term_link($brand);
        }

        $output .= '<div class="rns-brand-slide">';
        $output .= '<a href="' . esc_url($brand_link) . '" class="rns-brand-slide-link">';
        
        $output .= '<div class="rns-brand-slide-logo-wrapper">';
        // We know $image exists since we filtered for it
        $output .= '<img src="' . esc_url($image) . '" alt="' . esc_attr($brand->name) . '">';
        $output .= '</div>'; // Close rns-brand-slide-logo-wrapper
        
        $output .= '</a>';
        $output .= '</div>'; // Close rns-brand-slide
    }
    
    $output .= '</div>'; // Close rns-brand-slider
    $output .= '</div>'; // Close rns-brand-slider-container
    
    return $output;
}

// Ensure styles are enqueued only when needed
function rns_brand_router_styles() {
    if (! is_admin()) { // Ensures it's enqueued for frontend only
        wp_enqueue_style('rns-brand-router-style', plugins_url('style.css', __FILE__), array(), '1.5'); // Increment version
        wp_enqueue_script('rns-brand-router-script', plugins_url('script.js', __FILE__), array(), '1.0', true);
    }
}

// Enqueue admin scripts for update checker
add_action('admin_enqueue_scripts', 'rns_brand_router_admin_scripts');

function rns_brand_router_admin_scripts($hook) {
    // Only load on plugins page
    if ($hook === 'plugins.php') {
        wp_enqueue_script('rns-brand-router-admin', plugins_url('script.js', __FILE__), array('jquery'), '1.1', true);
        
        // Localize script with necessary variables
        wp_localize_script('rns-brand-router-admin', 'rns_updater_vars', array(
            'nonce' => wp_create_nonce('rns_check_update_nonce'),
            'current_version' => RNS_BRAND_ROUTER_VERSION,
            'ajaxurl' => admin_url('admin-ajax.php')
        ));
    }
}
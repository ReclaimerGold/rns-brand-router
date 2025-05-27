<?php
/*
Plugin Name: RNS Brand Router
Description: Displays WooCommerce brands in a responsive grid filtered by brand category from the URL.
Version: 1.0
Author: Ryan T. M. Reiffenberger
*/

// Enqueue styles directly, not inside the shortcode function
add_action('wp_enqueue_scripts', 'rns_brand_router_styles');
add_shortcode('rns_brand_router', 'rns_brand_router_shortcode');

function rns_brand_router_shortcode($atts) {
    // Get the brand_cat parameter from the URL
    $brand_cat_slug = isset($_GET['brand_cat']) ? sanitize_text_field($_GET['brand_cat']) : '';

    // No need for brand specific taxonomy query, instead focus on product category
    if (empty($brand_cat_slug)) {
        return '<p>No category specified.</p>';
    }

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
    ];
    $products = get_posts($product_args);

    if (empty($products)) {
        return '<p>No products found for this category.</p>';
    }

    // Get brands associated with these products
    $brand_ids = [];
    foreach ($products as $product) {
        $product_brands = wp_get_post_terms($product->ID, 'product_brand');
        foreach ($product_brands as $brand) {
            $brand_ids[] = $brand->term_id;
        }
    }
    $brand_ids = array_unique($brand_ids);

    // Fetch the brand details
    $brands = get_terms([
        'taxonomy' => 'product_brand',
        'include'  => $brand_ids,
        'hide_empty' => false,
    ]);

    if (empty($brands) || is_wp_error($brands)) {
        return '<p>No brands found for this category.</p>';
    }

    $output = '<div class="rns-brand-grid">';
    foreach ($brands as $brand) {
        $brand_link = get_term_link($brand);
        $thumbnail_id = get_term_meta($brand->term_id, 'thumbnail_id', true);
        $image = wp_get_attachment_image_url($thumbnail_id, 'medium');

        $output .= '<div class="rns-brand-box">';
        // The entire block is now the link
        $output .= '<a href="' . esc_url($brand_link) . '" class="rns-brand-link-block">';
        
        // Wrapper for the logo to control its centering
        $output .= '<div class="rns-brand-logo-wrapper">';
        if ($image) {
            $output .= '<img src="' . esc_url($image) . '" alt="' . esc_attr($brand->name) . '">';
        }
        $output .= '</div>'; // Close rns-brand-logo-wrapper

        $output .= '<p class="rns-brand-title">' . esc_html($brand->name) . '</p>';
        $output .= '</a>';
        $output .= '</div>';
    }
    $output .= '</div>';
    
    return $output;
}

// Ensure styles are enqueued only when needed
function rns_brand_router_styles() {
    if (! is_admin()) { // Ensures it's enqueued for frontend only
        wp_enqueue_style('rns-brand-router-style', plugins_url('style.css', __FILE__), array(), '1.2'); // Increment version for cache busting
    }
}
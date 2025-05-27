<?php
/*
Plugin Name: RNS Brand Router
Description: Displays WooCommerce brands in a responsive grid filtered by brand category from the URL.
Version: 1.0
Author: Ryan T. M. Reiffenberger
*/

add_shortcode('rns_brand_router', 'rns_brand_router_shortcode');

function rns_brand_router_shortcode($atts) {
    // Enqueue styles
    add_action('wp_enqueue_scripts', 'rns_brand_router_styles');

    // Get the brand_cat parameter from the URL
    $brand_cat_slug = isset($_GET['brand_cat']) ? sanitize_text_field($_GET['brand_cat']) : '';

    // Setup arguments for getting product brands
    $args = [
        'taxonomy'   => 'product_brand',
        'hide_empty' => false,
    ];

    // If a brand_cat filter exists
    if (!empty($brand_cat_slug)) {
        $brand_cat_term = get_term_by('slug', $brand_cat_slug, 'product_brand_cat');
        if ($brand_cat_term) {
            $args['child_of'] = $brand_cat_term->term_id;
        }
    }

    $brands = get_terms($args);

    if (empty($brands) || is_wp_error($brands)) {
        return '<p>No brands found for this category.</p>';
    }

    $output = '<div class="rns-brand-grid">';

    foreach ($brands as $brand) {
        $brand_link = get_term_link($brand);
        $thumbnail_id = get_term_meta($brand->term_id, 'thumbnail_id', true);
        $image = wp_get_attachment_image_url($thumbnail_id, 'medium');

        $output .= '<div class="rns-brand-box">';
        if ($image) {
            $output .= '<a href="' . esc_url($brand_link) . '"><img src="' . esc_url($image) . '" alt="' . esc_attr($brand->name) . '"></a>';
        }
        $output .= '<p><a href="' . esc_url($brand_link) . '">' . esc_html($brand->name) . '</a></p>';
        $output .= '</div>';
    }

    $output .= '</div>';
    return $output;
}

function rns_brand_router_styles() {
    wp_enqueue_style('rns-brand-router-style', plugins_url('style.css', __FILE__));
}

# RNS Brand Router

A WordPress plugin that creates dynamic brand displays for WooCommerce stores with both grid and slider layouts.

## Description

RNS Brand Router is a powerful WordPress plugin designed for WooCommerce stores that need to display product brands in an organized and visually appealing way. The plugin provides two main display modes: a responsive grid layout and an animated slider carousel, both of which can be filtered by product categories via URL parameters.

## Features

### 🎯 **Dual Display Modes**
- **Grid Layout**: Responsive brand grid with product counts
- **Slider Carousel**: Animated horizontal slider showcasing top brands

### 🔄 **Dynamic Filtering**
- Filter brands by product category using URL parameters (`?brand_cat=category-slug`)
- Automatic fallback to all brands when no category is specified

### 📱 **Responsive Design**
- **Grid**: 2 columns (mobile) → 4 columns (tablet) → 6 columns (desktop)
- **Slider**: Continuous horizontal flow with consistent 200px tiles and 20px spacing

### ⚡ **Performance Optimized**
- Only displays brands that have associated products
- Efficient database queries using product IDs
- Image validation to ensure visual consistency

### 🎨 **Customizable Styling**
- Clean, modern design with rounded corners
- Hover effects and smooth animations
- Customizable via CSS

## Installation

1. Download the plugin files to your WordPress plugins directory:
   ```
   /wp-content/plugins/rns-brand-router/
   ```

2. Activate the plugin through the 'Plugins' menu in WordPress

3. Ensure you have WooCommerce installed and active

4. Make sure your products have brand taxonomy (`product_brand`) assigned

## Usage

### Grid Display
Use the grid shortcode to display brands in a responsive grid layout:

```php
[rns_brand_router]
```

### Slider Display
Use the slider shortcode to display top brands in an animated carousel:

```php
[rns_brand_slider]
```

### URL Filtering
Both shortcodes support category filtering via URL parameters:

```
https://yoursite.com/brands/?brand_cat=electronics
```

This will show only brands that have products in the "electronics" category.

## Configuration

### Brand Requirements
- Products must be assigned to the `product_brand` taxonomy
- Brands should have thumbnail images for optimal display
- Only published products are counted

### Slider Specifications
- Displays top 40 brands by product count
- 30-second animation cycle
- Only includes brands with thumbnail images
- Randomized order for variety

### Grid Specifications
- Shows all brands with products (filtered by category if specified)
- Displays product counts for each brand
- Alphabetically sorted by brand name

## Customization

### CSS Classes
The plugin provides several CSS classes for customization:

**Grid Layout:**
- `.rns-brand-grid` - Main grid container
- `.rns-brand-box` - Individual brand boxes
- `.rns-brand-link-block` - Clickable brand links
- `.rns-brand-logo-wrapper` - Logo container
- `.rns-brand-title` - Brand name text
- `.rns-brand-count` - Product count display

**Slider Layout:**
- `.rns-brand-slider-container` - Main slider container
- `.rns-brand-slider` - Animated slider track
- `.rns-brand-slide` - Individual slide items
- `.rns-brand-slide-link` - Clickable slide links
- `.rns-brand-slide-logo-wrapper` - Slide logo container

### Custom Styling
You can override the default styles by adding CSS to your theme:

```css
/* Example: Customize slider tile size */
.rns-brand-slide-link {
    width: 250px; /* Default: 200px */
}

/* Example: Change grid hover effects */
.rns-brand-box:hover {
    transform: translateY(-5px);
    transition: transform 0.3s ease;
}
```

## URL Routing

The plugin generates custom brand URLs following this pattern:

**With Category Filter:**
```
/shop/brand-{brand-slug}/prodcat-{category-slug}/
```

**Without Category Filter:**
```
{brand-archive-url}
```

## Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.4+
- Product Brand taxonomy (typically provided by WooCommerce extensions)

## File Structure

```
rns-brand-router/
├── rns-brand-router.php    # Main plugin file
├── style.css              # Plugin styles
├── script.js              # JavaScript functionality
└── README.md              # Documentation
```

## Changelog

### Version 1.1.0
- Added slider shortcode functionality
- Implemented image validation for brands
- Enhanced responsive design
- Added continuous horizontal flow slider
- Improved performance with optimized queries

### Version 1.0.2
- Initial grid layout implementation
- URL parameter filtering
- Basic responsive design
- Product count display

## Support

For support, feature requests, or bug reports, please visit:
- **Documentation**: [https://docs.reiffenberger.com](https://docs.reiffenberger.com)
- **Repository**: [https://github.com/ReclaimerGold/rns-brand-router](https://github.com/ReclaimerGold/rns-brand-router)

## Author

**Ryan T. M. Reiffenberger**
- Website: [https://docs.reiffenberger.com](https://docs.reiffenberger.com)
- GitHub: [@ReclaimerGold](https://github.com/ReclaimerGold)

## License

This plugin is licensed under the GPL v2 or later.

---

*Built with ❤️ for WooCommerce store owners who want beautiful brand displays.*

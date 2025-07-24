<?php
/**
 * RNS Brand Router Update Checker
 * 
 * Checks for updates from GitHub repository and integrates with WordPress update system
 */

if (!defined('ABSPATH')) {
    exit;
}

class RNS_Brand_Router_Updater {
    
    private $plugin_file;
    private $plugin_basename;
    private $version;
    private $github_repo;
    private $github_api_url;
    private $transient_key;
    
    public function __construct() {
        $this->plugin_file = RNS_BRAND_ROUTER_PLUGIN_FILE;
        $this->plugin_basename = RNS_BRAND_ROUTER_PLUGIN_BASENAME;
        $this->version = RNS_BRAND_ROUTER_VERSION;
        $this->github_repo = RNS_BRAND_ROUTER_GITHUB_REPO;
        $this->github_api_url = 'https://api.github.com/repos/' . $this->github_repo;
        $this->transient_key = 'rns_brand_router_update_check';
        
        $this->init_hooks();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_action('upgrader_process_complete', array($this, 'purge_cache'), 10, 2);
        
        // Add update checker to admin
        add_action('admin_init', array($this, 'admin_init'));
        add_action('wp_ajax_rns_check_update', array($this, 'ajax_check_update'));
        
        // Show admin notice about update checker
        add_action('admin_notices', array($this, 'update_checker_notice'));
    }
    
    /**
     * Admin initialization
     */
    public function admin_init() {
        // Add manual update check button to plugins page
        add_action('after_plugin_row_' . $this->plugin_basename, array($this, 'show_update_row'), 10, 2);
    }
    
    /**
     * Check for plugin updates
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        // Get cached version info
        $version_info = $this->get_cached_version_info();
        
        if ($version_info === false) {
            $version_info = $this->get_remote_version();
            if ($version_info !== false) {
                set_transient($this->transient_key, $version_info, 12 * HOUR_IN_SECONDS);
            }
        }
        
        if ($version_info !== false && version_compare($this->version, $version_info['new_version'], '<')) {
            $transient->response[$this->plugin_basename] = (object) array(
                'slug' => dirname($this->plugin_basename),
                'new_version' => $version_info['new_version'],
                'url' => $version_info['details_url'],
                'package' => $version_info['download_url'],
                'tested' => $version_info['tested'],
                'requires_php' => $version_info['requires_php'],
                'compatibility' => new stdClass(),
            );
        }
        
        return $transient;
    }
    
    /**
     * Get cached version info
     */
    private function get_cached_version_info() {
        return get_transient($this->transient_key);
    }
    
    /**
     * Get remote version from GitHub
     */
    private function get_remote_version() {
        $request = wp_remote_get($this->github_api_url . '/releases/latest', array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
            )
        ));
        
        if (is_wp_error($request) || wp_remote_retrieve_response_code($request) !== 200) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($request);
        $data = json_decode($body, true);
        
        if (empty($data) || !isset($data['tag_name'])) {
            return false;
        }
        
        // Clean version number (remove 'v' prefix if present)
        $version = ltrim($data['tag_name'], 'v');
        
        $version_info = array(
            'new_version' => $version,
            'details_url' => $data['html_url'],
            'download_url' => $this->get_download_url($data),
            'tested' => get_bloginfo('version'),
            'requires_php' => '7.4',
            'last_updated' => $data['published_at'],
            'changelog' => $this->parse_changelog($data['body']),
        );
        
        return $version_info;
    }
    
    /**
     * Get download URL from release data
     */
    private function get_download_url($release_data) {
        // First try to find a .zip asset
        if (isset($release_data['assets']) && is_array($release_data['assets'])) {
            foreach ($release_data['assets'] as $asset) {
                if (strpos($asset['name'], '.zip') !== false) {
                    return $asset['browser_download_url'];
                }
            }
        }
        
        // Fallback to zipball URL
        return $release_data['zipball_url'];
    }
    
    /**
     * Parse changelog from release body
     */
    private function parse_changelog($body) {
        if (empty($body)) {
            return 'No changelog available.';
        }
        
        // Basic markdown to HTML conversion for display
        $changelog = nl2br(esc_html($body));
        $changelog = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $changelog);
        $changelog = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $changelog);
        $changelog = preg_replace('/^- (.+)$/m', '• $1', $changelog);
        
        return $changelog;
    }
    
    /**
     * Provide plugin information for the update screen
     */
    public function plugin_info($false, $action, $response) {
        if ($action !== 'plugin_information') {
            return $false;
        }
        
        if (empty($response->slug) || $response->slug !== dirname($this->plugin_basename)) {
            return $false;
        }
        
        $version_info = $this->get_cached_version_info();
        if ($version_info === false) {
            $version_info = $this->get_remote_version();
        }
        
        if ($version_info === false) {
            return $false;
        }
        
        $plugin_info = new stdClass();
        $plugin_info->name = 'RNS Brand Router';
        $plugin_info->slug = dirname($this->plugin_basename);
        $plugin_info->version = $version_info['new_version'];
        $plugin_info->author = '<a href="https://www.fallstech.group">Ryan T. M. Reiffenberger</a>';
        $plugin_info->homepage = 'https://docs.reiffenberger.net';
        $plugin_info->short_description = 'A shortcode generation tool that generates a brand page with a grid of brands, filtered by brand category from the URL.';
        $plugin_info->sections = array(
            'description' => 'A shortcode generation tool that generates a brand page with a grid of brands, filtered by brand category from the URL. It also includes a slider for the top 12 brands based on product count.',
            'changelog' => $version_info['changelog'],
        );
        $plugin_info->download_link = $version_info['download_url'];
        $plugin_info->last_updated = $version_info['last_updated'];
        $plugin_info->requires = '5.0';
        $plugin_info->tested = $version_info['tested'];
        $plugin_info->requires_php = $version_info['requires_php'];
        
        return $plugin_info;
    }
    
    /**
     * Purge update cache when plugin is updated
     */
    public function purge_cache($upgrader_object, $options) {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            if (isset($options['plugins']) && in_array($this->plugin_basename, $options['plugins'])) {
                delete_transient($this->transient_key);
            }
        }
    }
    
    /**
     * Show update notification row in plugins page
     */
    public function show_update_row($plugin_file, $plugin_data) {
        if (is_multisite() && !is_main_site()) {
            return;
        }
        
        $version_info = $this->get_cached_version_info();
        if ($version_info === false) {
            return;
        }
        
        if (version_compare($this->version, $version_info['new_version'], '>=')) {
            return;
        }
        
        $wp_list_table = _get_list_table('WP_Plugins_List_Table');
        
        echo '<tr class="plugin-update-tr active" id="' . esc_attr($this->plugin_basename) . '-update" data-slug="' . esc_attr(dirname($this->plugin_basename)) . '" data-plugin="' . esc_attr($this->plugin_basename) . '">';
        echo '<td colspan="' . esc_attr($wp_list_table->get_column_count()) . '" class="plugin-update colspanchange">';
        echo '<div class="update-message notice inline notice-warning notice-alt">';
        echo '<p>';
        printf(
            __('There is a new version of %1$s available. <a href="%2$s" class="thickbox open-plugin-details-modal">View version %3$s details</a> or <a href="%4$s" class="update-link">update now</a>.'),
            esc_html($plugin_data['Name']),
            esc_url(add_query_arg(array(
                'tab' => 'plugin-information',
                'plugin' => dirname($this->plugin_basename),
                'TB_iframe' => 'true',
                'width' => '600',
                'height' => '550'
            ), admin_url('plugin-install.php'))),
            esc_html($version_info['new_version']),
            esc_url(wp_nonce_url(self_admin_url('update.php?action=upgrade-plugin&plugin=' . urlencode($this->plugin_basename)), 'upgrade-plugin_' . $this->plugin_basename))
        );
        echo '</p>';
        echo '</div>';
        echo '</td>';
        echo '</tr>';
    }
    
    /**
     * AJAX handler for manual update check
     */
    public function ajax_check_update() {
        check_ajax_referer('rns_check_update_nonce', 'nonce');
        
        if (!current_user_can('update_plugins')) {
            wp_die(__('You do not have sufficient permissions to update plugins.'));
        }
        
        // Clear cache and force check
        delete_transient($this->transient_key);
        $version_info = $this->get_remote_version();
        
        if ($version_info !== false) {
            set_transient($this->transient_key, $version_info, 12 * HOUR_IN_SECONDS);
            
            if (version_compare($this->version, $version_info['new_version'], '<')) {
                wp_send_json_success(array(
                    'message' => sprintf(__('Update available: version %s'), $version_info['new_version']),
                    'new_version' => $version_info['new_version']
                ));
            } else {
                wp_send_json_success(array(
                    'message' => __('You have the latest version.'),
                    'new_version' => $this->version
                ));
            }
        } else {
            wp_send_json_error(array(
                'message' => __('Unable to check for updates. Please try again later.')
            ));
        }
    }
    
    /**
     * Show admin notice about update checker functionality
     */
    public function update_checker_notice() {
        $screen = get_current_screen();
        if ($screen->id !== 'plugins') {
            return;
        }
        
        // Only show notice once
        if (get_option('rns_brand_router_update_notice_dismissed', false)) {
            return;
        }
        
        echo '<div class="notice notice-info is-dismissible" id="rns-update-notice">';
        echo '<p><strong>RNS Brand Router:</strong> This plugin now includes automatic update checking from GitHub. Updates will appear in your WordPress admin just like other plugins. You can also manually check for updates on the plugins page.</p>';
        echo '<button type="button" class="notice-dismiss" onclick="rnsMarkNoticeDismissed()"><span class="screen-reader-text">Dismiss this notice.</span></button>';
        echo '</div>';
        
        echo '<script>
        function rnsMarkNoticeDismissed() {
            fetch(ajaxurl, {
                method: "POST",
                headers: {"Content-Type": "application/x-www-form-urlencoded"},
                body: new URLSearchParams({
                    action: "rns_dismiss_update_notice",
                    nonce: "' . wp_create_nonce('rns_dismiss_notice') . '"
                })
            });
            document.getElementById("rns-update-notice").style.display = "none";
        }
        </script>';
        
        // Add AJAX handler for dismissing notice
        add_action('wp_ajax_rns_dismiss_update_notice', array($this, 'dismiss_update_notice'));
    }
    
    /**
     * AJAX handler for dismissing update notice
     */
    public function dismiss_update_notice() {
        check_ajax_referer('rns_dismiss_notice', 'nonce');
        update_option('rns_brand_router_update_notice_dismissed', true);
        wp_die();
    }
}

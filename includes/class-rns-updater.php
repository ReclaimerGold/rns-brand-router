<?php
/**
 * RNS Brand Router Update Checker
 * 
 * Handles automatic updates from GitHub repository and integrates with WordPress update system
 *
 * @package RNS_Brand_Router
 * @subpackage Updater
 * @since 1.2.0
 * @author Ryan T. M. Reiffenberger
 */

defined('ABSPATH') || exit;

/**
 * Class RNS_Brand_Router_Updater
 *
 * Manages plugin updates from GitHub releases
 *
 * @since 1.2.0
 */
class RNS_Brand_Router_Updater {
    
    /**
     * Plugin file path
     *
     * @since 1.2.0
     * @var string
     */
    private $plugin_file;
    
    /**
     * Plugin basename
     *
     * @since 1.2.0
     * @var string
     */
    private $plugin_basename;
    
    /**
     * Current plugin version
     *
     * @since 1.2.0
     * @var string
     */
    private $version;
    
    /**
     * GitHub repository
     *
     * @since 1.2.0
     * @var string
     */
    private $github_repo;
    
    /**
     * GitHub API URL
     *
     * @since 1.2.0
     * @var string
     */
    private $github_api_url;
    
    /**
     * Transient cache key
     *
     * @since 1.2.0
     * @var string
     */
    private $transient_key;
    
    /**
     * Constructor
     *
     * @since 1.2.0
     */
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
     *
     * @since 1.2.0
     */
    private function init_hooks() {
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_action('upgrader_process_complete', array($this, 'purge_cache'), 10, 2);
        add_action('admin_init', array($this, 'admin_init'));
        
        if (!has_action('wp_ajax_rns_check_update')) {
            add_action('wp_ajax_rns_check_update', array($this, 'ajax_check_update'));
        }
        
        add_action('admin_notices', array($this, 'update_checker_notice'));
    }
    
    /**
     * Admin initialization
     *
     * @since 1.2.0
     */
    public function admin_init() {
        add_action('after_plugin_row_' . $this->plugin_basename, array($this, 'show_update_row'), 10, 2);
    }
    
    /**
     * Check for plugin updates
     *
     * @since 1.2.0
     * @param object $transient Update transient data
     * @return object Modified transient data
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
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
     * Get cached version information
     *
     * @since 1.2.0
     * @return array|false Version info array or false if not cached
     */
    private function get_cached_version_info() {
        return get_transient($this->transient_key);
    }
    
    /**
     * Get remote version information from GitHub
     *
     * @since 1.2.0
     * @return array|false Version info array or false on failure
     */
    private function get_remote_version() {
        $request = wp_remote_get($this->github_api_url . '/releases/latest', array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
            )
        ));
        
        if (is_wp_error($request)) {
            error_log('RNS Brand Router Update Check Error: ' . $request->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($request);
        if ($response_code !== 200) {
            error_log('RNS Brand Router Update Check Error: HTTP ' . $response_code . ' - ' . wp_remote_retrieve_response_message($request));
            return false;
        }
        
        $body = wp_remote_retrieve_body($request);
        $data = json_decode($body, true);
        
        if (empty($data) || !isset($data['tag_name'])) {
            error_log('RNS Brand Router Update Check Error: Invalid response data');
            return false;
        }
        
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
     *
     * @since 1.2.0
     * @param array $release_data GitHub release data
     * @return string Download URL
     */
    private function get_download_url($release_data) {
        if (isset($release_data['assets']) && is_array($release_data['assets'])) {
            foreach ($release_data['assets'] as $asset) {
                if (strpos($asset['name'], '.zip') !== false) {
                    return $asset['browser_download_url'];
                }
            }
        }
        
        return $release_data['zipball_url'];
    }
    
    /**
     * Parse changelog from release body
     *
     * @since 1.2.0
     * @param string $body Release body text
     * @return string Formatted changelog HTML
     */
    private function parse_changelog($body) {
        if (empty($body)) {
            return 'No changelog available.';
        }
        
        $changelog = nl2br(esc_html($body));
        $changelog = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $changelog);
        $changelog = preg_replace('/\*(.*?)\*/', '<em>$1</em>', $changelog);
        $changelog = preg_replace('/^- (.+)$/m', '• $1', $changelog);
        
        return $changelog;
    }
    
    /**
     * Provide plugin information for the update screen
     *
     * @since 1.2.0
     * @param false|object|array $false
     * @param string $action
     * @param object $response
     * @return false|object Plugin information or false
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
     *
     * @since 1.2.0
     * @param object $upgrader_object
     * @param array $options
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
     *
     * @since 1.2.0
     * @param string $plugin_file
     * @param array $plugin_data
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
     *
     * @since 1.2.0
     */
    public function ajax_check_update() {
        if (!check_ajax_referer('rns_check_update_nonce', 'nonce', false)) {
            wp_send_json_error(array(
                'message' => 'Security check failed. Please refresh the page and try again.'
            ));
            return;
        }
        
        if (!current_user_can('update_plugins')) {
            wp_send_json_error(array(
                'message' => 'You do not have sufficient permissions to update plugins.'
            ));
            return;
        }
        
        delete_transient($this->transient_key);
        $version_info = $this->get_remote_version();
        
        if ($version_info !== false) {
            set_transient($this->transient_key, $version_info, 12 * HOUR_IN_SECONDS);
            
            if (version_compare($this->version, $version_info['new_version'], '<')) {
                wp_send_json_success(array(
                    'message' => sprintf('Update available: version %s', $version_info['new_version']),
                    'new_version' => $version_info['new_version']
                ));
            } else {
                wp_send_json_success(array(
                    'message' => 'You have the latest version.',
                    'new_version' => $this->version
                ));
            }
        } else {
            wp_send_json_error(array(
                'message' => 'Unable to check for updates. Please check the error log for details.'
            ));
        }
    }
    
    /**
     * Show admin notice about update checker functionality
     *
     * @since 1.2.0
     */
    public function update_checker_notice() {
        if (!function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'plugins') {
            return;
        }
        
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
    }
    
    /**
     * AJAX handler for dismissing update notice
     *
     * @since 1.2.0
     */
    public function dismiss_update_notice() {
        check_ajax_referer('rns_dismiss_notice', 'nonce');
        update_option('rns_brand_router_update_notice_dismissed', true);
        wp_die();
    }
}

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
        
        // Auto-update integration
        add_filter('auto_update_plugin', array($this, 'enable_auto_update'), 10, 2);
        add_filter('automatic_updater_disabled', array($this, 'check_auto_updater_disabled'), 10, 1);
        
        // Add update checker to admin
        add_action('admin_init', array($this, 'admin_init'));
        
        // Register AJAX handlers
        if (!has_action('wp_ajax_rns_check_update')) {
            add_action('wp_ajax_rns_check_update', array($this, 'ajax_check_update'));
        }
        if (!has_action('wp_ajax_rns_dismiss_update_notice')) {
            add_action('wp_ajax_rns_dismiss_update_notice', array($this, 'dismiss_update_notice'));
        }
        
        // Show admin notice about update checker
        add_action('admin_notices', array($this, 'update_checker_notice'));
        
        // Auto-update result handling
        add_action('automatic_updates_complete', array($this, 'auto_update_complete'), 10, 1);
        
        // Add settings
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
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
        
        // Only check if our plugin is in the checked list
        if (!isset($transient->checked[$this->plugin_basename])) {
            return $transient;
        }
        
        // Get cached version info
        $version_info = $this->get_cached_version_info();
        
        // Use WordPress's update check frequency (twice daily by default)
        // Only fetch new data if cache is expired or doesn't exist
        if ($version_info === false) {
            $version_info = $this->get_remote_version();
            if ($version_info !== false) {
                // Cache for 12 hours to align with WordPress update checks
                set_transient($this->transient_key, $version_info, 12 * HOUR_IN_SECONDS);
            }
        }
        
        // Add update info if new version is available
        if ($version_info !== false && version_compare($this->version, $version_info['new_version'], '<')) {
            $transient->response[$this->plugin_basename] = (object) array(
                'slug' => dirname($this->plugin_basename),
                'new_version' => $version_info['new_version'],
                'url' => $version_info['details_url'],
                'package' => $version_info['download_url'],
                'tested' => $version_info['tested'],
                'requires_php' => $version_info['requires_php'],
                'compatibility' => new stdClass(),
                'auto_update' => get_option('rns_brand_router_auto_update', false)
            );
            
            // Log that an update is available
            error_log(sprintf(
                'RNS Brand Router: Update available - Current: %s, Available: %s',
                $this->version,
                $version_info['new_version']
            ));
        } else if ($version_info !== false) {
            // Ensure our plugin is marked as up-to-date
            if (isset($transient->response[$this->plugin_basename])) {
                unset($transient->response[$this->plugin_basename]);
            }
            $transient->no_update[$this->plugin_basename] = (object) array(
                'slug' => dirname($this->plugin_basename),
                'new_version' => $this->version,
                'url' => $version_info['details_url'],
                'package' => $version_info['download_url'],
                'tested' => $version_info['tested'],
                'requires_php' => $version_info['requires_php'],
                'compatibility' => new stdClass(),
                'auto_update' => get_option('rns_brand_router_auto_update', false)
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
     * Check if current version exists in GitHub releases
     */
    private function is_version_in_releases() {
        // Check if we have a cached result
        $cache_key = 'rns_brand_router_version_check_' . md5($this->version);
        $cached_result = get_transient($cache_key);
        
        if ($cached_result !== false) {
            return $cached_result === 'true';
        }
        
        $request = wp_remote_get($this->github_api_url . '/releases', array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
            )
        ));
        
        if (is_wp_error($request)) {
            // If we can't check, assume it's valid to avoid false positives
            // Cache the result for 1 hour
            set_transient($cache_key, 'true', HOUR_IN_SECONDS);
            return true;
        }
        
        $response_code = wp_remote_retrieve_response_code($request);
        if ($response_code !== 200) {
            // If we can't check, assume it's valid to avoid false positives
            // Cache the result for 1 hour
            set_transient($cache_key, 'true', HOUR_IN_SECONDS);
            return true;
        }
        
        $body = wp_remote_retrieve_body($request);
        $releases = json_decode($body, true);
        
        if (!is_array($releases)) {
            // If we can't parse, assume it's valid to avoid false positives
            // Cache the result for 1 hour
            set_transient($cache_key, 'true', HOUR_IN_SECONDS);
            return true;
        }
        
        // Clean current version (remove 'v' prefix if present)
        $current_version = ltrim($this->version, 'v');
        
        // Check if current version exists in any release
        foreach ($releases as $release) {
            if (isset($release['tag_name'])) {
                $release_version = ltrim($release['tag_name'], 'v');
                if ($release_version === $current_version) {
                    // Cache positive result for 24 hours
                    set_transient($cache_key, 'true', DAY_IN_SECONDS);
                    return true;
                }
            }
        }
        
        // Cache negative result for 6 hours (shorter than positive to recheck sooner)
        set_transient($cache_key, 'false', 6 * HOUR_IN_SECONDS);
        return false;
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
        $auto_update_enabled = get_option('rns_brand_router_auto_update', false);
        
        echo '<tr class="plugin-update-tr active" id="' . esc_attr($this->plugin_basename) . '-update" data-slug="' . esc_attr(dirname($this->plugin_basename)) . '" data-plugin="' . esc_attr($this->plugin_basename) . '">';
        echo '<td colspan="' . esc_attr($wp_list_table->get_column_count()) . '" class="plugin-update colspanchange">';
        echo '<div class="update-message notice inline notice-warning notice-alt">';
        echo '<p>';
        
        $message = sprintf(
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
        
        echo $message;
        
        // Add auto-update status
        if ($auto_update_enabled) {
            echo '<br><em>Automatic updates are enabled for this plugin.</em>';
        } else {
            echo '<br><em>Automatic updates are disabled. <a href="' . esc_url(admin_url('options-general.php?page=rns-brand-router-settings')) . '">Enable auto-updates</a></em>';
        }
        
        echo '</p>';
        echo '</div>';
        echo '</td>';
        echo '</tr>';
    }
    
    /**
     * AJAX handler for manual update check
     */
    public function ajax_check_update() {
        // Verify nonce
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
        
        // Clear cache and force check
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
     */
    public function update_checker_notice() {
        if (!function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'plugins') {
            return;
        }
        
        // Only show notice once
        if (get_option('rns_brand_router_update_notice_dismissed', false)) {
            return;
        }
        
        $auto_update_enabled = get_option('rns_brand_router_auto_update', false);
        $settings_url = admin_url('options-general.php?page=rns-brand-router-settings');
        
        echo '<div class="notice notice-info is-dismissible" id="rns-update-notice">';
        echo '<p><strong>RNS Brand Router:</strong> This plugin now includes automatic update checking from GitHub. ';
        
        if ($auto_update_enabled) {
            echo 'Automatic updates are <strong>enabled</strong>. ';
        } else {
            echo 'Automatic updates are currently <strong>disabled</strong>. ';
        }
        
        echo 'You can manage these settings in <a href="' . esc_url($settings_url) . '">Settings → RNS Brand Router</a>.</p>';
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
     */
    public function dismiss_update_notice() {
        check_ajax_referer('rns_dismiss_notice', 'nonce');
        update_option('rns_brand_router_update_notice_dismissed', true);
        wp_die();
    }
    
    /**
     * Enable auto-update for this plugin if settings allow
     */
    public function enable_auto_update($update, $item) {
        // Only handle our plugin
        if (!isset($item->plugin) || $item->plugin !== $this->plugin_basename) {
            return $update;
        }
        
        // Check if auto-updates are enabled for this plugin
        $auto_update_enabled = get_option('rns_brand_router_auto_update', false);
        
        // Respect WordPress global auto-update settings
        if (!$auto_update_enabled) {
            return $update;
        }
        
        // Check if automatic updates are globally disabled
        if (defined('AUTOMATIC_UPDATER_DISABLED') && AUTOMATIC_UPDATER_DISABLED) {
            return false;
        }
        
        // Check if plugin auto-updates are disabled
        if (defined('WP_AUTO_UPDATE_CORE') && !WP_AUTO_UPDATE_CORE) {
            return false;
        }
        
        // Log auto-update attempt
        error_log('RNS Brand Router: Auto-update enabled for version ' . $item->new_version);
        
        return true;
    }
    
    /**
     * Check if automatic updater is disabled
     */
    public function check_auto_updater_disabled($disabled) {
        // Don't interfere with global settings
        return $disabled;
    }
    
    /**
     * Handle auto-update completion
     */
    public function auto_update_complete($results) {
        if (!isset($results['plugin']) || empty($results['plugin'])) {
            return;
        }
        
        foreach ($results['plugin'] as $plugin_result) {
            if (isset($plugin_result->item) && $plugin_result->item->plugin === $this->plugin_basename) {
                if ($plugin_result->result === true) {
                    // Success
                    error_log('RNS Brand Router: Auto-update completed successfully to version ' . $plugin_result->item->new_version);
                    
                    // Send success email if enabled
                    if (get_option('rns_brand_router_auto_update_email', false)) {
                        $this->send_update_email(true, $plugin_result->item->new_version);
                    }
                } else {
                    // Failure
                    error_log('RNS Brand Router: Auto-update failed - ' . print_r($plugin_result->messages, true));
                    
                    // Send failure email if enabled
                    if (get_option('rns_brand_router_auto_update_email', false)) {
                        $this->send_update_email(false, $plugin_result->item->new_version ?? 'unknown', $plugin_result->messages);
                    }
                }
                break;
            }
        }
    }
    
    /**
     * Send email notification about auto-update
     */
    private function send_update_email($success, $version, $messages = array()) {
        $admin_email = get_option('admin_email');
        $site_name = get_bloginfo('name');
        
        if ($success) {
            $subject = sprintf('[%s] RNS Brand Router Auto-Update Successful', $site_name);
            $message = sprintf(
                "The RNS Brand Router plugin has been automatically updated to version %s on your site %s.\n\n" .
                "Site: %s\n" .
                "Plugin: RNS Brand Router\n" .
                "New Version: %s\n" .
                "Updated: %s",
                $version,
                $site_name,
                home_url(),
                $version,
                current_time('mysql')
            );
        } else {
            $subject = sprintf('[%s] RNS Brand Router Auto-Update Failed', $site_name);
            $message = sprintf(
                "The RNS Brand Router plugin auto-update failed on your site %s.\n\n" .
                "Site: %s\n" .
                "Plugin: RNS Brand Router\n" .
                "Attempted Version: %s\n" .
                "Failed: %s\n\n" .
                "Error Details:\n%s",
                $site_name,
                home_url(),
                $version,
                current_time('mysql'),
                is_array($messages) ? implode("\n", $messages) : print_r($messages, true)
            );
        }
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Add settings page to admin menu
     */
    public function add_settings_page() {
        add_options_page(
            'RNS Brand Router Auto-Updates',
            'RNS Brand Router',
            'manage_options',
            'rns-brand-router-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Register plugin settings (simplified)
     */
    public function register_settings() {
        // We're handling the form manually, so just register the options
        // This ensures they're properly recognized by WordPress
        register_setting('rns_brand_router_settings', 'rns_brand_router_auto_update', array(
            'type' => 'boolean',
            'default' => false
        ));
        register_setting('rns_brand_router_settings', 'rns_brand_router_auto_update_email', array(
            'type' => 'boolean', 
            'default' => false
        ));
    }
    
    /**
     * Settings page display
     */
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle form submission
        if (isset($_POST['submit'])) {
            // Verify nonce manually since we're using a custom form
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'rns_brand_router_settings-options')) {
                wp_die('Security check failed. Please try again.');
            }
            
            // Save settings
            update_option('rns_brand_router_auto_update', isset($_POST['rns_brand_router_auto_update']) ? 1 : 0);
            update_option('rns_brand_router_auto_update_email', isset($_POST['rns_brand_router_auto_update_email']) ? 1 : 0);
            
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
        }
        
        // Check if current version exists in GitHub releases
        $version_exists = $this->is_version_in_releases();
        
        ?>
        <div class="wrap">
            <h1>RNS Brand Router Settings</h1>
            
            <?php if (!$version_exists): ?>
            <div class="notice notice-warning">
                <p><strong>⚠️ Version Warning:</strong> The currently installed version (<?php echo esc_html($this->version); ?>) does not appear in the official GitHub release list for this plugin. This may indicate you are using an unofficial, development, or modified version.</p>
                <p><strong>Important:</strong> Support is not provided for unofficial versions, and some functionality may not work as expected. We recommend updating to an official release from the <a href="https://github.com/<?php echo esc_html($this->github_repo); ?>/releases" target="_blank">GitHub releases page</a>.</p>
            </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <?php wp_nonce_field('rns_brand_router_settings-options'); ?>
                
                <h2>Automatic Update Settings</h2>
                <p>Configure automatic update settings for the RNS Brand Router plugin. Updates are pulled from the GitHub repository.</p>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Automatic Updates</th>
                        <td>
                            <label>
                                <input type="checkbox" name="rns_brand_router_auto_update" value="1" <?php checked(1, get_option('rns_brand_router_auto_update', false)); ?> />
                                Enable automatic updates for RNS Brand Router
                            </label>
                            <p class="description">When enabled, the plugin will automatically update when new versions are available from GitHub.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Email Notifications</th>
                        <td>
                            <label>
                                <input type="checkbox" name="rns_brand_router_auto_update_email" value="1" <?php checked(1, get_option('rns_brand_router_auto_update_email', false)); ?> />
                                Send email notifications about automatic updates
                            </label>
                            <p class="description">Receive email notifications when automatic updates succeed or fail.</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <div class="card">
                <h2>Update Information</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Current Version</th>
                        <td><?php echo esc_html($this->version); ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Update Source</th>
                        <td>GitHub Repository: <?php echo esc_html($this->github_repo); ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Last Update Check</th>
                        <td>
                            <?php
                            $cached_info = $this->get_cached_version_info();
                            if ($cached_info) {
                                $timeout = get_option('_transient_timeout_' . $this->transient_key);
                                if ($timeout) {
                                    echo 'Cached until: ' . date('Y-m-d H:i:s', $timeout);
                                } else {
                                    echo 'Cached (no expiration time available)';
                                }
                            } else {
                                echo 'No recent check found';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
                
                <p>
                    <button type="button" class="button" onclick="rnsCheckForUpdate()">Check for Updates Now</button>
                    <span id="rns-update-status"></span>
                </p>
            </div>
        </div>
        
        <script>
        function rnsCheckForUpdate() {
            var button = event.target;
            var status = document.getElementById('rns-update-status');
            
            button.disabled = true;
            button.textContent = 'Checking...';
            status.textContent = '';
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'rns_check_update',
                    nonce: '<?php echo wp_create_nonce('rns_check_update_nonce'); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                button.disabled = false;
                button.textContent = 'Check for Updates Now';
                
                if (data.success) {
                    status.innerHTML = '<span style="color: green;">✓ ' + data.data.message + '</span>';
                } else {
                    status.innerHTML = '<span style="color: red;">✗ ' + data.data.message + '</span>';
                }
            })
            .catch(error => {
                button.disabled = false;
                button.textContent = 'Check for Updates Now';
                status.innerHTML = '<span style="color: red;">✗ Error checking for updates</span>';
            });
        }
        </script>
        <?php
    }
}

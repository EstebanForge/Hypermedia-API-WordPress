<?php

/**
 * Handles rendering the HTMX template.
 *
 * @since   2023-11-22
 */

namespace HMApi;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render Class.
 * Handles template loading, validation, and rendering for the HTMX API endpoints.
 *
 * @since 2023-11-22
 */
class Render
{
    /**
     * Currently processed template name.
     *
     * @var string|null
     */
    protected $template_name;

    /**
     * Current request nonce for validation.
     *
     * @var string|null
     */
    protected $nonce;

    /**
     * Request parameters passed to templates.
     *
     * @var array|false
     */
    protected $hmvals = false;

    /**
     * Render the template.
     *
     * @since 2023-11-22
     * @return void
     */
    public function load_template()
    {
        global $wp_query;

        // Determine which endpoint is being accessed (primary or legacy)
        $actual_endpoint_key = null;
        if (defined('HMAPI_ENDPOINT') && isset($wp_query->query_vars[HMAPI_ENDPOINT])) {
            $actual_endpoint_key = HMAPI_ENDPOINT;
        } elseif (defined('HMAPI_LEGACY_ENDPOINT') && isset($wp_query->query_vars[HMAPI_LEGACY_ENDPOINT])) {
            $actual_endpoint_key = HMAPI_LEGACY_ENDPOINT;
        }

        // Don't go further if this is not a request for one of our endpoints
        if (null === $actual_endpoint_key) {
            // Check if this might be a base endpoint access (without version)
            $this->handle_base_endpoint_access();

            return;
        }

        // Check if nonce exists and is valid, only on POST requests
        if (!$this->valid_nonce() && $_SERVER['REQUEST_METHOD'] === 'POST') {
            wp_die(esc_html__('Invalid nonce', 'api-for-htmx'), esc_html__('Error', 'api-for-htmx'), ['response' => 403]);
        }

        // Sanitize template name using the determined endpoint key
        $template_name = $this->sanitize_path($wp_query->query_vars[$actual_endpoint_key]);

        // Get hmvals from $_REQUEST and sanitize them
        $hmvals = $_REQUEST; // Nonce is validated in valid_nonce()
        if (!isset($hmvals) || empty($hmvals)) {
            $hmvals = false;
        } else {
            $hmvals = $this->sanitize_params($hmvals);
        }

        // For backward compatibility
        $hxvals = $hmvals;

        // Load the requested template or fail with a 404
        $this->render_or_fail($template_name, $hmvals);
        die(); // No wp_die() here, we don't want to show the complete WP error page
    }

    /**
     * Render or fail
     * Load the requested template or fail with a 404.
     *
     * @since 2023-11-30
     * @param string $template_name
     * @param array|bool $hmvals
     *
     * @return void
     */
    protected function render_or_fail($template_name = '', $hmvals = false)
    {
        if (empty($template_name)) {
            $this->show_developer_info_page('missing-template-name');

            return;
        }

        // Get our template file and vars
        $template_path = $this->get_template_file($template_name);

        if (!$template_path) {
            $this->show_developer_info_page('invalid-route', $template_name);

            return;
        }

        // Check if the template exists
        if (!file_exists($template_path)) {
            $this->show_developer_info_page('template-not-found', $template_name, $template_path);

            return;
        }

        // To help developers know when template files were loaded via our plugin
        define('HMAPI_REQUEST', true);

        // For backward compatibility
        $hxvals = $hmvals;
        
        // Run actions before loading the template
        do_action('hmapi/before_template_load', $template_name, $hmvals);

        // Load the template
        require_once $template_path;
    }

    /**
     * Show developer-friendly information page for API endpoints.
     *
     * @since 2.0.0
     * @param string $error_type Type of error: 'missing-template-name', 'invalid-route', 'template-not-found', 'endpoint-info'
     * @param string $template_name Optional template name that was requested
     * @param string $template_path Optional template path that was searched
     * @return void
     */
    protected function show_developer_info_page($error_type = 'endpoint-info', $template_name = '', $template_path = '')
    {
        status_header(200); // Use 200 to show helpful info instead of 404

        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: text/html; charset=utf-8');
        }

        // Get current endpoint info
        global $wp_query;
        $current_endpoint = '';
        $endpoint_version = '';

        if (defined('HMAPI_ENDPOINT') && isset($wp_query->query_vars[HMAPI_ENDPOINT])) {
            $current_endpoint = HMAPI_ENDPOINT;
            $endpoint_version = defined('HMAPI_ENDPOINT_VERSION') ? HMAPI_ENDPOINT_VERSION : 'v1';
        } elseif (defined('HMAPI_LEGACY_ENDPOINT') && isset($wp_query->query_vars[HMAPI_LEGACY_ENDPOINT])) {
            $current_endpoint = HMAPI_LEGACY_ENDPOINT;
            $endpoint_version = defined('HMAPI_ENDPOINT_VERSION') ? HMAPI_ENDPOINT_VERSION : 'v1';
        }

        $base_url = home_url($current_endpoint . '/' . $endpoint_version);
        $plugin_name = defined('HMAPI_PLUGIN_NAME') ? HMAPI_PLUGIN_NAME : 'Hypermedia API for WordPress';

        // Only show debug info if WP_DEBUG is enabled or user can manage options
        $show_debug = defined('WP_DEBUG') && WP_DEBUG || current_user_can('manage_options');

        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($plugin_name); ?> - Developer Information</title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    line-height: 1.6;
                    color: #333;
                    max-width: 800px;
                    margin: 40px auto;
                    padding: 20px;
                    background: #f5f5f5;
                }
                .container {
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                }
                h1 {
                    color: #0073aa;
                    border-bottom: 3px solid #0073aa;
                    padding-bottom: 10px;
                }
                h2 {
                    color: #555;
                    margin-top: 30px;
                }
                .error-box {
                    background: #fff3cd;
                    border: 1px solid #ffeaa7;
                    border-left: 4px solid #f39c12;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 4px;
                }
                .info-box {
                    background: #d1ecf1;
                    border: 1px solid #bee5eb;
                    border-left: 4px solid #17a2b8;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 4px;
                }
                .success-box {
                    background: #d4edda;
                    border: 1px solid #c3e6cb;
                    border-left: 4px solid #28a745;
                    padding: 15px;
                    margin: 20px 0;
                    border-radius: 4px;
                }
                code {
                    background: #f8f9fa;
                    padding: 2px 6px;
                    border-radius: 3px;
                    font-family: "SF Mono", Monaco, "Cascadia Code", "Roboto Mono", Consolas, "Courier New", monospace;
                }
                pre {
                    background: #f8f9fa;
                    padding: 15px;
                    border-radius: 5px;
                    overflow-x: auto;
                    border: 1px solid #e9ecef;
                }
                .endpoint-url {
                    font-weight: bold;
                    color: #0073aa;
                }
                .debug-info {
                    margin-top: 30px;
                    font-size: 0.9em;
                    color: #666;
                }
                ul {
                    padding-left: 20px;
                }
                li {
                    margin: 8px 0;
                }
                .footer {
                    margin-top: 40px;
                    padding-top: 20px;
                    border-top: 1px solid #eee;
                    color: #777;
                    font-size: 0.9em;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h1><?php echo esc_html($plugin_name); ?></h1>

                <?php if ($error_type === 'missing-template-name'): ?>
                    <div class="error-box">
                        <strong>Missing Template Name</strong><br>
                        You've accessed the API endpoint without specifying a template name.
                    </div>

                <?php elseif ($error_type === 'invalid-route'): ?>
                    <div class="error-box">
                        <strong>Invalid Route</strong><br>
                        Template '<code><?php echo esc_html($template_name); ?></code>' could not be resolved to a valid file path.
                    </div>

                <?php elseif ($error_type === 'template-not-found'): ?>
                    <div class="error-box">
                        <strong>Template Not Found</strong><br>
                        Template '<code><?php echo esc_html($template_name); ?></code>' was not found.
                        <?php if ($show_debug && $template_path): ?>
                            <br><small>Searched at: <code><?php echo esc_html($template_path); ?></code></small>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <div class="info-box">
                        <strong>API Endpoint Information</strong><br>
                        This is a hypermedia API endpoint for dynamic content delivery.
                    </div>
                <?php endif; ?>

                <h2>Usage Examples</h2>
                <div class="success-box">
                    <p><strong>Correct endpoint usage:</strong></p>
                    <ul>
                        <li><code class="endpoint-url"><?php echo esc_url(hm_get_endpoint_url('my-template')); ?></code> - Loads template file <code>my-template.hm.php</code></li>
                        <li><code class="endpoint-url"><?php echo esc_url(hm_get_endpoint_url('folder/template')); ?></code> - Loads <code>folder/template.hm.php</code></li>
                        <li><code class="endpoint-url"><?php echo esc_url(hm_get_endpoint_url('noswap/header-update')); ?></code> - Loads <code>noswap/header-update.hm.php</code></li>
                    </ul>
                </div>

                <h2>Template File Locations</h2>
                <div class="info-box">
                    <p>Template files (<code>.hm.php</code>) should be placed in:</p>
                    <ul>
                        <li><strong>Theme:</strong> <code><?php echo esc_html(get_template_directory()); ?>/hypermedia/</code></li>
                        <li><strong>Child Theme:</strong> <code><?php echo esc_html(get_stylesheet_directory()); ?>/hypermedia/</code></li>
                        <li><strong>Plugin:</strong> <code><?php echo esc_html(dirname(HMAPI_INSTANCE_LOADED_PATH)); ?>/hypermedia/</code></li>
                    </ul>
                </div>

                <h2>Available Helper Functions</h2>
                <div class="info-box">
                    <ul>
                        <li><code>hm_validate_request()</code> - Validate nonce and request</li>
                        <li><code>hm_send_header_response($data, $action)</code> - Send header-only response</li>
                        <li><code>hm_die($message)</code> - Die gracefully with error message</li>
                        <li><code>hm_get_endpoint_url($template)</code> - Get URL for template</li>
                        <li><code>hm_endpoint_url($template)</code> - Echoes endpoint URL for template</li>
                    </ul>
                </div>

                <?php if ($show_debug): ?>
                    <div class="debug-info">
                        <h2>Debug Information</h2>
                        <div class="info-box">
                            <strong>Current Request:</strong><br>
                            <code>REQUEST_METHOD:</code> <?php echo esc_html($_SERVER['REQUEST_METHOD'] ?? 'Unknown'); ?><br>
                            <code>REQUEST_URI:</code> <?php echo esc_html($_SERVER['REQUEST_URI'] ?? 'Unknown'); ?><br>
                            <code>Endpoint:</code> <?php echo esc_html($current_endpoint); ?><br>
                            <code>Version:</code> <?php echo esc_html($endpoint_version); ?><br>
                            <?php if ($template_name): ?>
                                <code>Requested Template:</code> <?php echo esc_html($template_name); ?><br>
                            <?php endif; ?>
                            <code>WordPress Version:</code> <?php echo esc_html(get_bloginfo('version')); ?><br>
                            <code>Plugin Version:</code> <?php echo esc_html(defined('HMAPI_LOADED_VERSION') ? HMAPI_LOADED_VERSION : 'Unknown'); ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="footer">
                    <p><?php echo esc_html($plugin_name); ?> | For more information, visit the <a href="https://github.com/EstebanForge/Hypermedia-API-WordPress" target="_blank" rel="noopener noreferrer">plugin documentation</a>.</p>
                </div>
            </div>
        </body>
        </html>
        <?php
        die();
    }

    /**
     * Handle access to base endpoints without version (e.g., /wp-html/ instead of /wp-html/v1/).
     *
     * @since 2.0.0
     * @return void
     */
    protected function handle_base_endpoint_access()
    {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        // Check if the request URI matches our base endpoints
        $base_endpoints = [];
        if (defined('HMAPI_ENDPOINT')) {
            $base_endpoints[] = '/' . HMAPI_ENDPOINT . '/';
            $base_endpoints[] = '/' . HMAPI_ENDPOINT;
        }
        if (defined('HMAPI_LEGACY_ENDPOINT')) {
            $base_endpoints[] = '/' . HMAPI_LEGACY_ENDPOINT . '/';
            $base_endpoints[] = '/' . HMAPI_LEGACY_ENDPOINT;
        }

        foreach ($base_endpoints as $endpoint) {
            if (strpos($request_uri, $endpoint) !== false) {
                // This is likely a base endpoint access, show helpful info
                $this->show_developer_info_page('endpoint-info');

                return;
            }
        }
    }

    /**
     * Check if nonce exists and is valid
     * nonce: hmapi_nonce.
     *
     * @since 2023-11-30
     *
     * @return bool
     */
    protected function valid_nonce()
    {
        // https://github.com/WP-API/api-core/blob/develop/wp-includes/rest-api.php#L555
        $nonce = null;

        if (isset($_REQUEST['_wpnonce'])) {
            $nonce = sanitize_key($_REQUEST['_wpnonce']);
        } elseif (isset($_SERVER['HTTP_X_WP_NONCE'])) {
            $nonce = sanitize_key($_SERVER['HTTP_X_WP_NONCE']);
        }

        if (null === $nonce) {
            // No nonce at all, so act as if it's an unauthenticated request.
            wp_set_current_user(0);

            return false;
        }

        // Check for the new nonce first, then fall back to the legacy nonce.
        $is_valid_new = wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), 'hmapi_nonce');
        $is_valid_legacy = wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), 'hxwp_nonce');

        if (!$is_valid_new && !$is_valid_legacy) {
            return false;
        }

        return true;
    }

    /**
     * Sanitize path.
     * This method sanitizes the template path string received from the URL.
     * If the path uses a colon for namespacing (e.g., "namespace:path/to/template"),
     * the namespace and the subsequent path segments are sanitized separately.
     * Otherwise, the entire string is treated as a theme-relative path and sanitized.
     *
     * @since 2023-11-30
     * @param string $path_string The raw path string from the query variable.
     *
     * @return string|false The sanitized path string, or false if sanitization fails or input is empty.
     */
    private function sanitize_path($path_string = '')
    {
        if (empty($path_string)) {
            return false;
        }

        $path_string = (string) $path_string;

        // Attempt to parse using the colon separator.
        $parsed_data = $this->parse_namespaced_template($path_string);

        if ($parsed_data !== false) {
            // Namespaced path: namespace:template_segment
            $namespace = sanitize_key($parsed_data['namespace']);
            $template_segment = $parsed_data['template'];

            // Sanitize the template_segment (which can be 'file' or 'subdir/file')
            $template_segment_parts = explode('/', $template_segment);
            $sanitized_template_segment_parts = [];

            foreach ($template_segment_parts as $index => $part) {
                if (empty($part) && count($template_segment_parts) > 1) { // Allow empty part if it's not the only part (e.g. trailing slash)
                    // However, explode usually doesn't create empty parts in the middle unless there are //
                    // For robustness, skip empty parts that are not significant.
                    continue;
                }
                $part_cleaned = str_replace('..', '', $part); // Basic traversal prevention
                $part_cleaned = remove_accents($part_cleaned);

                if ($index === count($template_segment_parts) - 1) {
                    // Last part is the filename
                    $sanitized_template_segment_parts[] = $this->sanitize_file_name($part_cleaned);
                } else {
                    // Directory part
                    $sanitized_template_segment_parts[] = sanitize_key($part_cleaned);
                }
            }
            // Filter out any truly empty parts that might result from sanitization or original string (e.g. "foo//bar")
            $filtered_parts = array_filter($sanitized_template_segment_parts, function ($value) { return $value !== ''; });
            $sanitized_template_segment = implode('/', $filtered_parts);

            if (empty($namespace) || empty($sanitized_template_segment)) {
                return false; // Invalid if either part becomes empty after sanitization
            }

            return $namespace . ':' . $sanitized_template_segment;

        } else {
            // Not a namespaced path (no colon, or invalid format). Treat as theme-relative.
            $template_segment_parts = explode('/', $path_string);
            $sanitized_template_segment_parts = [];

            foreach ($template_segment_parts as $index => $part) {
                if (empty($part) && count($template_segment_parts) > 1) {
                    continue;
                }
                $part_cleaned = str_replace('..', '', $part); // Basic traversal prevention
                $part_cleaned = remove_accents($part_cleaned);

                if ($index === count($template_segment_parts) - 1) {
                    // Last part is the filename
                    $sanitized_template_segment_parts[] = $this->sanitize_file_name($part_cleaned);
                } else {
                    // Directory part
                    $sanitized_template_segment_parts[] = sanitize_key($part_cleaned);
                }
            }
            $filtered_parts = array_filter($sanitized_template_segment_parts, function ($value) { return $value !== ''; });
            $sanitized_path = implode('/', $filtered_parts);

            return empty($sanitized_path) ? false : $sanitized_path;
        }
    }

    /**
     * Sanitize file name for template usage.
     * Removes accents and applies WordPress file name sanitization.
     *
     * @since 2023-11-30
     *
     * @param string $file_name Raw file name to sanitize.
     *
     * @return string|false Sanitized file name, or false if input is empty.
     */
    private function sanitize_file_name($file_name = '')
    {
        if (empty($file_name)) {
            return false;
        }

        // Remove accents and sanitize it
        $file_name = sanitize_file_name(remove_accents($file_name));

        return $file_name;
    }

    /**
     * Sanitize request parameters (hmvals).
     * Applies WordPress sanitization functions to all request parameters and removes nonces.
     * Supports both single values and arrays (for multi-value form elements).
     *
     * @since 2023-11-30
     *
     * @param array $hmvals Raw request parameters to sanitize.
     *
     * @return array|false Sanitized parameters array, or false if input is empty.
     */
    private function sanitize_params($hmvals = [])
    {
        if (empty($hmvals)) {
            return false;
        }

        // Sanitize each param
        foreach ($hmvals as $key => $value) {
            // Sanitize key
            $key = apply_filters('hmapi/sanitize_param_key', sanitize_key($key), $key);

            // For form elements with multiple values
            // https://github.com/EstebanForge/HTMX-API-WP/discussions/8
            if (is_array($value)) {
                // Sanitize each value
                $value = apply_filters('hmapi/sanitize_param_array_value', array_map('sanitize_text_field', $value), $key);
            } else {
                // Sanitize single value
                $value = apply_filters('hmapi/sanitize_param_value', sanitize_text_field($value), $key);
            }

            // Update param
            $hmvals[$key] = $value;
        }

        // Remove nonce if exists
        if (isset($hmvals['_wpnonce'])) { // Standard WordPress nonce key in $_REQUEST
            unset($hmvals['_wpnonce']);
        }
        // Also unset our specific nonce if it was passed as a regular param, though primary check is _wpnonce
        if (isset($hmvals['hmapi_nonce'])) {
            unset($hmvals['hmapi_nonce']);
        }

        return $hmvals;
    }

    /**
     * Get active theme or child theme path
     * If a child theme is active, use it instead of the parent theme.
     *
     * @since 2023-11-30
     *
     * @return string
     */
    protected function get_theme_path()
    {
        $theme_path = trailingslashit(get_template_directory());

        if (is_child_theme()) {
            $theme_path = trailingslashit(get_stylesheet_directory());
        }

        return $theme_path;
    }

    /**
     * Find a template file with support for multiple extensions.
     *
     * It checks for template files in a given directory using a primary and a legacy extension.
     * The primary extension is checked first.
     *
     * @since 2.0.0
     * @param string $base_dir      The directory to search in.
     * @param string $template_name The name of the template file (without extension).
     * @return string|false The full path to the found template file, or false if not found.
     */
    private function find_template_with_extensions(string $base_dir, string $template_name): string|false
    {
        // Define the extensions to check, with primary first.
        $extensions = [
            HMAPI_TEMPLATE_EXT,        // Primary: .hm.php
            HMAPI_LEGACY_TEMPLATE_EXT, // Legacy: .htmx.php
        ];

        foreach ($extensions as $extension) {
            $potential_path = $base_dir . $template_name . $extension;
            $resolved_path = $this->sanitize_full_path($potential_path);

            if ($resolved_path) {
                // Ensure the resolved path is within the allowed base directory.
                $real_base_dir = realpath($base_dir);
                if ($real_base_dir && (str_starts_with($resolved_path, $real_base_dir . DIRECTORY_SEPARATOR) || $resolved_path === $real_base_dir)) {
                    return $resolved_path;
                }
            }
        }

        return false;
    }

    /**
     * Determine our template file.
     * It first checks for templates in paths registered via 'hmapi/register_template_path'.
     * If a namespaced template is requested (e.g., "namespace:template-name") and found, it's used.
     * If an explicit namespace is used but not found, it will fail (no fallback).
     * Otherwise (no namespace in request), it falls back to the default theme's template directory.
     *
     * @since 2023-11-30
     * @param string $template_name The sanitized template name, possibly including a namespace (e.g., "namespace:template-file").
     *
     * @return string|false The full, sanitized path to the template file, or false if not found.
     */
    protected function get_template_file($template_name = '')
    {
        if (empty($template_name)) {
            return false;
        }

        $namespaced_paths = apply_filters('hmapi/register_template_path', []);
        $parsed_template_data = $this->parse_namespaced_template($template_name);

        if ($parsed_template_data !== false) {
            $namespace = $parsed_template_data['namespace'];
            $template_part = $parsed_template_data['template'];

            if (isset($namespaced_paths[$namespace])) {
                $base_dir_registered = trailingslashit((string) $namespaced_paths[$namespace]);
                $found_path = $this->find_template_with_extensions($base_dir_registered, $template_part);

                if ($found_path) {
                    return $found_path;
                }
            }

            return false;
        } else {
            // No colon found (or invalid colon format). Treat as a theme-relative path.
            $default_paths = [
                $this->get_theme_path() . HMAPI_TEMPLATE_DIR . '/',
                $this->get_theme_path() . HMAPI_LEGACY_TEMPLATE_DIR . '/',
            ];

            // Apply modern and legacy filters for backward compatibility.
            $modern_paths = apply_filters('hmapi/get_template_file/templates_path', $default_paths);
            $default_templates_paths_array = apply_filters('hxwp/get_template_file/templates_path', $modern_paths);

            foreach ((array) $default_templates_paths_array as $default_path_item_base) {
                if (empty($default_path_item_base)) {
                    continue;
                }

                $base_dir_theme = trailingslashit((string) $default_path_item_base);
                $found_path = $this->find_template_with_extensions($base_dir_theme, $template_name);

                if ($found_path) {
                    return $found_path;
                }
            }
        }

        return false;
    }

    /**
     * Parses a template name that might contain a namespace, using ':' as the separator.
     * e.g., "myplugin:template-name" -> ['namespace' => 'myplugin', 'template' => 'template-name'].
     *
     * @since 1.2.1 Changed separator from '/' to ':'.
     * @param string $template_name The template name to parse.
     * @return array{'namespace': string, 'template': string}|false Array with 'namespace' and 'template' keys if ':' is found and parts are valid, or false otherwise.
     */
    protected function parse_namespaced_template($template_name)
    {
        if (str_contains((string) $template_name, ':')) {
            $parts = explode(':', (string) $template_name, 2);
            if (count($parts) === 2 && !empty($parts[0]) && !empty($parts[1])) {
                return [
                    'namespace' => $parts[0],
                    'template'  => $parts[1],
                ];
            }
        }

        return false; // No valid colon separator found, or parts were empty.
    }

    /**
     * Sanitize full file path and resolve it to prevent directory traversal.
     * Uses realpath() to resolve symbolic links and validate the path exists.
     *
     * @since 2023-12-13
     *
     * @param string $full_path Full file path to sanitize and validate.
     *
     * @return string|false Resolved and sanitized file path, or false if invalid/nonexistent.
     */
    protected function sanitize_full_path($full_path = '')
    {
        if (empty($full_path)) {
            return false;
        }

        // Ensure full path is always a string
        $full_path = (string) $full_path;

        // Realpath
        $full_path = realpath($full_path);

        return $full_path;
    }
}

<?php
/**
 * Plugin Name: Sumai2
 * Plugin URI: https://sumai2.ai
 * Description: AI-powered content summarization from RSS feeds
 * Author: Sumai2 AI Team
 * Version: 1.0.5
 * Author URI: https://sumai2.ai
 * Text Domain: sumai2
 * Domain Path: /languages
 *
 * @package Sumai2
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

// Define plugin file constant
define( 'SUMAI2_PLUGIN_FILE', __FILE__ );
define( 'SUMAI2_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Include core constants - these are required before anything else
require_once plugin_dir_path( __FILE__ ) . 'includes/core/constants.php';

/**
 * Include files in a specific order based on dependencies.
 * This function implements phased loading to ensure dependencies are met.
 */
function sumai2_include_files() {
    // Phase 1: Core foundation files (no dependencies)
    $phase1_files = [
        'includes/logging/logger.php',       // Logging should be loaded first for error tracking
        'includes/logging/error-handler.php', // Error handling system
        'includes/core/security.php',        // Security functions needed early
        'includes/core/activation.php',      // Activation functions including dependency checks
        'includes/core/function-checker.php', // Function existence checker to prevent duplicates
        'includes/core/fallbacks.php',       // Fallback mechanisms for missing dependencies
        'includes/core/documentation-manager.php', // Documentation management system
    ];
    
    foreach ($phase1_files as $file) {
        require_once SUMAI2_PLUGIN_DIR . $file;
    }
    
    // Log the start of file loading
    if (function_exists('sumai2_handle_error')) {
        sumai2_log_event('Starting plugin file loading sequence');
    } else if (function_exists('sumai2_logging_fallback')) {
        // Use fallback logging if main logging function is not available
        sumai2_logging_fallback('Starting plugin file loading sequence');
    }
    
    // Phase 2: Check dependencies before loading feature-specific files
    $dependencies_met = true;
    
    // Check for required PHP extensions
    $required_extensions = ['json', 'curl', 'openssl'];
    $missing_extensions = [];
    
    foreach ($required_extensions as $ext) {
        if (!extension_loaded($ext)) {
            $missing_extensions[] = $ext;
            $dependencies_met = false;
        }
    }
    
    if (!empty($missing_extensions)) {
        sumai2_handle_error(
            'Missing required PHP extensions: ' . implode(', ', $missing_extensions),
            SUMAI2_ERROR_SYSTEM, // Assuming constant rename
            ['required' => $required_extensions, 'missing' => $missing_extensions],
            true // Notify admin
        );
    }
    
    // Phase 3: Load API and utility files
    $phase3_files = [
        'includes/api/retry.php',           // API retry mechanism
        'includes/api/openai.php',          // OpenAI API integration
        'includes/core/cache-manager.php',  // Caching system
        'includes/core/feed-processing.php',// Feed fetching functionality
        'includes/core/content-processor.php', // Content processing
        'includes/core/status-tracker.php', // Status tracking system
        'includes/core/retry-manager.php',  // Retry management
        'includes/core/action-scheduler.php' // Action scheduler
    ];
    
    foreach ($phase3_files as $file) {
        if (file_exists(SUMAI2_PLUGIN_DIR . $file)) {
            require_once SUMAI2_PLUGIN_DIR . $file;
        } else {
            sumai2_handle_error(
                "Missing required file: {$file}",
                SUMAI2_ERROR_SYSTEM, // Assuming constant rename
                ['file' => $file]
            );
            $dependencies_met = false;
        }
    }
    
    // Phase 4: Load admin interface files
    if (is_admin()) {
        $admin_files = [
            'includes/admin/settings.php',     // Settings page
            'includes/admin/prompt-manager.php', // Custom AI prompts management
            'includes/admin/error-reporting.php', // Error reporting interface
            'includes/admin/ajax.php',         // AJAX handlers
            'includes/admin/error-test.php',   // Error testing tools
        ];
        
        foreach ($admin_files as $file) {
            if (file_exists(SUMAI2_PLUGIN_DIR . $file)) {
                require_once SUMAI2_PLUGIN_DIR . $file;
            }
        }
    }
    
    // Return loading status
    return [
        'dependencies_met' => $dependencies_met,
        'missing_extensions' => $missing_extensions
    ];
}

/**
 * Initialize the plugin.
 * This function handles the initialization process with dependency awareness.
 */
function sumai2_init() {
    // Include necessary files
    $loading_status = sumai2_include_files();
    
    // Register hooks only if dependencies are met
    if ($loading_status['dependencies_met']) {
        sumai2_register_hooks($loading_status);
    } else {
        // Register only admin notice hooks if dependencies are not met
        add_action('admin_notices', 'sumai2_dependency_notice');
    }
    
    // Schedule log pruning if not already scheduled
    if (!wp_next_scheduled('sumai2_prune_logs')) {
        wp_schedule_event(time(), 'daily', 'sumai2_prune_logs');
    }
}

/**
 * Register all hooks for the plugin with dependency awareness.
 * 
 * @param array $loading_status Status of file loading including dependency checks
 */
function sumai2_register_hooks($loading_status = []) {
    // Add settings link to plugin page
    add_filter('plugin_action_links_' . plugin_basename(SUMAI2_PLUGIN_FILE), 'sumai2_add_settings_link');
    
    // Register activation and deactivation hooks
    register_activation_hook(SUMAI2_PLUGIN_FILE, 'sumai2_activate');
    register_deactivation_hook(SUMAI2_PLUGIN_FILE, 'sumai2_deactivate');
    
    // Schedule cron jobs if dependencies are met
    if ($loading_status['dependencies_met']) {
        // Register the cron job for feed processing
        add_action('sumai2_process_feeds', 'sumai2_process_feeds_job');
        
        // Register the cron job for log pruning
        add_action('sumai2_prune_logs', 'sumai2_prune_logs');
        
        // Ensure cron jobs are scheduled
        if (!wp_next_scheduled('sumai2_process_feeds')) {
            wp_schedule_event(time(), 'hourly', 'sumai2_process_feeds');
        }
    }
    
    // Register AJAX handlers for admin interface
    if (is_admin()) {
        add_action('wp_ajax_sumai2_test_api', 'sumai2_ajax_test_api');
        add_action('wp_ajax_sumai2_test_feed', 'sumai2_ajax_test_feed');
        add_action('wp_ajax_sumai2_manual_generate', 'sumai2_ajax_manual_generate');
        add_action('wp_ajax_sumai2_test_feeds', 'sumai2_ajax_test_feeds');
        add_action('wp_ajax_sumai2_generate_now', 'sumai2_ajax_generate_now');
        add_action('wp_ajax_sumai2_check_status', 'sumai2_ajax_check_status');
        add_action('wp_ajax_sumai2_get_processed_articles', 'sumai2_ajax_get_processed_articles');
        add_action('wp_ajax_sumai2_clear_all_articles', 'sumai2_ajax_clear_all_articles');
        add_action('wp_ajax_sumai2_clear_article', 'sumai2_ajax_clear_article');
    }
    
    // Register shortcodes
    add_shortcode('sumai2_summary', 'sumai2_summary_shortcode');
    
    // Register error handling for uncaught PHP errors
    set_error_handler('sumai2_php_error_handler');
}

/**
 * PHP error handler to catch and log uncaught errors.
 *
 * @param int $errno Error number.
 * @param string $errstr Error message.
 * @param string $errfile File where the error occurred.
 * @param int $errline Line where the error occurred.
 * @return bool Whether the error was handled.
 */
function sumai2_php_error_handler($errno, $errstr, $errfile, $errline) {
    // Only handle errors that are part of the current error reporting level
    if (!(error_reporting() & $errno)) {
        return false;
    }
    
    // Skip if error handler function doesn't exist yet
    if (!function_exists('sumai2_handle_error')) {
        // Fallback to basic logging if the full error handler isn't available yet
        if (function_exists('sumai2_log_event')) {
            sumai2_log_event("PHP Error: {$errstr} in {$errfile} on line {$errline}", true);
        }
        return false;
    }
    
    // Map PHP error constants to readable names
    $error_types = [
        E_ERROR => 'Fatal Error',
        E_WARNING => 'Warning',
        E_PARSE => 'Parse Error',
        E_NOTICE => 'Notice',
        E_CORE_ERROR => 'Core Error',
        E_CORE_WARNING => 'Core Warning',
        E_COMPILE_ERROR => 'Compile Error',
        E_COMPILE_WARNING => 'Compile Warning',
        E_USER_ERROR => 'User Error',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_STRICT => 'Strict Standards',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED => 'Deprecated',
        E_USER_DEPRECATED => 'User Deprecated',
    ];
    
    $error_type = $error_types[$errno] ?? 'Unknown Error';
    
    // Only log errors and warnings, not notices
    if ($errno == E_ERROR || $errno == E_USER_ERROR || $errno == E_RECOVERABLE_ERROR) {
        sumai2_handle_error(
            "PHP {$error_type}: {$errstr}",
            SUMAI2_ERROR_SYSTEM, // Assuming constant rename
            [
                'file' => $errfile,
                'line' => $errline,
                'errno' => $errno
            ],
            true // Notify admin for fatal errors
        );
    } elseif ($errno == E_WARNING || $errno == E_USER_WARNING) {
        sumai2_handle_error(
            "PHP {$error_type}: {$errstr}",
            SUMAI2_ERROR_SYSTEM, // Assuming constant rename
            [
                'file' => $errfile,
                'line' => $errline,
                'errno' => $errno
            ]
        );
    }
    
    // Don't execute PHP's internal error handler
    return true;
}

/**
 * Adds settings link to plugin actions.
 * 
 * @param array $links Existing plugin action links.
 * @return array Modified plugin action links.
 */
function sumai2_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=sumai2-settings') . '">' . __('Settings', 'sumai2') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Initialize the plugin
add_action('init', 'sumai2_init');
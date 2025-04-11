<?php
/**
 * AJAX handlers for the Sumai plugin admin interface.
 *
 * @package Sumai
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Register AJAX handlers for the admin interface.
 */
function sumai_register_ajax_handlers() {
    // Test feed AJAX endpoint
    add_action('wp_ajax_sumai_test_feeds', 'sumai_ajax_test_feeds');
    
    // Manual generation AJAX endpoint
    add_action('wp_ajax_sumai_generate_now', 'sumai_ajax_generate_now');
    
    // Check status AJAX endpoint
    add_action('wp_ajax_sumai_check_status', 'sumai_ajax_check_status');
    
    // Processed articles management endpoints
    add_action('wp_ajax_sumai_get_processed_articles', 'sumai_ajax_get_processed_articles');
    add_action('wp_ajax_sumai_clear_all_articles', 'sumai_ajax_clear_all_articles');
    add_action('wp_ajax_sumai_clear_article', 'sumai_ajax_clear_article');
    
    // Register custom action for background processing
    add_action('sumai_process_content_generation', 'sumai_process_content_generation');
}

/**
 * AJAX handler for testing feed URLs.
 */
function sumai_ajax_test_feeds() {
    // Verify nonce
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sumai_nonce' ) ) {
        wp_send_json_error( array(
            'message' => 'Security check failed.',
            'progress' => 100
        ) );
    }

    // Check user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array(
            'message' => 'You do not have permission to perform this action.',
            'progress' => 100
        ) );
    }

    // Get settings
    $opts = sumai_get_cached_settings();
    $feed_urls = explode( "\n", $opts['feed_urls'] );
    $feed_urls = array_map( 'trim', $feed_urls );
    $feed_urls = array_filter( $feed_urls );

    if ( empty( $feed_urls ) ) {
        wp_send_json_error( array(
            'message' => 'No feed URLs configured. Please add feed URLs in the settings.',
            'progress' => 100
        ) );
    }

    // Test each feed
    $results = array();
    $total_feeds = count($feed_urls);
    $processed = 0;
    
    // Check if we're continuing an existing test
    $current_index = isset($_POST['current_index']) ? intval($_POST['current_index']) : 0;
    
    // Special case: clear previous results and initialize
    if ($current_index < 0) {
        delete_transient('sumai_feed_test_results');
        wp_send_json_success(array(
            'message' => 'Test initialized',
            'progress' => 0,
            'next_index' => 0
        ));
    }
    
    // If we've processed all feeds, send the final response
    if ($current_index >= $total_feeds) {
        // Get stored results
        $stored_results = get_transient('sumai_feed_test_results');
        if (!$stored_results) {
            $stored_results = array();
        }
        
        wp_send_json_success(array(
            'results' => $stored_results,
            'progress' => 100,
            'status' => 'complete',
            'message' => 'Feed testing complete.'
        ));
    }
    
    // Get the current feed URL to test
    $url = $feed_urls[$current_index];
    
    // Update progress
    $progress = floor(($current_index / $total_feeds) * 100);
    
    // Log the test
    if (function_exists('sumai_log_event')) {
        sumai_log_event('Testing feed: ' . esc_url($url));
    }
    
    // Test the feed
    $result = array();
    
    try {
        if (!function_exists('fetch_feed')) {
            require_once(ABSPATH . WPINC . '/feed.php');
        }
        
        $feed = fetch_feed($url);
        
        if (is_wp_error($feed)) {
            $result = array(
                'url' => $url,
                'status' => 'error',
                'message' => $feed->get_error_message()
            );
        } else {
            $item_count = $feed->get_item_quantity();
            $result = array(
                'url' => $url,
                'status' => 'success',
                'message' => sprintf('Found %d items', $item_count),
                'items' => $item_count
            );
        }
    } catch (Exception $e) {
        $result = array(
            'url' => $url,
            'status' => 'error',
            'message' => 'Exception: ' . $e->getMessage()
        );
    }
    
    // Store result
    $stored_results = get_transient('sumai_feed_test_results');
    if (!$stored_results) {
        $stored_results = array();
    }
    $stored_results[] = $result;
    set_transient('sumai_feed_test_results', $stored_results, HOUR_IN_SECONDS);
    
    // Send response with next index
    wp_send_json_success(array(
        'current_index' => $current_index,
        'next_index' => $current_index + 1,
        'progress' => $progress,
        'status' => 'processing',
        'message' => sprintf('Testing feed %d of %d: %s', $current_index + 1, $total_feeds, esc_url($url))
    ));
}

/**
 * AJAX handler for manual content generation.
 */
function sumai_ajax_generate_now() {
    // Verify nonce
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sumai_nonce' ) ) {
        wp_send_json_error( array(
            'message' => 'Security check failed.',
            'progress' => 100
        ) );
    }

    // Check user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array(
            'message' => 'You do not have permission to perform this action.',
            'progress' => 100
        ) );
    }

    // Get parameters
    $draft_mode = isset( $_POST['draft_mode'] ) ? (bool) $_POST['draft_mode'] : false;
    $respect_processed = isset( $_POST['respect_processed'] ) ? (bool) $_POST['respect_processed'] : true;

    // Create a unique status ID for tracking this operation
    $status_id = sumai_generate_status_id();
    
    // Initialize progress tracking
    sumai_update_status($status_id, 'Starting content generation...', 'processing', [
        'total_steps' => 5,
        'current_step' => 0,
        'progress' => 0
    ]);
    
    // Store parameters in a transient for the background process
    set_transient('sumai_generate_params_' . $status_id, [
        'draft_mode' => $draft_mode,
        'respect_processed' => $respect_processed
    ], 3600); // 1 hour expiration
    
    // Schedule the background process using WordPress core
    wp_schedule_single_event(time(), 'sumai_process_content_generation', [$status_id]);
    
    // Return the status ID to the client for status polling
    wp_send_json_success([
        'status_id' => $status_id,
        'message' => 'Content generation started. You can monitor progress on this page.',
        'progress' => 0
    ]);
}

/**
 * Process content generation in the background.
 * 
 * @param string $status_id The status ID for tracking.
 */
function sumai_process_content_generation($status_id) {
    // Get parameters from transient
    $params = get_transient('sumai_generate_params_' . $status_id);
    if (!$params) {
        sumai_update_status($status_id, 'Error: Parameters not found for this generation process.', 'error');
        return;
    }
    
    // Extract parameters
    $draft_mode = isset($params['draft_mode']) ? (bool)$params['draft_mode'] : false;
    $respect_processed = isset($params['respect_processed']) ? (bool)$params['respect_processed'] : true;
    
    // Delete the transient as we no longer need it
    delete_transient('sumai_generate_params_' . $status_id);
    
    try {
        // Get settings
        $opts = get_option('sumai_settings', array());
        $feed_urls = isset($opts['feed_urls']) ? explode("\n", $opts['feed_urls']) : array();
        $feed_urls = array_map('trim', $feed_urls);
        $feed_urls = array_filter($feed_urls);
        
        if (empty($feed_urls)) {
            sumai_update_status($status_id, 'No feed URLs configured. Please add feed URLs in the settings.', 'error');
            return;
        }
        
        // Step 1: Fetch feeds
        sumai_update_status($status_id, 'Fetching RSS feeds...', 'processing', [
            'total_steps' => 5,
            'current_step' => 1,
            'progress' => 20
        ]);
        
        // Log the action
        sumai_log_event('Manual generation started: Fetching feeds');
        
        // Load required files
        if (!function_exists('fetch_feed')) {
            require_once(ABSPATH . WPINC . '/feed.php');
        }
        
        $articles = array();
        $processed_guids = sumai_get_processed_guids();
        
        foreach ($feed_urls as $url) {
            try {
                $feed = fetch_feed($url);
                
                if (is_wp_error($feed)) {
                    sumai_log_event('Error fetching feed: ' . $feed->get_error_message());
                    continue;
                }
                
                $items = $feed->get_items(0, 10); // Limit to 10 items per feed
                
                foreach ($items as $item) {
                    $guid = $item->get_id();
                    
                    // Skip if already processed and respect_processed is true
                    if ($respect_processed && isset($processed_guids[$guid])) {
                        continue;
                    }
                    
                    $content = $item->get_content();
                    $title = $item->get_title();
                    $link = $item->get_permalink();
                    $date = $item->get_date('U');
                    
                    $articles[] = array(
                        'guid' => $guid,
                        'title' => $title,
                        'content' => $content,
                        'link' => $link,
                        'date' => $date,
                        'content_hash' => sumai_generate_content_hash($content)
                    );
                }
            } catch (Exception $e) {
                sumai_log_event('Exception processing feed: ' . $e->getMessage(), true);
            }
        }
        
        if (empty($articles)) {
            sumai_update_status($status_id, 'No new articles found to process.', 'error');
            return;
        }
        
        // Sort articles by date (newest first)
        usort($articles, function($a, $b) {
            return $b['date'] - $a['date'];
        });
        
        // Step 2: Process feeds
        sumai_update_status($status_id, 'Processing feed content...', 'processing', [
            'total_steps' => 5,
            'current_step' => 2,
            'progress' => 40
        ]);
        
        // Log progress
        sumai_log_event('Manual generation: Processing ' . count($articles) . ' articles');
        
        // Step 3: Prepare for AI processing
        sumai_update_status($status_id, 'Preparing content for AI processing...', 'processing', [
            'total_steps' => 5,
            'current_step' => 3,
            'progress' => 60
        ]);
        
        // Prepare content for AI
        $context_prompt = isset($opts['context_prompt']) ? $opts['context_prompt'] : '';
        $title_prompt = isset($opts['title_prompt']) ? $opts['title_prompt'] : '';
        
        // Step 4: Generate content with AI
        sumai_update_status($status_id, 'Generating content with AI...', 'processing', [
            'total_steps' => 5,
            'current_step' => 4,
            'progress' => 80
        ]);
        
        // Log progress
        sumai_log_event('Manual generation: Generating content with AI');
        
        // Generate content
        $generated_content = sumai_generate_content($articles, $context_prompt);
        
        if (is_wp_error($generated_content)) {
            $error_message = 'Error generating content: ' . $generated_content->get_error_message();
            sumai_log_event($error_message, true);
            sumai_update_status($status_id, $error_message, 'error');
            return;
        }
        
        // Generate title
        $generated_title = sumai_generate_title($generated_content, $title_prompt);
        
        if (is_wp_error($generated_title)) {
            $generated_title = 'AI-Generated Summary: ' . current_time('F j, Y');
        }
        
        // Step 5: Create post
        sumai_update_status($status_id, 'Creating post...', 'processing', [
            'total_steps' => 5,
            'current_step' => 5,
            'progress' => 90
        ]);
        
        // Log progress
        sumai_log_event('Manual generation: Creating post');
        
        // Add signature if configured
        if (!empty($opts['post_signature'])) {
            $generated_content .= "\n\n" . $opts['post_signature'];
        }
        
        // Create post
        $post_id = wp_insert_post(array(
            'post_title' => $generated_title,
            'post_content' => $generated_content,
            'post_status' => $draft_mode ? 'draft' : 'publish',
            'post_author' => get_current_user_id(),
            'post_type' => 'post'
        ));
        
        if (is_wp_error($post_id)) {
            $error_message = 'Error creating post: ' . $post_id->get_error_message();
            sumai_log_event($error_message, true);
            sumai_update_status($status_id, $error_message, 'error');
            return;
        }
        
        // Mark articles as processed
        foreach ($articles as $article) {
            sumai_mark_guid_as_processed($article['guid'], $article['content_hash']);
        }
        
        // Update status to complete
        $success_message = sprintf(
            'Content generation complete! Post ID: %d (%s)',
            $post_id,
            $draft_mode ? 'Draft' : 'Published'
        );
        
        sumai_update_status($status_id, $success_message, 'complete', [
            'total_steps' => 5,
            'current_step' => 5,
            'progress' => 100,
            'post_id' => $post_id,
            'post_title' => $generated_title,
            'post_status' => $draft_mode ? 'draft' : 'publish'
        ]);
        
        // Log completion
        sumai_log_event('Manual generation complete: Post ID ' . $post_id);
    } catch (Exception $e) {
        // Log error and update status
        $error_message = 'Error during content generation: ' . $e->getMessage();
        sumai_log_event($error_message, true);
        sumai_update_status($status_id, $error_message, 'error');
    }
}

/**
 * AJAX handler for checking status.
 */
function sumai_ajax_check_status() {
    // Verify nonce
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'sumai_nonce' ) ) {
        wp_send_json_error( array(
            'message' => 'Security check failed.'
        ) );
    }

    // Check user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array(
            'message' => 'You do not have permission to perform this action.'
        ) );
    }

    // Get status ID from request
    $status_id = isset( $_POST['status_id'] ) ? sanitize_text_field( $_POST['status_id'] ) : '';
    
    if ( empty( $status_id ) ) {
        wp_send_json_error( array(
            'message' => 'Status ID is required.'
        ) );
    }
    
    // Get status data
    $status = sumai_get_status( $status_id );
    
    if ( ! $status ) {
        wp_send_json_error( array(
            'message' => 'Status not found. It may have expired.'
        ) );
    }
    
    // Log status check for debugging
    sumai_log_event( 'Status check for ID: ' . $status_id . ' - State: ' . $status['state'] );
    
    // Return status data
    wp_send_json_success( $status );
}

/**
 * AJAX handler for getting processed articles with pagination and search.
 */
function sumai_ajax_get_processed_articles() {
    // Check nonce
    if (!check_ajax_referer('sumai_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Security check failed']);
    }
    
    // Check capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }
    
    // Get pagination parameters
    $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
    $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 20;
    $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
    
    // Get processed GUIDs and hashes
    $guids = sumai_get_processed_guids();
    $hashes = sumai_get_processed_hashes();
    
    // Combine and format the data
    $articles = [];
    
    // Process GUIDs
    foreach ($guids as $guid => $timestamp) {
        $articles[$guid] = [
            'id' => $guid,
            'type' => 'guid',
            'timestamp' => $timestamp,
            'date' => date('Y-m-d H:i:s', $timestamp),
            'age' => human_time_diff($timestamp, time()) . ' ago'
        ];
    }
    
    // Process content hashes
    foreach ($hashes as $hash => $timestamp) {
        // If we already have this as a GUID, just add the hash info
        $found = false;
        foreach ($articles as &$article) {
            if (isset($article['content_hash']) && $article['content_hash'] === $hash) {
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            $articles[$hash] = [
                'id' => $hash,
                'type' => 'hash',
                'timestamp' => $timestamp,
                'date' => date('Y-m-d H:i:s', $timestamp),
                'age' => human_time_diff($timestamp, time()) . ' ago'
            ];
        }
    }
    
    // Apply search filter if provided
    if (!empty($search)) {
        $articles = array_filter($articles, function($article) use ($search) {
            return stripos($article['id'], $search) !== false;
        });
    }
    
    // Sort by timestamp (newest first)
    usort($articles, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    // Calculate pagination
    $total_items = count($articles);
    $total_pages = ceil($total_items / $per_page);
    
    // Ensure page is within bounds
    $page = max(1, min($page, $total_pages));
    
    // Get items for current page
    $offset = ($page - 1) * $per_page;
    $paged_articles = array_slice($articles, $offset, $per_page);
    
    // Return paginated results
    wp_send_json_success([
        'articles' => $paged_articles,
        'pagination' => [
            'page' => $page,
            'per_page' => $per_page,
            'total_items' => $total_items,
            'total_pages' => $total_pages
        ]
    ]);
}

/**
 * AJAX handler for clearing all processed articles.
 */
function sumai_ajax_clear_all_articles() {
    // Check nonce
    if (!check_ajax_referer('sumai_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Security check failed']);
    }
    
    // Check capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }
    
    // Clear all processed GUIDs and hashes
    $guids_cleared = update_option(SUMAI_PROCESSED_GUIDS_OPTION, []);
    $hashes_cleared = update_option(SUMAI_PROCESSED_HASHES_OPTION, []);
    
    if ($guids_cleared && $hashes_cleared) {
        sumai_log_event('All processed articles cleared by admin');
        wp_send_json_success(['message' => 'All processed articles have been cleared']);
    } else {
        wp_send_json_error(['message' => 'Failed to clear processed articles']);
    }
}

/**
 * AJAX handler for clearing a specific processed article.
 */
function sumai_ajax_clear_article() {
    // Check nonce
    if (!check_ajax_referer('sumai_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Security check failed']);
    }
    
    // Check capabilities
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
    }
    
    // Get article ID and type
    if (!isset($_POST['id']) || empty($_POST['id'])) {
        wp_send_json_error(['message' => 'No article ID provided']);
    }
    
    $id = sanitize_text_field($_POST['id']);
    $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
    
    // Get current data
    $guids = sumai_get_processed_guids();
    $hashes = sumai_get_processed_hashes();
    
    $updated = false;
    
    // Remove from appropriate array
    if ($type === 'guid' && isset($guids[$id])) {
        unset($guids[$id]);
        $updated = update_option(SUMAI_PROCESSED_GUIDS_OPTION, $guids);
        sumai_log_event("Processed article GUID cleared: $id");
    } elseif ($type === 'hash' && isset($hashes[$id])) {
        unset($hashes[$id]);
        $updated = update_option(SUMAI_PROCESSED_HASHES_OPTION, $hashes);
        sumai_log_event("Processed article hash cleared: $id");
    } else {
        // Try both if type not specified
        if (isset($guids[$id])) {
            unset($guids[$id]);
            $updated = update_option(SUMAI_PROCESSED_GUIDS_OPTION, $guids);
            sumai_log_event("Processed article GUID cleared: $id");
        }
        
        if (isset($hashes[$id])) {
            unset($hashes[$id]);
            $updated = $updated || update_option(SUMAI_PROCESSED_HASHES_OPTION, $hashes);
            sumai_log_event("Processed article hash cleared: $id");
        }
    }
    
    if ($updated) {
        wp_send_json_success(['message' => 'Article has been cleared']);
    } else {
        wp_send_json_error(['message' => 'Article not found or could not be cleared']);
    }
}

sumai_register_ajax_handlers();
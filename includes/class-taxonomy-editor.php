<?php
/**
 * Main class for Taxonomy Editor functionality
 */

// Ensure WordPress is loaded
if (!defined('ABSPATH')) {
    exit;
}

class Taxonomy_Editor {
    /**
     * Initialize the plugin
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('wp_ajax_bulk_edit_taxonomy', array($this, 'handle_bulk_edit'));
        add_action('wp_ajax_merge_terms', array($this, 'handle_term_merge'));
        add_filter('bulk_actions-edit-tags', array($this, 'register_bulk_actions'));
        add_filter('bulk_actions-edit-category', array($this, 'register_bulk_actions'));
        add_filter('bulk_actions-edit-post_tag', array($this, 'add_merge_bulk_action'));
        add_filter('bulk_actions-edit-category', array($this, 'add_merge_bulk_action'));
        add_filter('handle_bulk_actions-edit-post_tag', array($this, 'handle_merge_bulk_action'), 10, 3);
        add_filter('handle_bulk_actions-edit-category', array($this, 'handle_merge_bulk_action'), 10, 3);
        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('admin_footer', array($this, 'add_merge_modal'));
        add_action('restrict_manage_posts', array($this, 'add_tag_filter'));
        add_filter('parse_query', array($this, 'filter_posts_by_tag'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_select2'));
        
        // Add bulk edit actions for posts
        add_filter('bulk_actions-edit-post', array($this, 'add_tag_bulk_actions'));
        add_filter('handle_bulk_actions-edit-post', array($this, 'handle_tag_bulk_actions'), 10, 3);
    }

    /**
     * Add menu items
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit-tags.php?taxonomy=category',
            __('Bulk Edit Categories', 'taxonomy-editor'),
            __('Bulk Edit', 'taxonomy-editor'),
            'manage_categories',
            'bulk-edit-categories',
            array($this, 'render_bulk_edit_page')
        );

        add_submenu_page(
            'edit-tags.php?taxonomy=post_tag',
            __('Bulk Edit Tags', 'taxonomy-editor'),
            __('Bulk Edit', 'taxonomy-editor'),
            'manage_categories',
            'bulk-edit-tags',
            array($this, 'render_bulk_edit_page')
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (!in_array($hook, array('edit-tags.php', 'term.php'))) {
            return;
        }

        wp_enqueue_style(
            'taxonomy-editor-styles',
            TAXONOMY_EDITOR_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            TAXONOMY_EDITOR_VERSION
        );

        wp_enqueue_script(
            'taxonomy-editor-scripts',
            TAXONOMY_EDITOR_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'jquery-ui-sortable'),
            TAXONOMY_EDITOR_VERSION,
            true
        );

        wp_localize_script('taxonomy-editor-scripts', 'taxonomyEditorData', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('taxonomy-editor-nonce'),
            'strings' => array(
                'selectMultiple' => __('Please select at least two terms to merge.', 'taxonomy-editor'),
                'selectPrimary' => __('Please select a primary term to merge into.', 'taxonomy-editor'),
                'merging' => __('Merging...', 'taxonomy-editor'),
                'error' => __('An error occurred. Please try again.', 'taxonomy-editor'),
                'confirmMerge' => __('Are you sure you want to merge these terms? This action cannot be undone.', 'taxonomy-editor')
            )
        ));
    }

    /**
     * Register bulk actions
     */
    public function register_bulk_actions($bulk_actions) {
        $bulk_actions['merge_terms'] = __('Merge Selected', 'taxonomy-editor');
        return $bulk_actions;
    }

    /**
     * Render bulk edit page
     */
    public function render_bulk_edit_page() {
        $taxonomy = isset($_GET['taxonomy']) ? sanitize_text_field($_GET['taxonomy']) : 'category';
        $terms = get_terms(array(
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
        ));

        include TAXONOMY_EDITOR_PLUGIN_DIR . 'templates/bulk-edit.php';
    }

    /**
     * Handle bulk edit AJAX request
     */
    public function handle_bulk_edit() {
        check_ajax_referer('taxonomy-editor-nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Permission denied');
        }

        $action = isset($_POST['bulk_action']) ? sanitize_text_field($_POST['bulk_action']) : '';

        switch ($action) {
            case 'get_tags':
                // Get all available tags
                $tags = get_terms(array(
                    'taxonomy' => 'post_tag',
                    'hide_empty' => false,
                ));

                if (is_wp_error($tags)) {
                    wp_send_json_error('Error loading tags');
                    return;
                }

                wp_send_json_success(array('tags' => $tags));
                break;

            case 'get_post_tags':
                $post_ids = isset($_POST['post_ids']) ? array_map('intval', (array) $_POST['post_ids']) : array();
                if (empty($post_ids)) {
                    wp_send_json_error('No posts selected');
                    return;
                }

                // Get all tags from selected posts
                $post_tags = array();
                foreach ($post_ids as $post_id) {
                    $tags = wp_get_post_tags($post_id);
                    foreach ($tags as $tag) {
                        $post_tags[$tag->term_id] = $tag;
                    }
                }

                wp_send_json_success(array('tags' => array_values($post_tags)));
                break;

            case 'assign_tags':
                $post_ids = isset($_POST['post_ids']) ? array_map('intval', (array) $_POST['post_ids']) : array();
                $tag_ids = isset($_POST['tag_ids']) ? array_map('intval', (array) $_POST['tag_ids']) : array();

                if (empty($post_ids) || empty($tag_ids)) {
                    wp_send_json_error('Invalid parameters');
                    return;
                }

                $success = true;
                $error_message = '';

                foreach ($post_ids as $post_id) {
                    $existing_tags = wp_get_post_tags($post_id, array('fields' => 'ids'));
                    $new_tags = array_unique(array_merge($existing_tags, $tag_ids));
                    $result = wp_set_post_tags($post_id, $new_tags, false);
                    
                    if (is_wp_error($result)) {
                        $success = false;
                        $error_message = $result->get_error_message();
                        break;
                    }
                }

                if (!$success) {
                    wp_send_json_error($error_message);
                    return;
                }

                wp_send_json_success('Tags assigned successfully');
                break;

            case 'unassign_tags':
                $post_ids = isset($_POST['post_ids']) ? array_map('intval', (array) $_POST['post_ids']) : array();
                $tag_ids = isset($_POST['tag_ids']) ? array_map('intval', (array) $_POST['tag_ids']) : array();

                if (empty($post_ids) || empty($tag_ids)) {
                    wp_send_json_error('Invalid parameters');
                    return;
                }

                $success = true;
                $error_message = '';

                foreach ($post_ids as $post_id) {
                    $existing_tags = wp_get_post_tags($post_id, array('fields' => 'ids'));
                    $new_tags = array_diff($existing_tags, $tag_ids);
                    $result = wp_set_post_tags($post_id, $new_tags, false);
                    
                    if (is_wp_error($result)) {
                        $success = false;
                        $error_message = $result->get_error_message();
                        break;
                    }
                }

                if (!$success) {
                    wp_send_json_error($error_message);
                    return;
                }

                wp_send_json_success('Tags unassigned successfully');
                break;

            default:
                wp_send_json_error('Invalid action');
                return;
        }
    }

    /**
     * Handle term merge AJAX request
     */
    public function handle_term_merge() {
        // Set error handling to catch everything
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        ini_set('log_errors', 1);
        ini_set('error_log', WP_CONTENT_DIR . '/debug.log');
        
        // Register shutdown function to catch fatal errors
        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_USER_ERROR])) {
                error_log('Fatal PHP error during term merge: ' . print_r($error, true));
                wp_send_json_error('A fatal error occurred: ' . $error['message']);
            }
        });

        try {
            error_log('-------- Starting Term Merge --------');
            error_log('POST data: ' . print_r($_POST, true));
            
            // Verify nonce first
            if (!check_ajax_referer('taxonomy-editor-nonce', 'nonce', false)) {
                error_log('Nonce verification failed');
                wp_send_json_error('Invalid security token.');
                return;
            }

            // Check user capabilities
            if (!current_user_can('manage_categories')) {
                error_log('Permission check failed');
                wp_send_json_error('You do not have permission to merge terms.');
                return;
            }

            // Get and validate parameters
            $primary_term_id = isset($_POST['primary_term_id']) ? intval($_POST['primary_term_id']) : 0;
            $term_ids = isset($_POST['term_ids']) ? array_map('intval', (array) $_POST['term_ids']) : array();
            $taxonomy = isset($_POST['taxonomy']) ? sanitize_text_field($_POST['taxonomy']) : '';

            error_log('Validated parameters:');
            error_log('Primary Term ID: ' . $primary_term_id);
            error_log('Term IDs: ' . print_r($term_ids, true));
            error_log('Taxonomy: ' . $taxonomy);

            // Basic validation
            if (empty($primary_term_id)) {
                error_log('Missing primary term ID');
                wp_send_json_error('Missing primary term ID.');
                return;
            }
            if (empty($term_ids)) {
                error_log('No terms provided to merge');
                wp_send_json_error('No terms provided to merge.');
                return;
            }
            if (empty($taxonomy)) {
                error_log('Missing taxonomy');
                wp_send_json_error('Missing taxonomy.');
                return;
            }

            // Validate taxonomy exists
            if (!taxonomy_exists($taxonomy)) {
                error_log('Invalid taxonomy: ' . $taxonomy);
                wp_send_json_error('Invalid taxonomy: ' . $taxonomy);
                return;
            }

            // Validate primary term exists
            $primary_term = get_term($primary_term_id, $taxonomy);
            if (!$primary_term || is_wp_error($primary_term)) {
                $error_msg = is_wp_error($primary_term) ? $primary_term->get_error_message() : 'Primary term not found';
                error_log('Invalid primary term: ' . $error_msg);
                wp_send_json_error('Invalid primary term: ' . $error_msg);
                return;
            }

            error_log('Primary term validated: ' . print_r($primary_term, true));

            // Load and initialize merger
            if (!class_exists('Taxonomy_Merger')) {
                require_once TAXONOMY_EDITOR_PLUGIN_DIR . 'includes/class-taxonomy-merger.php';
            }

            $merger = new Taxonomy_Merger();
            
            // Perform merge
            error_log('Attempting to merge terms...');
            $result = $merger->merge_terms($primary_term_id, $term_ids, $taxonomy);

            if (is_wp_error($result)) {
                $error_msg = $result->get_error_message();
                error_log('Merge failed with WP_Error: ' . $error_msg);
                wp_send_json_error($error_msg);
                return;
            }

            if ($result !== true) {
                error_log('Merge failed without specific error');
                wp_send_json_error('Merge operation failed without specific error.');
                return;
            }

            error_log('Merge completed successfully');
            wp_send_json_success(array(
                'message' => sprintf(
                    _n(
                        'Successfully merged %d term into %s.',
                        'Successfully merged %d terms into %s.',
                        count($term_ids),
                        'taxonomy-editor'
                    ),
                    count($term_ids),
                    $primary_term->name
                )
            ));

        } catch (Throwable $e) {
            // Catch both Exception and Error
            error_log('Taxonomy Editor merge error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Add merge option to bulk actions dropdown
     */
    public function add_merge_bulk_action($bulk_actions) {
        $bulk_actions['merge'] = __('Merge', 'taxonomy-editor');
        return $bulk_actions;
    }

    /**
     * Handle the merge bulk action
     */
    public function handle_merge_bulk_action($redirect_to, $doaction, $term_ids) {
        if ($doaction !== 'merge') {
            return $redirect_to;
        }

        if (count($term_ids) < 2) {
            return add_query_arg('error', 'taxonomy-editor-min-terms', $redirect_to);
        }

        $taxonomy = $_GET['taxonomy'] ?? 'post_tag';
        $merger = new Taxonomy_Merger();
        $result = $merger->merge_terms($term_ids, $taxonomy);

        if (is_wp_error($result)) {
            return add_query_arg('error', 'taxonomy-editor-merge-failed', $redirect_to);
        }

        return add_query_arg('merged', count($term_ids) - 1, $redirect_to);
    }

    /**
     * Display admin notices for feedback
     */
    public function admin_notices() {
        if (!isset($_GET['taxonomy'])) {
            return;
        }

        if (isset($_GET['error'])) {
            switch ($_GET['error']) {
                case 'taxonomy-editor-min-terms':
                    $message = __('Please select at least two terms to merge.', 'taxonomy-editor');
                    echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
                    break;
                case 'taxonomy-editor-merge-failed':
                    $message = __('Failed to merge terms. Please try again.', 'taxonomy-editor');
                    echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
                    break;
            }
        }

        if (isset($_GET['merged'])) {
            $count = intval($_GET['merged']);
            $message = sprintf(
                _n(
                    '%s term has been merged successfully.',
                    '%s terms have been merged successfully.',
                    $count,
                    'taxonomy-editor'
                ),
                number_format_i18n($count)
            );
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        }
    }

    /**
     * Add merge modal to footer
     */
    public function add_merge_modal() {
        // Remove this method as the modal is now created in JavaScript
    }

    /**
     * Add tag filter dropdown to posts list
     */
    public function add_tag_filter() {
        global $typenow;
        
        // Only add to post type 'post'
        if ($typenow != 'post') {
            return;
        }

        // Get all tags
        $tags = get_terms(array(
            'taxonomy' => 'post_tag',
            'hide_empty' => false,
        ));

        if (!empty($tags) && !is_wp_error($tags)) {
            // Get currently selected tags
            $selected_tags = isset($_GET['post_tag']) ? (array) $_GET['post_tag'] : array();
            
            echo '<select name="post_tag[]" id="tag-filter" class="tag-filter" multiple="multiple" style="width: 200px;">';
            foreach ($tags as $tag) {
                $selected = in_array($tag->slug, $selected_tags) ? ' selected="selected"' : '';
                echo sprintf(
                    '<option value="%s"%s>%s</option>',
                    esc_attr($tag->slug),
                    $selected,
                    esc_html($tag->name)
                );
            }
            echo '</select>';
        }
    }

    /**
     * Filter posts by selected tags
     */
    public function filter_posts_by_tag($query) {
        global $pagenow, $typenow;

        // Only filter on admin post list page
        if (!is_admin() || $pagenow !== 'edit.php' || $typenow !== 'post') {
            return;
        }

        // Check if our filter is set
        if (isset($_GET['post_tag']) && !empty($_GET['post_tag'])) {
            $tags = (array) $_GET['post_tag'];
            
            // Remove empty values
            $tags = array_filter($tags);

            if (!empty($tags)) {
                $tax_query = array(
                    array(
                        'taxonomy' => 'post_tag',
                        'field' => 'slug',
                        'terms' => $tags,
                        'operator' => 'AND' // Posts must have all selected tags
                    )
                );

                $query->set('tax_query', $tax_query);
            }
        }
    }

    /**
     * Enqueue Select2 for enhanced dropdown
     */
    public function enqueue_select2($hook) {
        if ($hook !== 'edit.php') {
            return;
        }

        global $typenow;
        if ($typenow !== 'post') {
            return;
        }

        // Enqueue Select2
        wp_enqueue_style(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
            array(),
            '4.1.0-rc.0'
        );

        wp_enqueue_script(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            array('jquery'),
            '4.1.0-rc.0',
            true
        );

        // Initialize Select2
        wp_add_inline_script('select2', '
            jQuery(document).ready(function($) {
                $("#tag-filter").select2({
                    placeholder: "Filter by tags",
                    allowClear: true,
                    width: "200px"
                });
            });
        ');

        // Add some custom CSS
        wp_add_inline_style('select2', '
            .select2-container {
                margin: 1px 8px 0 0;
            }
            .select2-container--default .select2-selection--multiple {
                border-color: #8c8f94;
            }
            .select2-container .select2-selection--multiple {
                min-height: 32px;
            }
            .select2-container--default .select2-selection--multiple .select2-selection__choice {
                margin-top: 4px;
                background-color: #f0f0f1;
                border-color: #8c8f94;
            }
        ');
    }

    /**
     * Add tag assignment bulk actions to posts
     */
    public function add_tag_bulk_actions($bulk_actions) {
        $bulk_actions['assign_tag'] = __('Assign Tag', 'taxonomy-editor');
        $bulk_actions['unassign_tag'] = __('Unassign Tag', 'taxonomy-editor');
        return $bulk_actions;
    }

    /**
     * Handle tag assignment bulk actions
     */
    public function handle_tag_bulk_actions($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'assign_tag' && $doaction !== 'unassign_tag') {
            return $redirect_to;
        }

        // Show tag selection modal via JavaScript
        if ($doaction === 'assign_tag') {
            add_action('admin_footer', function() use ($post_ids) {
                $this->render_tag_assignment_modal($post_ids);
            });
            return $redirect_to;
        }

        // Show tag unassignment modal via JavaScript
        if ($doaction === 'unassign_tag') {
            add_action('admin_footer', function() use ($post_ids) {
                $this->render_tag_unassignment_modal($post_ids);
            });
            return $redirect_to;
        }

        return $redirect_to;
    }

    /**
     * Render tag assignment modal
     */
    private function render_tag_assignment_modal($post_ids) {
        $tags = get_terms(array(
            'taxonomy' => 'post_tag',
            'hide_empty' => false,
        ));
        ?>
        <div id="assign-tag-modal" class="tag-modal" style="display:none;">
            <div class="tag-modal-content">
                <h2><?php _e('Assign Tags', 'taxonomy-editor'); ?></h2>
                <p><?php _e('Select tags to assign to the selected posts:', 'taxonomy-editor'); ?></p>
                <select id="tags-to-assign" multiple="multiple" style="width: 100%;">
                    <?php foreach ($tags as $tag): ?>
                        <option value="<?php echo esc_attr($tag->term_id); ?>">
                            <?php echo esc_html($tag->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="tag-modal-buttons">
                    <button type="button" class="button button-primary" id="confirm-assign-tags">
                        <?php _e('Assign Tags', 'taxonomy-editor'); ?>
                    </button>
                    <button type="button" class="button" id="cancel-assign-tags">
                        <?php _e('Cancel', 'taxonomy-editor'); ?>
                    </button>
                </div>
                <input type="hidden" id="selected-post-ids" value="<?php echo esc_attr(implode(',', $post_ids)); ?>">
            </div>
        </div>
        <?php
    }

    /**
     * Render tag unassignment modal
     */
    private function render_tag_unassignment_modal($post_ids) {
        // Get all tags that are assigned to at least one of the selected posts
        $post_tags = array();
        foreach ($post_ids as $post_id) {
            $tags = wp_get_post_tags($post_id);
            foreach ($tags as $tag) {
                $post_tags[$tag->term_id] = $tag;
            }
        }
        ?>
        <div id="unassign-tag-modal" class="tag-modal" style="display:none;">
            <div class="tag-modal-content">
                <h2><?php _e('Unassign Tags', 'taxonomy-editor'); ?></h2>
                <p><?php _e('Select tags to remove from the selected posts:', 'taxonomy-editor'); ?></p>
                <select id="tags-to-unassign" multiple="multiple" style="width: 100%;">
                    <?php foreach ($post_tags as $tag): ?>
                        <option value="<?php echo esc_attr($tag->term_id); ?>">
                            <?php echo esc_html($tag->name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="tag-modal-buttons">
                    <button type="button" class="button button-primary" id="confirm-unassign-tags">
                        <?php _e('Unassign Tags', 'taxonomy-editor'); ?>
                    </button>
                    <button type="button" class="button" id="cancel-unassign-tags">
                        <?php _e('Cancel', 'taxonomy-editor'); ?>
                    </button>
                </div>
                <input type="hidden" id="selected-post-ids" value="<?php echo esc_attr(implode(',', $post_ids)); ?>">
            </div>
        </div>
        <?php
    }
} 
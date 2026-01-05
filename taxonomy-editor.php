<?php
/**
 * Plugin Name: Taxonomy Editor
 * Plugin URI: https://example.com/taxonomy-editor
 * Description: Bulk edit categories and tags for any post type, with merging capabilities and meta updates.
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * Text Domain: taxonomy-editor
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('TAXONOMY_EDITOR_VERSION', '1.0.0');
define('TAXONOMY_EDITOR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TAXONOMY_EDITOR_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once TAXONOMY_EDITOR_PLUGIN_DIR . 'includes/class-taxonomy-editor.php';
require_once TAXONOMY_EDITOR_PLUGIN_DIR . 'includes/class-taxonomy-merger.php';

// Initialize the plugin
function taxonomy_editor_init() {
    $plugin = new Taxonomy_Editor();
    $plugin->init();
}
add_action('plugins_loaded', 'taxonomy_editor_init');
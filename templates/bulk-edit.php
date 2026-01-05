<?php
if (!defined('ABSPATH')) {
    exit;
}

$taxonomy_object = get_taxonomy($taxonomy);
$taxonomy_label = $taxonomy_object->labels->name;
?>

<div class="wrap taxonomy-editor-bulk-edit">
    <h1><?php printf(__('Bulk Edit %s', 'taxonomy-editor'), $taxonomy_label); ?></h1>

    <div class="taxonomy-editor-merge-section">
        <h2><?php _e('Merge Terms', 'taxonomy-editor'); ?></h2>
        <p class="description">
            <?php _e('Select multiple terms to merge them. The first selected term will be the primary term that others will be merged into.', 'taxonomy-editor'); ?>
        </p>

        <form id="taxonomy-editor-merge-form" method="post">
            <?php wp_nonce_field('taxonomy-editor-merge', 'taxonomy-editor-nonce'); ?>
            <input type="hidden" name="taxonomy" value="<?php echo esc_attr($taxonomy); ?>">
            
            <div class="terms-selection">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th class="column-primary"><?php _e('Term', 'taxonomy-editor'); ?></th>
                            <th><?php _e('Count', 'taxonomy-editor'); ?></th>
                            <th><?php _e('Actions', 'taxonomy-editor'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($terms as $term): ?>
                            <tr>
                                <td class="column-primary">
                                    <label>
                                        <input type="checkbox" name="term_ids[]" value="<?php echo esc_attr($term->term_id); ?>">
                                        <?php echo esc_html($term->name); ?>
                                    </label>
                                </td>
                                <td><?php echo esc_html($term->count); ?></td>
                                <td>
                                    <a href="<?php echo get_edit_term_link($term->term_id, $taxonomy); ?>" class="button button-small">
                                        <?php _e('Edit', 'taxonomy-editor'); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="merge-actions">
                <button type="submit" class="button button-primary" id="merge-terms-button">
                    <?php _e('Merge Selected Terms', 'taxonomy-editor'); ?>
                </button>
                <span class="spinner"></span>
            </div>
        </form>
    </div>
</div> 
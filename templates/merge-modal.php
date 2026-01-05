<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="taxonomy-editor-merge-modal" class="taxonomy-editor-modal" style="display:none;">
    <div class="taxonomy-editor-modal-content">
        <h2><?php _e('Select Primary Term', 'taxonomy-editor'); ?></h2>
        <p class="description">
            <?php _e('Select the term that others will be merged into. All posts from other terms will be assigned to this term.', 'taxonomy-editor'); ?>
        </p>
        <div class="term-list">
            <!-- Terms will be populated here via JavaScript -->
        </div>
        <div class="modal-actions">
            <button type="button" class="button button-primary" id="confirm-merge">
                <?php _e('Merge Terms', 'taxonomy-editor'); ?>
            </button>
            <button type="button" class="button" id="cancel-merge">
                <?php _e('Cancel', 'taxonomy-editor'); ?>
            </button>
        </div>
    </div>
</div> 
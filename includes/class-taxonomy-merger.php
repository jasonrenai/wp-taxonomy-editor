<?php
/**
 * Class for handling taxonomy term merging
 */

// Ensure WordPress is loaded
if (!defined('ABSPATH')) {
    exit;
}

class Taxonomy_Merger {
    /**
     * Merge multiple terms into one
     *
     * @param int    $primary_term_id The ID of the term to keep
     * @param array  $term_ids Array of term IDs to merge
     * @param string $taxonomy Taxonomy name
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function merge_terms($primary_term_id, $term_ids, $taxonomy) {
        global $wpdb;

        error_log('Starting merge_terms in Taxonomy_Merger');
        error_log('Primary Term ID: ' . $primary_term_id);
        error_log('Term IDs to merge: ' . print_r($term_ids, true));
        error_log('Taxonomy: ' . $taxonomy);

        try {
            // Validate input
            if (empty($term_ids) || !is_array($term_ids)) {
                error_log('Invalid terms provided - empty or not array');
                return new WP_Error('invalid_terms', __('Invalid terms provided', 'taxonomy-editor'));
            }

            // Get the primary term
            $primary_term = get_term($primary_term_id, $taxonomy);
            error_log('Primary term fetch result: ' . print_r($primary_term, true));
            
            if (!$primary_term || is_wp_error($primary_term)) {
                error_log('Invalid primary term - not found or error');
                return new WP_Error('invalid_primary_term', __('Invalid primary term', 'taxonomy-editor'));
            }

            // Get the primary term taxonomy ID
            $primary_tt_id = $primary_term->term_taxonomy_id;
            error_log('Primary term taxonomy ID: ' . $primary_tt_id);

            // Remove primary term from the merge list if present
            $merge_term_ids = array_diff($term_ids, array($primary_term_id));
            error_log('Terms to merge after removing primary: ' . print_r($merge_term_ids, true));
            
            if (empty($merge_term_ids)) {
                error_log('No terms to merge after filtering');
                return new WP_Error('no_terms_to_merge', __('No terms to merge', 'taxonomy-editor'));
            }

            // Begin transaction
            error_log('Starting database transaction');
            $wpdb->query('START TRANSACTION');

            try {
                foreach ($merge_term_ids as $term_id) {
                    error_log('Processing term ID: ' . $term_id);
                    
                    $term = get_term($term_id, $taxonomy);
                    error_log('Term fetch result: ' . print_r($term, true));
                    
                    if (!$term || is_wp_error($term)) {
                        error_log('Invalid term, skipping: ' . $term_id);
                        continue;
                    }

                    // Update posts to use the primary term
                    error_log('Updating term relationships from ' . $term->term_taxonomy_id . ' to ' . $primary_tt_id);
                    $result = $this->update_term_relationships($term->term_taxonomy_id, $primary_tt_id);
                    if (is_wp_error($result)) {
                        throw new Exception($result->get_error_message());
                    }

                    // Update term counts
                    error_log('Updating term count for ' . $primary_tt_id);
                    wp_update_term_count($primary_tt_id, $taxonomy);

                    // Merge term meta
                    error_log('Merging term meta from ' . $term_id . ' to ' . $primary_term_id);
                    $this->merge_term_meta($term_id, $primary_term_id);

                    try {
                        // Delete the old term
                        error_log('Attempting to delete term: ' . $term_id);
                        
                        // First, check if the term still exists
                        $check_term = get_term($term_id, $taxonomy);
                        if (!$check_term || is_wp_error($check_term)) {
                            error_log('Term ' . $term_id . ' no longer exists before deletion');
                            continue;
                        }
                        
                        // Check if there are any remaining relationships
                        $remaining = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
                            $term->term_taxonomy_id
                        ));
                        error_log('Remaining relationships for term ' . $term_id . ': ' . $remaining);
                        
                        // Delete the term
                        $delete_result = wp_delete_term($term_id, $taxonomy);
                        if (is_wp_error($delete_result)) {
                            error_log('Error deleting term ' . $term_id . ': ' . $delete_result->get_error_message());
                            throw new Exception('Failed to delete term: ' . $delete_result->get_error_message());
                        }
                        error_log('Successfully deleted term: ' . $term_id);
                        
                    } catch (Exception $e) {
                        error_log('Error during term deletion: ' . $e->getMessage());
                        throw $e;
                    }
                }

                error_log('All terms processed, committing transaction');
                $commit_result = $wpdb->query('COMMIT');
                if ($commit_result === false) {
                    throw new Exception('Failed to commit transaction: ' . $wpdb->last_error);
                }
                error_log('Transaction committed successfully');
                
                // Final term count update for primary term
                wp_update_term_count($primary_tt_id, $taxonomy);
                error_log('Updated final term count for primary term');
                
                error_log('Merge completed successfully');
                return true;

            } catch (Exception $e) {
                error_log('Error during merge, rolling back: ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());
                $wpdb->query('ROLLBACK');
                return new WP_Error('merge_failed', $e->getMessage());
            }

        } catch (Exception $e) {
            error_log('Taxonomy_Merger - Unexpected error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return new WP_Error('merge_failed', $e->getMessage());
        }
    }

    /**
     * Update term relationships
     *
     * @param int $old_tt_id Old term_taxonomy_id
     * @param int $new_tt_id New term_taxonomy_id
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    private function update_term_relationships($old_tt_id, $new_tt_id) {
        global $wpdb;

        try {
            error_log('Starting update_term_relationships');
            error_log('Old term_taxonomy_id: ' . $old_tt_id);
            error_log('New term_taxonomy_id: ' . $new_tt_id);

            // Get all objects that have the old term
            $query = $wpdb->prepare(
                "SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
                $old_tt_id
            );
            error_log('Query for objects with old term: ' . $query);
            
            $objects_with_old_term = $wpdb->get_col($query);
            error_log('Objects with old term: ' . print_r($objects_with_old_term, true));

            if (empty($objects_with_old_term)) {
                error_log('No objects found with old term');
                return true; // No objects to update
            }

            // Get the taxonomy for these term relationships
            $taxonomy_query = $wpdb->prepare(
                "SELECT taxonomy FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
                $old_tt_id
            );
            $taxonomy = $wpdb->get_var($taxonomy_query);
            error_log('Taxonomy for term relationships: ' . $taxonomy);

            if (!$taxonomy) {
                throw new Exception('Could not determine taxonomy for term relationships');
            }

            // For each object with the old term
            foreach ($objects_with_old_term as $object_id) {
                error_log('Processing object ID: ' . $object_id);

                // Check if the object already has the new term
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE object_id = %d AND term_taxonomy_id = %d",
                    $object_id,
                    $new_tt_id
                ));

                error_log('Object ' . $object_id . ' has new term: ' . ($exists ? 'yes' : 'no'));

                // Only add the new term if it doesn't exist
                if (!$exists) {
                    error_log('Adding new term relationship for object ' . $object_id);
                    $result = $wpdb->insert(
                        $wpdb->term_relationships,
                        array(
                            'object_id' => $object_id,
                            'term_taxonomy_id' => $new_tt_id,
                            'term_order' => 0
                        ),
                        array('%d', '%d', '%d')
                    );

                    if (false === $result) {
                        $error = $wpdb->last_error ?: 'Failed to add new term relationship';
                        error_log('Insert error: ' . $error);
                        throw new Exception($error);
                    }
                }
            }

            // Remove only the old term relationships
            error_log('Removing old term relationships');
            $result = $wpdb->delete(
                $wpdb->term_relationships,
                array('term_taxonomy_id' => $old_tt_id),
                array('%d')
            );

            if (false === $result && !empty($wpdb->last_error)) {
                error_log('Delete error: ' . $wpdb->last_error);
                throw new Exception($wpdb->last_error);
            }

            // Clean the object term cache for affected posts
            foreach ($objects_with_old_term as $object_id) {
                error_log('Cleaning term cache for object ' . $object_id . ' and taxonomy ' . $taxonomy);
                clean_object_term_cache($object_id, $taxonomy);
            }

            error_log('Term relationships updated successfully');
            return true;

        } catch (Exception $e) {
            error_log('Term relationship update error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return new WP_Error('update_relationships_failed', $e->getMessage());
        }
    }

    /**
     * Merge term meta
     *
     * @param int $old_term_id Old term ID
     * @param int $new_term_id New term ID
     */
    private function merge_term_meta($old_term_id, $new_term_id) {
        $old_meta = get_term_meta($old_term_id);
        
        foreach ($old_meta as $meta_key => $meta_values) {
            foreach ($meta_values as $meta_value) {
                // Only add if it doesn't exist
                if (!metadata_exists('term', $new_term_id, $meta_key)) {
                    add_term_meta($new_term_id, $meta_key, maybe_unserialize($meta_value));
                }
            }
        }
    }
} 
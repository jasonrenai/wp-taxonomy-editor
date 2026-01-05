jQuery(document).ready(function($) {
    'use strict';

    const $form = $('#taxonomy-editor-merge-form');
    const $submitButton = $('#merge-terms-button');
    const $spinner = $('.spinner');

    let selectedTerms = [];
    let primaryTermId = null;

    $form.on('submit', function(e) {
        e.preventDefault();

        const selectedTerms = $('input[name="term_ids[]"]:checked').length;
        if (selectedTerms < 2) {
            alert(taxonomyEditorData.strings.selectMultiple || 'Please select at least two terms to merge.');
            return;
        }

        if (!confirm(taxonomyEditorData.strings.confirmMerge)) {
            return;
        }

        $submitButton.prop('disabled', true);
        $spinner.addClass('is-active');

        const formData = $(this).serialize();

        $.ajax({
            url: taxonomyEditorData.ajaxurl,
            type: 'POST',
            data: {
                action: 'merge_terms',
                nonce: taxonomyEditorData.nonce,
                ...formData
            },
            success: function(response) {
                if (response.success) {
                    alert(taxonomyEditorData.strings.mergeSuccess);
                    window.location.reload();
                } else {
                    alert(response.data || taxonomyEditorData.strings.error);
                }
            },
            error: function() {
                alert(taxonomyEditorData.strings.error);
            },
            complete: function() {
                $submitButton.prop('disabled', false);
                $spinner.removeClass('is-active');
            }
        });
    });

    // Add drag and drop functionality for term reordering
    $('.terms-selection tbody').sortable({
        helper: function(e, ui) {
            ui.children().each(function() {
                $(this).width($(this).width());
            });
            return ui;
        },
        handle: '.column-primary',
        update: function(event, ui) {
            // Update the order of terms if needed
            const termIds = $(this).find('input[name="term_ids[]"]').map(function() {
                return $(this).val();
            }).get();
            
            // You can add AJAX call here if you want to save the order
        }
    });

    // Handle bulk action clicks for posts and terms
    $('#doaction, #doaction2').click(function(e) {
        const select = $(this).siblings('select');
        const selectedAction = select.val();

        if (selectedAction === 'merge') {
            e.preventDefault();

            // Get selected terms
            const checkedBoxes = $('input[name="delete_tags[]"]:checked, input[name="delete_categories[]"]:checked');
            if (checkedBoxes.length < 2) {
                alert(taxonomyEditorData.strings.selectMultiple || 'Please select at least two terms to merge.');
                return;
            }

            // Collect term data
            const terms = checkedBoxes.map(function() {
                const $row = $(this).closest('tr');
                return {
                    id: $(this).val(),
                    name: $row.find('.row-title').text().trim(),
                    count: $row.find('.posts.column-posts').text().trim()
                };
            }).get();

            showMergeModal(terms);
        } else if (selectedAction === 'assign_tag' || selectedAction === 'unassign_tag') {
            e.preventDefault();

            // Get selected posts
            const checkedBoxes = $('input[name="post[]"]:checked');
            if (checkedBoxes.length === 0) {
                alert('Please select at least one post.');
                return;
            }

            // Collect post IDs
            const postIds = checkedBoxes.map(function() {
                return $(this).val();
            }).get();

            if (selectedAction === 'assign_tag') {
                showTagAssignmentModal(postIds);
            } else {
                showTagUnassignmentModal(postIds);
            }
        }
    });

    // Show merge modal with selected terms
    function showMergeModal(terms) {
        // Create modal if it doesn't exist
        if ($('#taxonomy-editor-merge-modal').length === 0) {
            const modalHtml = `
                <div id="taxonomy-editor-merge-modal" class="taxonomy-editor-modal">
                    <div class="taxonomy-editor-modal-content">
                        <h2>Select Primary Term</h2>
                        <p class="description">
                            Select the term that others will be merged into. All posts from other terms will be assigned to this term.
                        </p>
                        <div class="term-list"></div>
                        <div class="modal-actions">
                            <button type="button" class="button button-primary" id="confirm-merge">
                                Merge Terms
                            </button>
                            <button type="button" class="button" id="cancel-merge">
                                Cancel
                            </button>
                        </div>
                    </div>
                </div>
            `;
            $('body').append(modalHtml);
        }

        const $modal = $('#taxonomy-editor-merge-modal');
        const $termList = $modal.find('.term-list');

        // Clear existing content
        $termList.empty();

        // Add terms to the list
        terms.forEach(function(term) {
            $termList.append(`
                <div class="term-item" data-term-id="${term.id}">
                    <span class="term-name">${term.name}</span>
                    <span class="term-count">(${term.count} posts)</span>
                </div>
            `);
        });

        // Handle term selection
        $modal.find('.term-item').click(function() {
            $('.term-item').removeClass('selected');
            $(this).addClass('selected');
        });

        // Select first term by default
        $modal.find('.term-item:first').addClass('selected');

        // Show modal
        $modal.show();

        // Handle merge confirmation
        $('#confirm-merge').off('click').on('click', function() {
            const $selectedTerm = $('.term-item.selected');
            if (!$selectedTerm.length) {
                alert(taxonomyEditorData.strings.selectPrimary || 'Please select a primary term.');
                return;
            }

            const primaryTermId = $selectedTerm.data('term-id');
            const termIds = $('.term-item').map(function() {
                const termId = $(this).data('term-id');
                return termId !== primaryTermId ? termId : null;
            }).get().filter(Boolean);

            if (termIds.length === 0) {
                alert(taxonomyEditorData.strings.selectMultiple || 'Please select at least two terms to merge.');
                return;
            }

            const taxonomy = new URLSearchParams(window.location.search).get('taxonomy') || 'post_tag';
            const $button = $(this);

            // Disable button and show loading state
            $button.prop('disabled', true).text(taxonomyEditorData.strings.merging || 'Merging...');

            // Perform the merge
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'merge_terms',
                    nonce: taxonomyEditorData.nonce,
                    primary_term_id: primaryTermId,
                    term_ids: termIds,
                    taxonomy: taxonomy
                },
                success: function(response) {
                    if (response.success) {
                        window.location.reload();
                    } else {
                        alert(response.data || taxonomyEditorData.strings.error);
                        $button.prop('disabled', false).text('Merge Terms');
                    }
                },
                error: function() {
                    alert(taxonomyEditorData.strings.error);
                    $button.prop('disabled', false).text('Merge Terms');
                }
            });
        });

        // Handle cancel button
        $('#cancel-merge').off('click').on('click', function() {
            $modal.hide();
        });

        // Close modal when clicking outside
        $modal.off('click').on('click', function(e) {
            if ($(e.target).is('.taxonomy-editor-modal')) {
                $(this).hide();
            }
        });
    }

    // Function to show tag assignment modal
    function showTagAssignmentModal(postIds) {
        // Create modal if it doesn't exist
        if ($('#assign-tag-modal').length === 0) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bulk_edit_taxonomy',
                    nonce: taxonomyEditorData.nonce,
                    bulk_action: 'get_tags'
                },
                success: function(response) {
                    if (response.success && response.data.tags) {
                        const tags = response.data.tags;
                        const modalHtml = `
                            <div id="assign-tag-modal" class="tag-modal">
                                <div class="tag-modal-content">
                                    <h2>Assign Tags</h2>
                                    <p>Select tags to assign to the selected posts:</p>
                                    <select id="tags-to-assign" multiple="multiple" style="width: 100%;">
                                        ${tags.map(tag => `
                                            <option value="${tag.term_id}">${tag.name}</option>
                                        `).join('')}
                                    </select>
                                    <div class="tag-modal-buttons">
                                        <button type="button" class="button button-primary" id="confirm-assign-tags">
                                            Assign Tags
                                        </button>
                                        <button type="button" class="button" id="cancel-assign-tags">
                                            Cancel
                                        </button>
                                    </div>
                                    <input type="hidden" id="selected-post-ids" value="${postIds.join(',')}">
                                </div>
                            </div>
                        `;
                        $('body').append(modalHtml);
                        initializeTagModal('#assign-tag-modal');
                    } else {
                        alert('Error loading tags. Please try again.');
                    }
                },
                error: function() {
                    alert('Error loading tags. Please try again.');
                }
            });
        } else {
            $('#selected-post-ids').val(postIds.join(','));
            initializeTagModal('#assign-tag-modal');
        }
    }

    // Function to show tag unassignment modal
    function showTagUnassignmentModal(postIds) {
        // Create modal if it doesn't exist
        if ($('#unassign-tag-modal').length === 0) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'bulk_edit_taxonomy',
                    nonce: taxonomyEditorData.nonce,
                    post_ids: postIds,
                    bulk_action: 'get_post_tags'
                },
                success: function(response) {
                    if (response.success && response.data.tags) {
                        const tags = response.data.tags;
                        const modalHtml = `
                            <div id="unassign-tag-modal" class="tag-modal">
                                <div class="tag-modal-content">
                                    <h2>Unassign Tags</h2>
                                    <p>Select tags to remove from the selected posts:</p>
                                    <select id="tags-to-unassign" multiple="multiple" style="width: 100%;">
                                        ${tags.map(tag => `
                                            <option value="${tag.term_id}">${tag.name}</option>
                                        `).join('')}
                                    </select>
                                    <div class="tag-modal-buttons">
                                        <button type="button" class="button button-primary" id="confirm-unassign-tags">
                                            Unassign Tags
                                        </button>
                                        <button type="button" class="button" id="cancel-unassign-tags">
                                            Cancel
                                        </button>
                                    </div>
                                    <input type="hidden" id="selected-post-ids" value="${postIds.join(',')}">
                                </div>
                            </div>
                        `;
                        $('body').append(modalHtml);
                        initializeTagModal('#unassign-tag-modal');
                    } else {
                        alert('Error loading tags. Please try again.');
                    }
                },
                error: function() {
                    alert('Error loading tags. Please try again.');
                }
            });
        } else {
            $('#selected-post-ids').val(postIds.join(','));
            initializeTagModal('#unassign-tag-modal');
        }
    }

    // Function to initialize tag modal
    function initializeTagModal(modalSelector) {
        const $modal = $(modalSelector);
        const $select = $modal.find('select');

        // Initialize Select2
        $select.select2({
            width: '100%',
            placeholder: 'Select tags...',
            allowClear: true
        });

        // Show modal
        $modal.show();

        // Handle cancel button
        $modal.find('.button:not(.button-primary)').off('click').on('click', function() {
            $modal.hide();
            window.location.reload();
        });

        // Handle confirm button for assign tags
        $('#confirm-assign-tags').off('click').on('click', function() {
            handleTagAction(this, 'assign_tags');
        });

        // Handle confirm button for unassign tags
        $('#confirm-unassign-tags').off('click').on('click', function() {
            handleTagAction(this, 'unassign_tags');
        });
    }

    // Function to handle tag actions (assign/unassign)
    function handleTagAction(button, action) {
        const $button = $(button);
        const $modal = $button.closest('.tag-modal');
        const selectedTags = $modal.find('select').val();
        const postIds = $modal.find('#selected-post-ids').val().split(',');

        if (!selectedTags || selectedTags.length === 0) {
            alert('Please select at least one tag.');
            return;
        }

        $button.prop('disabled', true).text(action === 'assign_tags' ? 'Assigning...' : 'Unassigning...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'bulk_edit_taxonomy',
                nonce: taxonomyEditorData.nonce,
                post_ids: postIds,
                tag_ids: selectedTags,
                bulk_action: action
            },
            success: function(response) {
                if (response.success) {
                    window.location.reload();
                } else {
                    alert(response.data || 'An error occurred. Please try again.');
                    $button.prop('disabled', false).text(action === 'assign_tags' ? 'Assign Tags' : 'Unassign Tags');
                }
            },
            error: function() {
                alert('An error occurred. Please try again.');
                $button.prop('disabled', false).text(action === 'assign_tags' ? 'Assign Tags' : 'Unassign Tags');
            }
        });
    }

    // Close modal when clicking outside
    $(document).on('click', '.tag-modal', function(e) {
        if ($(e.target).is('.tag-modal')) {
            $(this).hide();
            window.location.reload();
        }
    });
}); 
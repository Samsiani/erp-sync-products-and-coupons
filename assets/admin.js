/**
 * ERP Sync Admin JavaScript - Version 1.5.0
 * Implements batch processing with retry logic for large datasets
 */

(function($) {
    'use strict';

    // Progress polling state
    let progressInterval = null;
    let syncInProgress = false;

    // Batch processing configuration
    const BATCH_SIZE = 50;
    const MAX_RETRIES = 5;
    const RETRY_DELAY_MS = 5000;

    // Tab Navigation
    function initTabs() {
        $('.erp-sync-nav-tabs .nav-tab').on('click', function(e) {
            e.preventDefault();
            
            const target = $(this).attr('href');
            
            // Update active tab
            $('.erp-sync-nav-tabs .nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            
            // Show target content
            $('.erp-sync-tab-content').hide();
            $(target).show();
            
            // Save active tab to localStorage
            localStorage.setItem('erp_sync_active_tab', target);
        });
        
        // Restore last active tab
        const lastTab = localStorage.getItem('erp_sync_active_tab');
        if (lastTab && $(lastTab).length) {
            $('.erp-sync-nav-tabs .nav-tab[href="' + lastTab + '"]').trigger('click');
        }
    }

    // Start Progress Bar Polling
    function startProgressPolling() {
        if (progressInterval) {
            return; // Already polling
        }
        
        $('#erp-sync-progress-container').show();
        $('.erp-sync-progress-fill').css('width', '0%');
        $('.erp-sync-progress-text').text('Initializing...');
        
        progressInterval = setInterval(checkProgress, 1000);
    }

    // Stop Progress Bar Polling
    function stopProgressPolling() {
        if (progressInterval) {
            clearInterval(progressInterval);
            progressInterval = null;
        }
        syncInProgress = false;
    }

    // Check Progress via AJAX
    function checkProgress() {
        $.ajax({
            url: erpSyncAdmin.ajaxurl,
            type: 'POST',
            data: {
                action: 'erp_sync_sync_progress',
                nonce: erpSyncAdmin.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    const data = response.data;
                    
                    if (data.status !== 'idle' && data.progress > 0) {
                        $('#erp-sync-progress-container').show();
                        $('.erp-sync-progress-fill').css('width', data.progress + '%');
                        $('.erp-sync-progress-text').text(data.status + ' (' + data.progress + '%)');
                    } else if (!syncInProgress) {
                        // Only hide if no sync is in progress (AJAX might complete before progress updates)
                        $('#erp-sync-progress-container').fadeOut();
                        stopProgressPolling();
                    }
                }
            }
        });
    }

    // Progress Bar Polling for legacy form submissions
    function initProgressPolling() {
        // Start polling when traditional sync form buttons are clicked (coupons sync)
        $('.erp-sync-action-buttons form').on('submit', function() {
            syncInProgress = true;
            startProgressPolling();
        });
    }

    // AJAX Sync Buttons Handler with Batch Processing
    function initAjaxSyncButtons() {
        $(document).on('click', '.erp-sync-ajax-btn', function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const action = $button.data('action');
            const originalText = $button.text();
            
            // Prevent double-clicks
            if ($button.prop('disabled')) {
                return;
            }
            
            // Store original width to prevent layout jump
            const originalWidth = $button.outerWidth();
            $button.css('min-width', originalWidth + 'px');
            
            // Disable button and show loading state
            $button.prop('disabled', true).addClass('updating-message');
            $button.html(originalText + ' <span class="erp-sync-loading"></span>');
            
            // Start progress polling
            syncInProgress = true;
            startProgressPolling();
            
            // Generate unique session ID for this sync
            const sessionId = 'sync_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            
            // Start the recursive batch sync process
            runSyncStep(action, 'init', 0, sessionId, 0, $button, originalText, {
                created: 0,
                updated: 0,
                skipped: 0,
                errors: 0,
                orphans_zeroed: 0
            });
        });
    }

    /**
     * Run a single sync step (init, process, or cleanup)
     * 
     * @param {string} action - The AJAX action (erp_sync_stock or erp_sync_catalog)
     * @param {string} step - Current step: 'init', 'process', or 'cleanup'
     * @param {number} offset - Current offset for batch processing
     * @param {string} sessionId - Unique session identifier
     * @param {number} retryCount - Number of retry attempts for current step
     * @param {jQuery} $button - The button element
     * @param {string} originalText - Original button text
     * @param {object} aggregateStats - Accumulated statistics
     */
    function runSyncStep(action, step, offset, sessionId, retryCount, $button, originalText, aggregateStats) {
        $.ajax({
            url: erpSyncAdmin.ajaxurl,
            type: 'POST',
            timeout: 60000, // 1 minute timeout per batch request
            data: {
                action: action,
                nonce: erpSyncAdmin.nonce,
                step: step,
                offset: offset,
                batch_size: BATCH_SIZE,
                session_id: sessionId
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    
                    if (step === 'init') {
                        // Init step completed - start processing batches
                        const totalCount = data.total || 0;
                        
                        if (totalCount === 0) {
                            // No items to process, go directly to cleanup
                            runSyncStep(action, 'cleanup', 0, sessionId, 0, $button, originalText, aggregateStats);
                        } else {
                            // Update progress bar
                            updateProgressUI(0, totalCount, 'Starting batch processing...');
                            
                            // Start processing first batch
                            runSyncStep(action, 'process', 0, sessionId, 0, $button, originalText, {
                                ...aggregateStats,
                                total: totalCount
                            });
                        }
                    } else if (step === 'process') {
                        // Accumulate stats from this batch
                        aggregateStats.created = (aggregateStats.created || 0) + (data.created || 0);
                        aggregateStats.updated = (aggregateStats.updated || 0) + (data.updated || 0);
                        aggregateStats.skipped = (aggregateStats.skipped || 0) + (data.skipped || 0);
                        aggregateStats.errors = (aggregateStats.errors || 0) + (data.errors || 0);
                        
                        const nextOffset = data.next_offset || (offset + BATCH_SIZE);
                        const totalCount = data.total || aggregateStats.total || 0;
                        const processed = data.processed || 0;
                        
                        // Update progress bar
                        const progressPercent = totalCount > 0 ? Math.round((nextOffset / totalCount) * 100) : 0;
                        updateProgressUI(Math.min(nextOffset, totalCount), totalCount, 
                            'Processing batch ' + Math.ceil(nextOffset / BATCH_SIZE) + '...');
                        
                        if (nextOffset >= totalCount) {
                            // All batches processed, run cleanup
                            runSyncStep(action, 'cleanup', 0, sessionId, 0, $button, originalText, {
                                ...aggregateStats,
                                total: totalCount
                            });
                        } else {
                            // Process next batch
                            runSyncStep(action, 'process', nextOffset, sessionId, 0, $button, originalText, {
                                ...aggregateStats,
                                total: totalCount
                            });
                        }
                    } else if (step === 'cleanup') {
                        // Cleanup completed - sync is done
                        aggregateStats.orphans_zeroed = data.orphans_zeroed || 0;
                        
                        handleSyncSuccess($button, originalText, aggregateStats);
                    }
                } else {
                    // Server returned error
                    handleSyncError($button, originalText, response.data?.message || 'Unknown error');
                }
            },
            error: function(xhr, status, error) {
                // AJAX error - try to retry
                if (retryCount < MAX_RETRIES) {
                    const newRetryCount = retryCount + 1;
                    updateProgressUI(offset, aggregateStats.total || 0, 
                        'Retrying... (attempt ' + newRetryCount + '/' + MAX_RETRIES + ')');
                    
                    // Wait before retrying
                    setTimeout(function() {
                        runSyncStep(action, step, offset, sessionId, newRetryCount, $button, originalText, aggregateStats);
                    }, RETRY_DELAY_MS);
                } else {
                    // Max retries reached
                    handleSyncError($button, originalText, 'Failed after ' + MAX_RETRIES + ' retries: ' + error);
                }
            }
        });
    }

    /**
     * Update the progress UI
     */
    function updateProgressUI(current, total, statusText) {
        const progressPercent = total > 0 ? Math.round((current / total) * 100) : 0;
        $('#erp-sync-progress-container').show();
        $('.erp-sync-progress-fill').css('width', progressPercent + '%');
        $('.erp-sync-progress-text').text(statusText + ' (' + progressPercent + '%)');
    }

    /**
     * Handle successful sync completion
     */
    function handleSyncSuccess($button, originalText, stats) {
        syncInProgress = false;
        
        // Show success state
        $button.removeClass('updating-message').addClass('button-primary');
        
        // Build result message
        let resultMsg = 'Completed! ✅';
        if (stats) {
            resultMsg = 'Done: ';
            if (stats.created) resultMsg += stats.created + ' created, ';
            if (stats.updated) resultMsg += stats.updated + ' updated, ';
            if (stats.skipped) resultMsg += stats.skipped + ' skipped, ';
            if (stats.errors) resultMsg += stats.errors + ' errors, ';
            if (stats.orphans_zeroed) resultMsg += stats.orphans_zeroed + ' orphans zeroed';
            resultMsg = resultMsg.replace(/, $/, '') + ' ✅';
        }
        
        $button.html(resultMsg);
        
        // Update progress bar to 100%
        $('.erp-sync-progress-fill').css('width', '100%');
        $('.erp-sync-progress-text').text('Completed!');
        
        // Reset after 3 seconds
        setTimeout(function() {
            $button.removeClass('button-primary').prop('disabled', false);
            $button.text(originalText);
            $button.css('min-width', '');
            $('#erp-sync-progress-container').fadeOut();
            stopProgressPolling();
        }, 3000);
    }

    /**
     * Handle sync error
     */
    function handleSyncError($button, originalText, errorMessage) {
        syncInProgress = false;
        
        // Show error - revert immediately
        $button.removeClass('updating-message');
        $button.prop('disabled', false);
        $button.text(originalText);
        $button.css('min-width', '');
        
        $('#erp-sync-progress-container').fadeOut();
        stopProgressPolling();
        
        alert('Error: ' + errorMessage);
    }

    // Quick Edit Functionality
    function initQuickEdit() {
        let originalValue = '';
        
        $(document).on('click', '.erp-sync-editable', function() {
            const $this = $(this);
            
            if ($this.hasClass('editing')) {
                return;
            }
            
            originalValue = $this.text().trim();
            const field = $this.data('field');
            const couponId = $this.data('coupon-id');
            
            let input;
            if (field === 'is_deleted') {
                input = $('<select><option value="no">No</option><option value="yes">Yes</option></select>');
                input.val(originalValue === 'Yes' ? 'yes' : 'no');
            } else if (field === 'base_discount') {
                input = $('<input type="number" min="0" max="100" />');
                input.val(parseInt(originalValue));
            } else if (field === 'dob') {
                input = $('<input type="date" />');
                input.val(originalValue);
            } else {
                return;
            }
            
            input.addClass('erp-sync-quick-edit-input');
            
            $this.addClass('editing').html(input);
            input.focus();
            
            // Save on blur or enter
            input.on('blur keypress', function(e) {
                if (e.type === 'keypress' && e.which !== 13) {
                    return;
                }
                
                e.preventDefault();
                const newValue = $(this).val();
                
                // Save via AJAX
                $.ajax({
                    url: erpSyncAdmin.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'erp_sync_quick_edit_coupon',
                        nonce: erpSyncAdmin.nonce,
                        coupon_id: couponId,
                        field: field,
                        value: newValue
                    },
                    success: function(response) {
                        if (response.success) {
                            let displayValue = newValue;
                            if (field === 'base_discount') {
                                displayValue = newValue + '%';
                            } else if (field === 'is_deleted') {
                                displayValue = newValue === 'yes' ? 'Yes' : 'No';
                            }
                            $this.removeClass('editing').text(displayValue);
                            
                            // Show success feedback
                            $this.css('background', '#d4edda');
                            setTimeout(function() {
                                $this.css('background', '');
                            }, 1000);
                        } else {
                            alert('Failed to update: ' + (response.data.message || 'Unknown error'));
                            $this.removeClass('editing').text(originalValue);
                        }
                    },
                    error: function() {
                        alert('Failed to update. Please try again.');
                        $this.removeClass('editing').text(originalValue);
                    }
                });
            });
            
            // Cancel on ESC
            input.on('keydown', function(e) {
                if (e.which === 27) {
                    $this.removeClass('editing').text(originalValue);
                }
            });
        });
    }

    // Confirm dangerous actions
    function initConfirmations() {
        $('input[type="submit"][class*="delete"]').on('click', function(e) {
            if (!$(this).data('confirmed')) {
                e.preventDefault();
                
                if (confirm('Are you sure? This action cannot be undone.')) {
                    $(this).data('confirmed', true);
                    $(this).trigger('click');
                }
            }
        });
    }

    // Single Product ERP Sync Update
    function initSingleProductUpdate() {
        $(document).on('click', '.erp-sync-single-update', function(e) {
            e.preventDefault();
            
            const $button = $(this);
            const productId = $button.data('id');
            const originalText = $button.text();
            
            // Store original width to prevent layout jump
            const originalWidth = $button.outerWidth();
            $button.css('min-width', originalWidth + 'px');
            
            // Disable button and show loading state with custom spinner
            $button.prop('disabled', true).addClass('updating-message');
            $button.html(originalText + ' <span class="erp-sync-loading"></span>');
            
            $.ajax({
                url: erpSyncAdmin.ajaxurl,
                type: 'POST',
                data: {
                    action: 'erp_sync_single_update',
                    nonce: erpSyncAdmin.nonce,
                    product_id: productId
                },
                success: function(response) {
                    if (response.success) {
                        // Show success state
                        $button.removeClass('updating-message').addClass('button-primary');
                        $button.html('Updated! ✅');
                        
                        // Reset after 2 seconds
                        setTimeout(function() {
                            $button.removeClass('button-primary').prop('disabled', false);
                            $button.text(originalText);
                            $button.css('min-width', '');
                        }, 2000);
                    } else {
                        // Show error - revert immediately
                        $button.removeClass('updating-message');
                        $button.prop('disabled', false);
                        $button.text(originalText);
                        $button.css('min-width', '');
                        alert('Error: ' + (response.data.message || 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    // Show error - revert immediately
                    $button.removeClass('updating-message');
                    $button.prop('disabled', false);
                    $button.text(originalText);
                    $button.css('min-width', '');
                    alert('AJAX Error: ' + error);
                }
            });
        });
    }

    // Initialize on document ready
    $(document).ready(function() {
        initTabs();
        initProgressPolling();
        initAjaxSyncButtons();
        initQuickEdit();
        initConfirmations();
        initSingleProductUpdate();
        
        console.log('ERP Sync Admin JS v1.5.0 loaded');
    });

})(jQuery);
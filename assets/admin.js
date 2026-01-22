/**
 * ERP Sync Admin JavaScript - Version 1.2.0
 */

(function($) {
    'use strict';

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

    // Progress Bar Polling
    function initProgressPolling() {
        let progressInterval = null;
        
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
                        } else {
                            $('#erp-sync-progress-container').fadeOut();
                            if (progressInterval) {
                                clearInterval(progressInterval);
                                progressInterval = null;
                            }
                        }
                    }
                }
            });
        }
        
        // Start polling when sync buttons are clicked
        $('.erp-sync-action-buttons form').on('submit', function() {
            $('#erp-sync-progress-container').show();
            $('.erp-sync-progress-fill').css('width', '0%');
            $('.erp-sync-progress-text').text('Initializing...');
            
            if (!progressInterval) {
                progressInterval = setInterval(checkProgress, 1000);
            }
        });
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

    // Initialize on document ready
    $(document).ready(function() {
        initTabs();
        initProgressPolling();
        initQuickEdit();
        initConfirmations();
        
        console.log('ERP Sync Admin JS v1.2.0 loaded');
    });

})(jQuery);
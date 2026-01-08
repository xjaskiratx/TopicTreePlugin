jQuery(document).ready(function ($) {
    console.log('Script loaded. jQuery version:', $.fn.jquery);

    //ANCHOR - Sidebar Toggle Functionality
    var $leftPanel = $('.left-panel');
    var $toggleBtn = $('#sidebar-toggle');
    var $closeBtn = $('#sidebar-close');
    var $mainContent = $('.main-content');

    if ($leftPanel.length === 0) console.error('Left panel (.left-panel) not found');
    if ($toggleBtn.length === 0) console.error('Toggle button (#sidebar-toggle) not found');
    if ($closeBtn.length === 0) console.error('Close button (#sidebar-close) not found');
    if ($mainContent.length === 0) console.error('Main content (.main-content) not found');

    $leftPanel.removeClass('collapsed');
    $mainContent.removeClass('full-width');
    $toggleBtn.hide();

    // Function to adjust content height
    function adjustContentHeight() {
        // Reset scroll position
        $mainContent.scrollTop(0);
        
        // Get the actual content height
        var contentHeight = $('.main-content > .section').outerHeight(true);
        var viewportHeight = $(window).height() - 32 - 30; // Subtract admin bar and footer
        
        // Set main content height to fit content but not exceed viewport
        if (contentHeight < viewportHeight) {
            $mainContent.css('height', 'auto');
            $mainContent.css('overflow-y', 'visible');
        } else {
            $mainContent.css('height', 'calc(100vh - 32px - 30px)');
            $mainContent.css('overflow-y', 'auto');
        }
    }

    $closeBtn.on('click', function () {
        console.log('Sidebar close button clicked');
        $leftPanel.addClass('collapsed');
        $mainContent.addClass('full-width');
        $toggleBtn.show();
        
        // Adjust content height after transition completes
        setTimeout(adjustContentHeight, 400); // Match transition duration
    });

    $toggleBtn.on('click', function () {
        console.log('Sidebar toggle button clicked');
        $leftPanel.removeClass('collapsed');
        $mainContent.removeClass('full-width');
        $toggleBtn.hide();
        
        // Adjust content height after transition completes
        setTimeout(adjustContentHeight, 400); // Match transition duration
    });

    // Initial height adjustment
    adjustContentHeight();
    
    // Adjust height on window resize
    $(window).on('resize', function() {
        adjustContentHeight();
    });

    // Group Index Click Handler
    $('.group-index-container a').on('click', function (e) {
        e.preventDefault();
        var targetId = $(this).attr('href'); // e.g., #group-some-category
        var $targetGroup = $(targetId);

        if ($targetGroup.length === 0) {
            console.error('Target group not found for ID:', targetId);
            return;
        }

        console.log('Group index link clicked, targeting:', targetId);

        // Scroll to the group within .main-content
        $('.main-content').animate({
            scrollTop: $targetGroup.position().top + $('.main-content').scrollTop()
        }, 500, function () {
            // Highlight the group card
            $targetGroup.addClass('highlight');
            setTimeout(function () {
                $targetGroup.removeClass('highlight');
            }, 1000); // Remove highlight after 1 second
        });
    });

    // Fetch currency data via AJAX
    $.ajax({
        url: topicTreeAjax.ajax_url,
        type: 'POST',
        data: {
            action: 'topic_tree_fetch_currency',
            nonce: topicTreeAjax.nonce
        },
        success: function (response) {
            if (response.success) {
                var rates = response.data.exchangeRates;
                var totalUsd = response.data.totalCostUsd;
                console.log('Currency data loaded:', rates, 'Total USD:', totalUsd);

                $('#total-cost-display').text(rates['USD'].symbol + totalUsd.toFixed(2));

                $('#currency-selector').on('change', function () {
                    var currency = $(this).val();
                    var rate = rates[currency].rate;
                    var symbol = rates[currency].symbol;
                    var converted = (totalUsd * rate).toFixed(2);
                    console.log('Currency changed to', currency, 'Converted value:', converted);
                    $('#total-cost-display').text(symbol + converted);
                });
            } else {
                console.error('Failed to fetch currency data:', response.data);
                $('#total-cost-display').text('N/A');
            }
        },
        error: function (xhr, status, error) {
            console.error('AJAX error fetching currency data:', status, error);
            $('#total-cost-display').text('N/A');
        }
    });

    // Individual Analyze button handler (Updated to ensure visibility)
    $(document).on('click', '.analyze-btn', function () {
        const $button = $(this);
        const $li = $button.closest('li');
        let $seoDiv = $li.find('.seo-suggestions-inline');
        const $tokenCount = $li.find('.token-count');
        const tokenCountText = $tokenCount.length ? $tokenCount.text() : '';
        const itemData = $button.data('item');
        console.log('Individual Analyze clicked, item:', itemData);
        console.log('SEO div found:', $seoDiv.length, 'Parent LI:', $li.length);
        console.log('Token count before update:', tokenCountText);

        if ($seoDiv.length === 0) {
            console.warn('No .seo-suggestions-inline found in this LI. Creating one.');
            $seoDiv = $('<div class="seo-suggestions-inline" style="display: none;"></div>');
            $li.append($seoDiv);
            console.log('Fallback div created:', $seoDiv.length);
        }

        if ($seoDiv.hasClass('visible')) {
            $seoDiv.removeClass('visible');
            $button.text('Analyze');
            // Adjust content height after closing
            setTimeout(adjustContentHeight, 400);
            return;
        }

        $seoDiv.html('<p>Analyzing content...</p>').addClass('visible');
        $button.text('Hide Analysis');
        
        // Adjust content height after opening
        setTimeout(adjustContentHeight, 400);

        $.ajax({
            url: topicTreeAjax.ajax_url,
            type: 'POST',
            data: {
                action: 'topic_tree_fetch_seo',
                nonce: topicTreeAjax.nonce,
                item: JSON.stringify(itemData)
            },
            success: function (response) {
                console.log('AJAX success:', response);
                if (response.success) {
                    let suggestions = response.data;
                    let html = '<ul>';
                    if (Array.isArray(suggestions) && suggestions.length > 0) {
                        $.each(suggestions, function (index, suggestion) {
                            html += '<li><div>' + suggestion.trim() + '</div></li>';
                        });
                    } else {
                        html = '<p>No suggestions available.</p>';
                    }
                    html += '</ul>';

                    $seoDiv.html(html).addClass('visible');
                    if ($tokenCount.length && tokenCountText) {
                        $tokenCount.text(tokenCountText);
                    }
                    console.log('SEO div updated. Content:', $seoDiv.html(), 'Visible class:', $seoDiv.hasClass('visible'));
                    console.log('Token count after update:', $tokenCount.text());
                    
                    // Adjust height after content loads
                    setTimeout(adjustContentHeight, 100);
                } else {
                    $seoDiv.html('<p>Error: ' + (response.data || 'Failed to generate suggestions') + '</p>').addClass('visible');
                    if ($tokenCount.length && tokenCountText) {
                        $tokenCount.text(tokenCountText);
                    }
                    console.log('SEO div error. Content:', $seoDiv.html());
                    
                    // Adjust height after error message displays
                    setTimeout(adjustContentHeight, 100);
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX error:', status, error);
                $seoDiv.html('<p>Error: Request failed. Please try again.</p>').addClass('visible');
                if ($tokenCount.length && tokenCountText) {
                    $tokenCount.text(tokenCountText);
                }
                
                // Adjust height after error message displays
                setTimeout(adjustContentHeight, 100);
            }
        });
    });

    // Group Analyze button handler
    $(document).on('click', '.group-analyze-btn', function (e) {
        e.preventDefault();
        console.log('Group Analyze button clicked');
        const $button = $(this);
        const $groupCard = $button.closest('.group-card');
        const $analysisDiv = $groupCard.find('.group-analysis');

        if ($groupCard.length === 0) console.error('Group card not found for button:', $button);
        if ($analysisDiv.length === 0) console.error('Analysis div not found in group card');

        $analysisDiv.toggleClass('visible');
        $button.text($analysisDiv.hasClass('visible') ? 'Hide Info' : 'Show Group Info');
        
        // Adjust content height after toggling analysis
        setTimeout(adjustContentHeight, 400);
    });

    // Group Toggle (Expand All/Collapse All) button handler
    $(document).on('click', '.toggle-group-btn', function (e) {
        e.preventDefault();
        console.log('Toggle Group button clicked');
        const $button = $(this);
        const toggleId = $button.data('toggle-id');
        const $groupContent = $('#' + toggleId);

        if ($groupContent.length === 0) console.error('Group content not found for toggle ID:', toggleId);

        $groupContent.toggleClass('visible');
        $button.text($groupContent.hasClass('visible') ? 'Collapse All' : 'Expand All');
        
        // Adjust content height after toggling group
        setTimeout(adjustContentHeight, 400);
    });

    // External link handler
    $(document).on('click', '.topic-tree a[target="_blank"]', function (e) {
        e.preventDefault();
        console.log('Link clicked:', $(this).attr('href'));
        window.open($(this).attr('href'), '_blank');
    });

    // Keyword Analysis button handler
    $('#analyze-keywords-btn').on('click', function () {
        console.log('Analyze Keywords button clicked');
        $('#keyword-analysis-output').slideDown(300);
        $(this).hide();
        
        // Adjust content height after expanding keyword analysis
        setTimeout(adjustContentHeight, 300);
    });

    // Keyword Analysis close button handler
    $('#keyword-analysis-close').on('click', function () {
        console.log('Keyword Analysis close button clicked');
        $('#keyword-analysis-output').slideUp(300, function () {
            $('#analyze-keywords-btn').show(); // Show the analyze button again after closing
            
            // Adjust content height after closing keyword analysis
            setTimeout(adjustContentHeight, 100);
        });
    });

    // Debugging button presence
    setTimeout(function () {
        const $groupButtons = $('.group-analyze-btn');
        const $toggleButtons = $('.toggle-group-btn');
        const $analyzeButtons = $('.analyze-btn');
        const $keywordButton = $('#analyze-keywords-btn');
        const $keywordCloseButton = $('#keyword-analysis-close');
        console.log('Group Analyze buttons found:', $groupButtons.length);
        console.log('Toggle Group buttons found:', $toggleButtons.length);
        console.log('Analyze buttons found:', $analyzeButtons.length);
        console.log('Keyword Analyze button found:', $keywordButton.length);
        console.log('Keyword Analysis close button found:', $keywordCloseButton.length);
        if ($groupButtons.length === 0) {
            console.warn('No .group-analyze-btn found after 2s. Check HTML output.');
        }
        if ($toggleButtons.length === 0) {
            console.warn('No .toggle-group-btn found after 2s. Check HTML output.');
        }
        if ($analyzeButtons.length === 0) {
            console.warn('No .analyze-btn found after 2s. Check HTML output.');
        }
        if ($keywordButton.length === 0) {
            console.warn('No #analyze-keywords-btn found after 2s. Check HTML output.');
        }
        if ($keywordCloseButton.length === 0) {
            console.warn('No #keyword-analysis-close found after 2s. Check HTML output.');
        }
    }, 2000);
});
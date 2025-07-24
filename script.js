document.addEventListener('DOMContentLoaded', function() {
    const sliders = document.querySelectorAll('.rns-brand-slider');
    
    sliders.forEach(function(slider) {
        const originalSlides = Array.from(slider.querySelectorAll('.rns-brand-slide'));
        
        if (originalSlides.length === 0) return;
        
        // Duplicate all slides to create seamless infinite loop
        originalSlides.forEach(function(slide) {
            const clone = slide.cloneNode(true);
            slider.appendChild(clone);
        });
    });
    
    // Update checker functionality for admin pages
    if (typeof ajaxurl !== 'undefined' && typeof rns_updater_vars !== 'undefined') {
        // Add manual update check button to plugin row
        const pluginRow = document.querySelector('[data-plugin="rns-brand-router/rns-brand-router.php"]');
        if (pluginRow) {
            const actionLinks = pluginRow.querySelector('.row-actions');
            if (actionLinks) {
                const checkUpdateLink = document.createElement('span');
                checkUpdateLink.innerHTML = ' | <a href="#" id="rns-check-update" class="rns-check-update">Check for Updates</a>';
                actionLinks.appendChild(checkUpdateLink);
                
                // Add click handler for manual update check
                document.getElementById('rns-check-update').addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const link = this;
                    const originalText = link.textContent;
                    link.textContent = 'Checking...';
                    link.style.pointerEvents = 'none';
                    
                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'rns_check_update',
                            nonce: rns_updater_vars.nonce
                        })
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok: ' + response.status);
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            const message = data.data && data.data.message ? data.data.message : 'Update check completed.';
                            alert(message);
                            // Refresh the page to show any new update notifications
                            if (data.data && data.data.new_version && data.data.new_version !== rns_updater_vars.current_version) {
                                location.reload();
                            }
                        } else {
                            const errorMessage = (data.data && data.data.message) ? data.data.message : 'Error checking for updates.';
                            alert(errorMessage);
                        }
                    })
                    .catch(error => {
                        console.error('Update check failed:', error);
                        alert('Error checking for updates: ' + (error.message || 'Unknown error occurred'));
                    })
                    .finally(() => {
                        link.textContent = originalText;
                        link.style.pointerEvents = 'auto';
                    });
                });
            }
        }
    }
});

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
});

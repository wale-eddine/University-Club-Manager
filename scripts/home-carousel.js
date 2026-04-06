/**
 * Auto-rotating carousel with arrow navigation, swipe support, and indicators
 */

// SECTION 1: CLUBS CAROUSEL (data-club-carousel)
(function () {
    // Get all club carousels
    const carousels = document.querySelectorAll('[data-club-carousel]');
    if (!carousels.length) return;

    carousels.forEach(function (carousel) {
        // Get carousel DOM elements
        const track = carousel.querySelector('.carousel-track');
        if (!track) return;
        
        const slides = Array.from(track.querySelectorAll('.carousel-slide'));
        const prevBtn = carousel.querySelector('.carousel-arrow-left');
        const nextBtn = carousel.querySelector('.carousel-arrow-right');

        if (!track || slides.length === 0 || !prevBtn || !nextBtn) return;

        // State and timing constants
        let index = 1;
        let isAnimating = false;
        const AUTO_DELAY_MS = 10000; // Auto-advance every 10 seconds
        let autoTimer = null;
        let autoFlashTimer = null;
        const ARROW_ANIM_MS = 380;

        // Animate arrow button on click with flash effect
        const animateArrow = function (button) {
            if (!button) return;
            if (button === nextBtn) nextBtn.classList.remove('carousel-arrow-auto-flash');
            button.style.setProperty('--arrow-click-duration', ARROW_ANIM_MS + 'ms');
            button.classList.remove('carousel-arrow-click-flash');
            button.offsetHeight; // Force reflow
            button.classList.add('carousel-arrow-click-flash');
        };

        // Remove animation class when animation ends
        const clearClickFlashClass = function (event) {
            event.currentTarget.classList.remove('carousel-arrow-click-flash');
        };
        prevBtn.addEventListener('animationend', clearClickFlashClass);
        nextBtn.addEventListener('animationend', clearClickFlashClass);

        // Toggle CSS transition on/off
        const setTransition = function (enabled) {
            track.style.transition = enabled ? 'transform 0.75s ease' : 'none';
        };

        // Move track to the current slide index.
        const update = function () {
            track.style.transform = 'translateX(-' + index * 100 + '%)';
        };

        // Equalize card and description heights across slides.
        const equalizeCarouselCards = function () {
            const cards = slides
                .map(function (slide) {
                    return slide.querySelector('.card');
                })
                .filter(function (card) {
                    return card !== null;
                });

            if (cards.length === 0) {
                return;
            }

            cards.forEach(function (card) {
                card.style.height = 'auto';
                const desc = card.querySelector('.card-description');
                if (desc) {
                    desc.style.minHeight = '0';
                }
            });

            const descriptions = cards
                .map(function (card) {
                    return card.querySelector('.card-description');
                })
                .filter(function (el) {
                    return el !== null;
                });

            if (descriptions.length > 0) {
                const maxDescriptionHeight = descriptions.reduce(function (max, el) {
                    return Math.max(max, el.offsetHeight);
                }, 0);

                descriptions.forEach(function (el) {
                    el.style.minHeight = maxDescriptionHeight + 'px';
                });
            }

            const maxCardHeight = cards.reduce(function (max, card) {
                return Math.max(max, card.offsetHeight);
            }, 0);

            cards.forEach(function (card) {
                card.style.height = maxCardHeight + 'px';
            });
        };

        equalizeCarouselCards();
        window.addEventListener('load', equalizeCarouselCards);
        window.addEventListener('resize', equalizeCarouselCards);
        setTimeout(equalizeCarouselCards, 120);

        // Create carousel indicators
        const indicatorsContainer = document.createElement('div');
        indicatorsContainer.className = 'carousel-indicators';
        
        slides.forEach(function (_, slideIndex) {
            const dot = document.createElement('button');
            dot.className = 'carousel-indicator-dot' + (slideIndex === 0 ? ' active' : '');
            dot.setAttribute('data-slide', slideIndex);
            dot.setAttribute('aria-label', 'Aller à la diapositive ' + (slideIndex + 1));
            dot.setAttribute('type', 'button');
            
            dot.addEventListener('click', function () {
                if (isAnimating) return;
                isAnimating = true;
                index = slideIndex + 1;
                update();
                updateIndicators(slideIndex);
                scheduleAutoAdvance();
            });
            
            indicatorsContainer.appendChild(dot);
        });

        carousel.appendChild(indicatorsContainer);
        
        // Activate the indicator matching the current slide.
        const updateIndicators = function (currentSlideIndex) {
            const dots = carousel.querySelectorAll('.carousel-indicator-dot');
            dots.forEach(function (dot) {
                dot.classList.remove('active');
            });
            if (dots[currentSlideIndex]) {
                dots[currentSlideIndex].classList.add('active');
            }
        };

        if (slides.length === 1) {
            prevBtn.disabled = true;
            nextBtn.disabled = true;
            return;
        }

        // Advance one slide if no animation is in progress.
        const runNext = function () {
            if (isAnimating) {
                return;
            }

            isAnimating = true;
            index += 1;
            update();
        };

        // Restart auto-advance and arrow cue timers.
        const scheduleAutoAdvance = function () {
            if (autoTimer) {
                clearTimeout(autoTimer);
            }

            if (autoFlashTimer) {
                clearTimeout(autoFlashTimer);
            }

            nextBtn.style.setProperty('--auto-advance-duration', AUTO_DELAY_MS + 'ms');
            nextBtn.classList.remove('carousel-arrow-auto-flash');

            autoFlashTimer = setTimeout(function () {
                nextBtn.offsetHeight;
                nextBtn.classList.add('carousel-arrow-auto-flash');
            }, ARROW_ANIM_MS + 20);

            autoTimer = setTimeout(function () {
                runNext();
                scheduleAutoAdvance();
            }, AUTO_DELAY_MS);
        };

        const firstClone = slides[0].cloneNode(true);
        const lastClone = slides[slides.length - 1].cloneNode(true);
        track.appendChild(firstClone);
        track.insertBefore(lastClone, slides[0]);

        setTransition(false);
        update();
        requestAnimationFrame(function () {
            setTransition(true);
        });

        // Navigate to the previous slide and refresh timers.
        const goPrev = function () {
            if (isAnimating) {
                return;
            }

            animateArrow(prevBtn);
            isAnimating = true;
            index -= 1;
            update();
            scheduleAutoAdvance();
        };

        // Navigate to the next slide and refresh timers.
        const goNext = function () {
            animateArrow(nextBtn);
            runNext();
            scheduleAutoAdvance();
        };

        prevBtn.addEventListener('click', goPrev);
        nextBtn.addEventListener('click', goNext);

        let touchStartX = 0;
        let touchStartY = 0;

        // Capture initial touch position for swipe handling.
        carousel.addEventListener('touchstart', function (event) {
            const touch = event.touches && event.touches[0];
            if (!touch) {
                return;
            }
            touchStartX = touch.clientX;
            touchStartY = touch.clientY;
        }, { passive: true });

        // Trigger next/previous navigation on horizontal swipe.
        carousel.addEventListener('touchend', function (event) {
            const touch = event.changedTouches && event.changedTouches[0];
            if (!touch) {
                return;
            }

            const deltaX = touch.clientX - touchStartX;
            const deltaY = touch.clientY - touchStartY;
            const absX = Math.abs(deltaX);
            const absY = Math.abs(deltaY);

            if (absX < 45 || absX <= absY) {
                return;
            }

            if (deltaX < 0) {
                goNext();
            } else {
                goPrev();
            }
        }, { passive: true });

        // Handle seamless looping when reaching cloned slides.
        track.addEventListener('transitionend', function () {
            const total = slides.length;

            if (index === total + 1) {
                setTransition(false);
                index = 1;
                update();
                track.offsetHeight;
                setTransition(true);
                updateIndicators(0);
            } else if (index === 0) {
                setTransition(false);
                index = total;
                update();
                track.offsetHeight;
                setTransition(true);
                updateIndicators(total - 1);
            } else {
                updateIndicators(index - 1);
            }

            isAnimating = false;
        });

        scheduleAutoAdvance();
    });
})();

// SECTION 2: EVENTS CAROUSEL (data-events-carousel)
(function () {
    // Find all events carousels on the page.
    const carousels = document.querySelectorAll('[data-events-carousel]');

    if (!carousels.length) {
        return;
    }

    carousels.forEach(function (carousel) {
        const track = carousel.querySelector('.carousel-track');
        if (!track) {
            return;
        }
        const slides = Array.from(track.querySelectorAll('.carousel-slide'));
        const prevBtn = carousel.querySelector('.carousel-arrow-left');
        const nextBtn = carousel.querySelector('.carousel-arrow-right');

        if (!track || slides.length === 0 || !prevBtn || !nextBtn) {
            return;
        }

        let index = 0;
        let isAnimating = false;
        const ARROW_ANIM_MS = 380;

        // Animate clicked arrow for quick visual feedback.
        const animateArrow = function (button) {
            if (!button) {
                return;
            }

            button.style.setProperty('--arrow-click-duration', ARROW_ANIM_MS + 'ms');
            button.classList.remove('carousel-arrow-click-flash');
            button.offsetHeight;
            button.classList.add('carousel-arrow-click-flash');
        };

        // Return visible cards count based on current breakpoint.
        const getVisibleCount = function () {
            if (window.innerWidth <= 768) {
                return 1;
            }
            if (window.innerWidth <= 992) {
                return 2;
            }
            return 3;
        };

        // Apply translate offset for the current carousel index.
        const update = function () {
            const visibleCount = getVisibleCount();
            const step = 100 / visibleCount;
            track.style.transform = 'translateX(-' + index * step + '%)';
        };

        // Keep event cards at equal height for a stable layout.
        const equalizeCarouselCards = function () {
            const cards = slides
                .map(function (slide) {
                    return slide.querySelector('.card');
                })
                .filter(function (card) {
                    return card !== null;
                });

            if (cards.length === 0) {
                return;
            }

            cards.forEach(function (card) {
                card.style.height = 'auto';
            });

            const maxCardHeight = cards.reduce(function (max, card) {
                return Math.max(max, card.offsetHeight);
            }, 0);

            cards.forEach(function (card) {
                card.style.height = maxCardHeight + 'px';
            });
        };

        equalizeCarouselCards();
        window.addEventListener('load', equalizeCarouselCards);
        window.addEventListener('resize', equalizeCarouselCards);
        setTimeout(equalizeCarouselCards, 120);

        // Compute last valid page index for current viewport.
        const getMaxIndex = function () {
            return Math.max(0, slides.length - getVisibleCount());
        };

        // Create carousel indicators for events carousel
        const indicatorsContainer = document.createElement('div');
        indicatorsContainer.className = 'carousel-indicators';
        
        const maxPages = getMaxIndex() + 1;
        for (let i = 0; i < maxPages; i++) {
            const dot = document.createElement('button');
            dot.className = 'carousel-indicator-dot' + (i === 0 ? ' active' : '');
            dot.setAttribute('data-slide', i);
            dot.setAttribute('aria-label', 'Aller à la page ' + (i + 1));
            dot.setAttribute('type', 'button');
            
            dot.addEventListener('click', function () {
                if (isAnimating) return;
                isAnimating = true;
                index = i;
                update();
                updateIndicators(i);
                setTimeout(function () {
                    isAnimating = false;
                }, 760);
            });
            
            indicatorsContainer.appendChild(dot);
        }

        carousel.appendChild(indicatorsContainer);
        
        // Highlight the active pagination indicator.
        const updateIndicators = function (currentPageIndex) {
            const dots = carousel.querySelectorAll('.carousel-indicator-dot');
            dots.forEach(function (dot) {
                dot.classList.remove('active');
            });
            if (dots[currentPageIndex]) {
                dots[currentPageIndex].classList.add('active');
            }
        };

        if (slides.length <= getVisibleCount()) {
            prevBtn.disabled = true;
            nextBtn.disabled = true;
            update();
            return;
        }

        update();

        // Move carousel to previous page with wrap-around.
        const goPrev = function () {
            if (isAnimating) {
                return;
            }

            animateArrow(prevBtn);
            isAnimating = true;
            const maxIndex = getMaxIndex();
            index = index <= 0 ? maxIndex : index - 1;
            update();
            updateIndicators(index);
            setTimeout(function () {
                isAnimating = false;
            }, 760);
        };

        // Move carousel to next page with wrap-around.
        const goNext = function () {
            if (isAnimating) {
                return;
            }

            animateArrow(nextBtn);
            isAnimating = true;
            const maxIndex = getMaxIndex();
            index = index >= maxIndex ? 0 : index + 1;
            update();
            updateIndicators(index);
            setTimeout(function () {
                isAnimating = false;
            }, 760);
        };

        prevBtn.addEventListener('click', goPrev);
        nextBtn.addEventListener('click', goNext);

        let touchStartX = 0;
        let touchStartY = 0;

        // Store touch start coordinates for swipe detection.
        carousel.addEventListener('touchstart', function (event) {
            const touch = event.touches && event.touches[0];
            if (!touch) {
                return;
            }
            touchStartX = touch.clientX;
            touchStartY = touch.clientY;
        }, { passive: true });

        // Trigger navigation when a horizontal swipe is detected.
        carousel.addEventListener('touchend', function (event) {
            const touch = event.changedTouches && event.changedTouches[0];
            if (!touch) {
                return;
            }

            const deltaX = touch.clientX - touchStartX;
            const deltaY = touch.clientY - touchStartY;
            const absX = Math.abs(deltaX);
            const absY = Math.abs(deltaY);

            if (absX < 45 || absX <= absY) {
                return;
            }

            if (deltaX < 0) {
                goNext();
            } else {
                goPrev();
            }
        }, { passive: true });

        // Recalculate pages and controls when viewport size changes.
        window.addEventListener('resize', function () {
            const maxIndex = getMaxIndex();
            if (index > maxIndex) {
                index = maxIndex;
            }
            prevBtn.disabled = slides.length <= getVisibleCount();
            nextBtn.disabled = slides.length <= getVisibleCount();
            
            // Recreate indicators if page count changed
            const newMaxPages = getMaxIndex() + 1;
            const currentDots = carousel.querySelectorAll('.carousel-indicator-dot');
            if (currentDots.length !== newMaxPages) {
                indicatorsContainer.innerHTML = '';
                for (let i = 0; i < newMaxPages; i++) {
                    const dot = document.createElement('button');
                    dot.className = 'carousel-indicator-dot' + (i === index ? ' active' : '');
                    dot.setAttribute('data-slide', i);
                    dot.setAttribute('aria-label', 'Aller à la page ' + (i + 1));
                    dot.setAttribute('type', 'button');
                    
                    dot.addEventListener('click', function () {
                        if (isAnimating) return;
                        isAnimating = true;
                        index = i;
                        update();
                        updateIndicators(i);
                        setTimeout(function () {
                            isAnimating = false;
                        }, 760);
                    });
                    
                    indicatorsContainer.appendChild(dot);
                }
                if (newMaxPages === 1) {
                    indicatorsContainer.style.display = 'none';
                } else {
                    indicatorsContainer.style.display = 'flex';
                }
            }
            updateIndicators(index);
            update();
        });
    });
})();

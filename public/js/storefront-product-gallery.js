(function () {
    'use strict';

    // Runtime lightbox diagnostics: header selector is `.sf-storefront-header`,
    // lightbox selector is `[data-gallery-lightbox]`, and the inline fallback
    // removes the header from layout independently from z-index stacking.
    function hideStorefrontHeaderForLightbox() {
        var header = document.querySelector('.sf-storefront-header');

        if (!header) {
            return;
        }

        if (!Object.prototype.hasOwnProperty.call(header.dataset, 'lightboxPreviousDisplay')) {
            header.dataset.lightboxPreviousDisplay = header.style.display || '';
        }

        if (!Object.prototype.hasOwnProperty.call(header.dataset, 'lightboxPreviousPointerEvents')) {
            header.dataset.lightboxPreviousPointerEvents = header.style.pointerEvents || '';
        }

        header.style.display = 'none';
        header.style.pointerEvents = 'none';
    }

    function restoreStorefrontHeaderAfterLightbox() {
        var header = document.querySelector('.sf-storefront-header');

        if (!header) {
            return;
        }

        header.style.display = header.dataset.lightboxPreviousDisplay || '';
        header.style.pointerEvents = header.dataset.lightboxPreviousPointerEvents || '';
        delete header.dataset.lightboxPreviousDisplay;
        delete header.dataset.lightboxPreviousPointerEvents;
    }

    function initGallery(gallery) {
        var mainImage = gallery.querySelector('[data-gallery-main]');
        var openButton = gallery.querySelector('[data-gallery-open]');
        var thumbs = Array.prototype.slice.call(gallery.querySelectorAll('[data-gallery-thumb]'));
        var lightbox = gallery.querySelector('[data-gallery-lightbox]');
        var lightboxImage = gallery.querySelector('[data-gallery-lightbox-image]');
        var prevButtons = Array.prototype.slice.call(gallery.querySelectorAll('[data-gallery-prev], [data-gallery-main-prev]'));
        var nextButtons = Array.prototype.slice.call(gallery.querySelectorAll('[data-gallery-next], [data-gallery-main-next]'));
        var closeButtons = Array.prototype.slice.call(gallery.querySelectorAll('[data-gallery-close]'));
        var thumbsTrack = gallery.querySelector('[data-gallery-thumbs-track]');
        var thumbsPrevButton = gallery.querySelector('[data-gallery-thumbs-prev]');
        var thumbsNextButton = gallery.querySelector('[data-gallery-thumbs-next]');
        var currentIndex = 0;

        if (!mainImage || !openButton || !lightbox || !lightboxImage) {
            return;
        }

        lightbox.classList.add('sf-lightbox');

        var images = thumbs.length ? thumbs.map(function (thumb) {
            return {
                product: thumb.dataset.productSrc,
                alt: thumb.dataset.alt || ''
            };
        }) : [{ product: mainImage.getAttribute('src'), alt: mainImage.getAttribute('alt') || '' }];

        function setActive(index) {
            if (!images[index]) {
                return;
            }

            currentIndex = index;
            mainImage.src = images[index].product;
            mainImage.alt = images[index].alt;
            lightboxImage.src = images[index].product;
            lightboxImage.alt = images[index].alt;

            thumbs.forEach(function (thumb, thumbIndex) {
                var isActive = thumbIndex === index;
                thumb.classList.toggle('is-active', isActive);
                thumb.setAttribute('aria-current', isActive ? 'true' : 'false');

                if (isActive && typeof thumb.scrollIntoView === 'function') {
                    thumb.scrollIntoView({ block: 'nearest', inline: 'nearest' });
                }
            });
        }

        function showOffset(offset) {
            if (images.length < 2) {
                return;
            }

            setActive((currentIndex + offset + images.length) % images.length);
        }

        function scrollThumbs(offset) {
            if (!thumbsTrack) {
                return;
            }

            var firstThumb = thumbs[0];
            var step = firstThumb ? firstThumb.getBoundingClientRect().height + 10 : 82;
            thumbsTrack.scrollBy({ top: offset * step, left: 0, behavior: 'smooth' });
        }

        function openLightbox() {
            setActive(currentIndex);
            lightbox.hidden = false;
            lightbox.setAttribute('aria-hidden', 'false');
            document.body.classList.add('sf-lightbox-open');
            hideStorefrontHeaderForLightbox();
            var closeButton = lightbox.querySelector('.sf-lightbox__close');
            if (closeButton) {
                closeButton.focus({ preventScroll: true });
            }
        }

        function closeLightbox() {
            lightbox.hidden = true;
            lightbox.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('sf-lightbox-open');
            restoreStorefrontHeaderAfterLightbox();
            openButton.focus({ preventScroll: true });
        }

        if (lightbox.hidden) {
            document.body.classList.remove('sf-lightbox-open');
            restoreStorefrontHeaderAfterLightbox();
        }

        thumbs.forEach(function (thumb, index) {
            thumb.addEventListener('click', function () {
                setActive(index);
            });
        });

        if (thumbsPrevButton) {
            thumbsPrevButton.addEventListener('click', function () {
                scrollThumbs(-1);
            });
        }

        if (thumbsNextButton) {
            thumbsNextButton.addEventListener('click', function () {
                scrollThumbs(1);
            });
        }

        openButton.addEventListener('click', openLightbox);
        closeButtons.forEach(function (button) {
            button.addEventListener('click', closeLightbox);
        });

        prevButtons.forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                showOffset(-1);
            });
        });

        nextButtons.forEach(function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                showOffset(1);
            });
        });

        document.addEventListener('keydown', function (event) {
            if (lightbox.hidden) {
                return;
            }

            if (event.key === 'Escape') {
                closeLightbox();
            } else if (event.key === 'ArrowLeft') {
                showOffset(-1);
            } else if (event.key === 'ArrowRight') {
                showOffset(1);
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-product-gallery]').forEach(initGallery);
    });
}());

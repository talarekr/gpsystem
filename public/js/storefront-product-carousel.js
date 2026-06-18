document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-product-carousel]').forEach((carousel) => {
        const track = carousel.querySelector('[data-carousel-track]');
        const previous = carousel.querySelector('[data-carousel-prev]');
        const next = carousel.querySelector('[data-carousel-next]');
        const pagination = carousel.querySelector('[data-carousel-pagination]');
        const controls = carousel.querySelector('.sf-product-carousel__controls');

        if (!track || !previous || !next || !pagination) {
            return;
        }

        let dots = [];
        let pageCount = 0;
        let scrollTimer;

        const getPageCount = () => Math.max(1, Math.ceil(track.scrollWidth / track.clientWidth));
        const getCurrentPage = () => Math.min(pageCount - 1, Math.round(track.scrollLeft / track.clientWidth));

        const scrollToPage = (page) => {
            const targetPage = Math.max(0, Math.min(pageCount - 1, page));

            track.scrollTo({
                left: targetPage * track.clientWidth,
                behavior: 'smooth',
            });
        };

        const updateActiveDot = () => {
            const currentPage = getCurrentPage();

            previous.disabled = currentPage <= 0;
            next.disabled = currentPage >= pageCount - 1;

            dots.forEach((dot, index) => {
                const isActive = index === currentPage;

                dot.classList.toggle('is-active', isActive);
                dot.setAttribute('aria-current', isActive ? 'true' : 'false');
            });
        };

        const buildPagination = () => {
            pageCount = getPageCount();
            pagination.innerHTML = '';
            dots = [];

            if (pageCount <= 1) {
                pagination.hidden = true;

                if (controls) {
                    controls.hidden = true;
                }

                return;
            }

            pagination.hidden = false;

            if (controls) {
                controls.hidden = false;
            }

            for (let index = 0; index < pageCount; index += 1) {
                const dot = document.createElement('button');
                dot.className = 'sf-product-carousel__dot';
                dot.type = 'button';
                dot.setAttribute('aria-label', `Przewiń do strony ${index + 1}`);
                dot.addEventListener('click', () => scrollToPage(index));
                pagination.appendChild(dot);
                dots.push(dot);
            }

            updateActiveDot();
        };

        const scrollByPage = (direction) => {
            scrollToPage(getCurrentPage() + direction);
        };

        previous.addEventListener('click', () => scrollByPage(-1));
        next.addEventListener('click', () => scrollByPage(1));
        track.addEventListener('scroll', () => {
            window.clearTimeout(scrollTimer);
            scrollTimer = window.setTimeout(updateActiveDot, 80);
        }, { passive: true });
        window.addEventListener('resize', buildPagination);

        buildPagination();
    });
});

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-product-carousel]').forEach((carousel) => {
        const track = carousel.querySelector('[data-carousel-track]');
        const previous = carousel.querySelector('[data-carousel-prev]');
        const next = carousel.querySelector('[data-carousel-next]');

        if (!track || !previous || !next) {
            return;
        }

        const scrollByPage = (direction) => {
            track.scrollBy({
                left: direction * track.clientWidth,
                behavior: 'smooth',
            });
        };

        previous.addEventListener('click', () => scrollByPage(-1));
        next.addEventListener('click', () => scrollByPage(1));
    });
});

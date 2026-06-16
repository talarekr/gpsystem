(function () {
    const storageKey = 'gps_shop_event_sound_enabled';

    function playTestBeep() {
        const AudioContext = window.AudioContext || window.webkitAudioContext;

        if (!AudioContext) {
            return;
        }

        const context = new AudioContext();
        const oscillator = context.createOscillator();
        const gain = context.createGain();

        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(880, context.currentTime);
        gain.gain.setValueAtTime(0.0001, context.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.12, context.currentTime + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.16);

        oscillator.connect(gain);
        gain.connect(context.destination);
        oscillator.start();
        oscillator.stop(context.currentTime + 0.18);
        oscillator.addEventListener('ended', function () {
            context.close();
        });
    }

    function initShopEventSoundToggle() {
        document.querySelectorAll('[data-gps-shop-event-sound-toggle]').forEach(function (toggle) {
            toggle.checked = localStorage.getItem(storageKey) === '1';

            toggle.addEventListener('change', function () {
                localStorage.setItem(storageKey, toggle.checked ? '1' : '0');

                if (toggle.checked) {
                    playTestBeep();
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initShopEventSoundToggle);
    } else {
        initShopEventSoundToggle();
    }
})();

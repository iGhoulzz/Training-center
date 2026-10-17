/*
 * The hero seal turns like a coin under the pointer (owner, 2026-10-17).
 *
 * Across the seal, the pointer's horizontal position sets the turn: the left edge
 * is -180 degrees, the centre is face-on and the right edge is +180 degrees, so one
 * pass across it is a full circle. Its vertical position tilts the seal slightly.
 * When the pointer leaves, the seal eases back to face-on. The motion itself is
 * CSS (public.css, "THE HERO SEAL"); this only writes two custom properties.
 *
 * The public site's only script. It reads nothing but the pointer, stores nothing
 * and sends nothing. It does nothing on touch, where a finger is scrolling the
 * page, and nothing when the visitor has asked for reduced motion.
 */

const MAX_TILT_DEGREES = 12;

const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

const clamp = (value) => Math.min(Math.max(value, 0), 1);

for (const stage of document.querySelectorAll('[data-seal-coin]')) {
    const coin = stage.querySelector('[data-seal-coin-body]');

    if (coin === null) {
        continue;
    }

    let frame = 0;

    const turnTo = (turn, tilt) => {
        coin.style.setProperty('--seal-turn', `${turn}deg`);
        coin.style.setProperty('--seal-tilt', `${tilt}deg`);
    };

    stage.addEventListener('pointermove', (event) => {
        if (event.pointerType === 'touch' || reducedMotion.matches) {
            return;
        }

        const box = stage.getBoundingClientRect();
        const across = clamp((event.clientX - box.x) / box.width);
        const down = clamp((event.clientY - box.y) / box.height);

        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(() => {
            stage.classList.add('is-turning');
            turnTo((across - 0.5) * 360, (0.5 - down) * 2 * MAX_TILT_DEGREES);
        });
    });

    stage.addEventListener('pointerleave', () => {
        cancelAnimationFrame(frame);
        stage.classList.remove('is-turning');
        turnTo(0, 0);
    });
}

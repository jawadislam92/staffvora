import { useEffect, useRef } from 'react';

/**
 * A lightweight moving constellation / particle network for the hero background
 * (the "interactive, alive" feel, like sparkingasia.com). Pure canvas, no library.
 * Colours follow the active theme via the --a / --g-from CSS vars, so it adapts
 * to the palette and day/night. Honours prefers-reduced-motion (renders static).
 */
export default function HeroParticles() {
    const ref = useRef(null);

    useEffect(() => {
        const canvas = ref.current;
        if (!canvas) return undefined;
        const ctx = canvas.getContext('2d');
        const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        let w = 0, h = 0, pts = [], raf = 0, frame = 0;
        let col = { a: '#7C3AED', g: '#22D3EE' };

        const readColor = () => {
            const cs = getComputedStyle(canvas);
            col = {
                a: (cs.getPropertyValue('--a') || '#7C3AED').trim(),
                g: (cs.getPropertyValue('--g-from') || '#22D3EE').trim(),
            };
        };

        const resize = () => {
            const r = canvas.getBoundingClientRect();
            w = r.width; h = r.height;
            canvas.width = Math.max(1, Math.floor(w * dpr));
            canvas.height = Math.max(1, Math.floor(h * dpr));
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
            const count = Math.max(18, Math.min(70, Math.floor(w / 20)));
            pts = Array.from({ length: count }, () => ({
                x: Math.random() * w, y: Math.random() * h,
                vx: (Math.random() - 0.5) * 0.25, vy: (Math.random() - 0.5) * 0.25,
            }));
            readColor();
        };

        const step = (move) => {
            ctx.clearRect(0, 0, w, h);
            if (move) {
                for (const p of pts) {
                    p.x += p.vx; p.y += p.vy;
                    if (p.x < 0 || p.x > w) p.vx *= -1;
                    if (p.y < 0 || p.y > h) p.vy *= -1;
                }
            }
            for (let i = 0; i < pts.length; i++) {
                for (let j = i + 1; j < pts.length; j++) {
                    const dx = pts[i].x - pts[j].x, dy = pts[i].y - pts[j].y;
                    const d = Math.hypot(dx, dy);
                    if (d < 130) {
                        ctx.globalAlpha = (1 - d / 130) * 0.16;
                        ctx.strokeStyle = col.g;
                        ctx.lineWidth = 1;
                        ctx.beginPath();
                        ctx.moveTo(pts[i].x, pts[i].y);
                        ctx.lineTo(pts[j].x, pts[j].y);
                        ctx.stroke();
                    }
                }
            }
            ctx.globalAlpha = 0.45;
            ctx.fillStyle = col.a;
            for (const p of pts) {
                ctx.beginPath();
                ctx.arc(p.x, p.y, 1.7, 0, Math.PI * 2);
                ctx.fill();
            }
            ctx.globalAlpha = 1;
        };

        const loop = () => {
            frame++;
            if (frame % 45 === 0) readColor();
            step(true);
            raf = requestAnimationFrame(loop);
        };

        resize();
        window.addEventListener('resize', resize);
        if (reduce) step(false);
        else raf = requestAnimationFrame(loop);

        return () => {
            cancelAnimationFrame(raf);
            window.removeEventListener('resize', resize);
        };
    }, []);

    return <canvas ref={ref} aria-hidden="true" className="absolute inset-0 h-full w-full" style={{ pointerEvents: 'none' }} />;
}

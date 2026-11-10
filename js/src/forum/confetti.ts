/**
 * A burst of paper from an element: 48 spans, pure CSS animation, gone
 * after two seconds. Skipped for readers who ask for less motion.
 */
const COLORS = ['#f43f5e', '#f59e0b', '#10b981', '#3b82f6', '#a855f7', '#ec4899'];

export function confetti(from?: Element | null): void {
  if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;
  const box = from?.getBoundingClientRect();
  const x = box ? box.left + box.width / 2 : window.innerWidth / 2;
  const y = box ? box.top + box.height / 2 : window.innerHeight / 3;

  const layer = document.createElement('div');
  layer.className = 'BingoConfetti';
  layer.setAttribute('aria-hidden', 'true');
  for (let i = 0; i < 48; i++) {
    const piece = document.createElement('span');
    const angle = Math.random() * Math.PI * 2;
    const power = 90 + Math.random() * 170;
    piece.style.left = `${x}px`;
    piece.style.top = `${y}px`;
    piece.style.background = COLORS[i % COLORS.length];
    piece.style.setProperty('--dx', `${Math.cos(angle) * power}px`);
    piece.style.setProperty('--dy', `${Math.sin(angle) * power - 120}px`);
    piece.style.setProperty('--r', `${Math.random() * 720 - 360}deg`);
    piece.style.animationDelay = `${Math.random() * 120}ms`;
    layer.appendChild(piece);
  }
  document.body.appendChild(layer);
  window.setTimeout(() => layer.remove(), 2200);
}

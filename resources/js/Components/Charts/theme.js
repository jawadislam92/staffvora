import React from "react";

/**
 * Shared theme plumbing for the chart kit.
 *
 * Staffvora themes switch via `data-theme` on <html> (light / dark / black).
 * Chart.js canvases rasterize colors at draw time, so components that want
 * theme-reactive paint call `useThemeName()` — it re-renders the chart when
 * the attribute flips (same MutationObserver pattern the date-picker pages
 * use, e.g. Pages/WorkHoursReport.jsx).
 */

/** Current theme name off <html data-theme>; defaults to "dark" (the :root theme). */
export function readThemeName() {
  if (typeof document === "undefined") return "dark";
  return document.documentElement.getAttribute("data-theme") || "dark";
}

/** Glow is a dark/black-only treatment — light theme stays crisp (no halo). */
export function themeAllowsGlow(theme = readThemeName()) {
  return theme !== "light";
}

/** React hook: the live theme name, re-rendering on `data-theme` changes. */
export function useThemeName() {
  const [theme, setTheme] = React.useState(readThemeName);
  React.useEffect(() => {
    if (typeof document === "undefined") return undefined;
    const root = document.documentElement;
    const read = () => setTheme(root.getAttribute("data-theme") || "dark");
    read();
    const observer = new MutationObserver(read);
    observer.observe(root, { attributes: true, attributeFilter: ["data-theme"] });
    return () => observer.disconnect();
  }, []);
  return theme;
}

/** Read a CSS custom property's computed value off <html> (e.g. "--viz-1"). */
export function cssVar(name, fallback = "") {
  if (typeof document === "undefined") return fallback;
  const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  return v || fallback;
}

/** Resolved hex/rgb string for a categorical viz slot (1..5). */
export function vizColor(slot) {
  const n = Math.min(5, Math.max(1, Number(slot) || 1));
  return cssVar(`--viz-${n}`, "#2563EB");
}

/** The resolved --viz slot colors, in fixed slot order (never cycled). */
export function vizPalette() {
  return [1, 2, 3, 4, 5].map(vizColor);
}

/** Text tokens for on-canvas text (Chart.js can't consume var() strings). */
export function textTokens() {
  return {
    body: cssVar("--text", "#eef2fb"),
    muted: cssVar("--text-muted", "#aeb9d0"),
    subtle: cssVar("--text-subtle", "#6b7793"),
  };
}

/** "#rrggbb" (or rgb()) → "rgba(r,g,b,a)". Non-hex inputs pass through color-mix-free. */
export function withAlpha(color, alpha) {
  const hex = /^#([0-9a-f]{6})$/i.exec((color || "").trim());
  if (hex) {
    const int = parseInt(hex[1], 16);
    const r = (int >> 16) & 255;
    const g = (int >> 8) & 255;
    const b = int & 255;
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }
  const rgb = /^rgba?\(([^)]+)\)$/i.exec((color || "").trim());
  if (rgb) {
    const [r, g, b] = rgb[1].split(",").map((s) => s.trim());
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
  }
  return color;
}

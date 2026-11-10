"use client";

import { useSyncExternalStore } from "react";

const QUERY = "(prefers-reduced-motion:reduce)";

function subscribe(cb: () => void) {
  const mq = window.matchMedia?.(QUERY);
  if (!mq) return () => {};
  mq.addEventListener("change", cb);
  return () => mq.removeEventListener("change", cb);
}

/** Sama dengan `reduce` di theme.main.js: matikan autoplay/animasi bila user memilih reduced motion. */
export function useReducedMotion(): boolean {
  return useSyncExternalStore(
    subscribe,
    () => window.matchMedia?.(QUERY).matches ?? false,
    () => false,
  );
}

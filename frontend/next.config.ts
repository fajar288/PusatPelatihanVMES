import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  /* config options here */
  // Build standalone untuk image Docker (frontend/Dockerfile)
  output: "standalone",
  cacheComponents: true,
  partialPrefetching: true,
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;

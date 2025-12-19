import type { NextConfig } from "next";

// In prod (es. hosting/path/charlotte) impostiamo NEXT_PUBLIC_BASE_PATH="/charlotte".
// In locale si può lasciare vuoto.
const basePath = process.env.NEXT_PUBLIC_BASE_PATH ?? "";

const nextConfig: NextConfig = {
  output: "export",
  basePath,
  assetPrefix: basePath,
  images: {
    unoptimized: true,
  },
};

export default nextConfig;

#!/usr/bin/env bash
# Decompress the Chromium build shipped with @sparticuz/chromium into /tmp (no root, no system deps).
set -euo pipefail
cd "$(dirname "$0")"
node -e 'const fs=require("fs"),z=require("zlib"),p=require("path");const d="node_modules/@sparticuz/chromium/bin";
fs.rmSync("/tmp/al2023",{recursive:true,force:true});fs.mkdirSync("/tmp/al2023",{recursive:true});
fs.writeFileSync("/tmp/al2023.tar",z.brotliDecompressSync(fs.readFileSync(p.join(d,"al2023.tar.br"))));
fs.writeFileSync("/tmp/chromium",z.brotliDecompressSync(fs.readFileSync(p.join(d,"chromium.br"))));'
tar -xf /tmp/al2023.tar -C /tmp/al2023 && chmod 755 /tmp/chromium
LD_LIBRARY_PATH=/tmp/al2023/lib /tmp/chromium --version

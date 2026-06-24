#!/usr/bin/env node
/**
 * Generate PNG favicon / PWA icons from the brand SVG.
 *
 * The generated PNGs (favicon-32, apple-touch-icon, icon-192, icon-512) are
 * committed to public/ so a clean clone/build is never missing them. Re-run
 * this only when the brand mark (single_bird.svg) changes:
 *
 *   npm install sharp --no-save && npm run icons:generate
 *
 * See synaplan-apps/docs/ASSETS.md for the full asset pipeline (incl. native
 * app icons/splash via @capacitor/assets) and the white-label swap guide.
 */

import sharp from 'sharp';
import { readFileSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const publicDir = join(__dirname, '..', 'public');
const svgPath = join(publicDir, 'single_bird.svg');

const svgContent = readFileSync(svgPath, 'utf-8');

// Icon sizes needed
const sizes = [
  { name: 'favicon-32.png', size: 32 },
  { name: 'apple-touch-icon.png', size: 180 },
  { name: 'icon-192.png', size: 192 },
  { name: 'icon-512.png', size: 512 },
];

async function generateIcons() {
  console.log('Generating icons from single_bird.svg...\n');
  
  for (const { name, size } of sizes) {
    const outputPath = join(publicDir, name);
    
    await sharp(Buffer.from(svgContent))
      .resize(size, size, {
        fit: 'contain',
        background: { r: 255, g: 255, b: 255, alpha: 0 },
      })
      .png()
      .toFile(outputPath);
    
    console.log(`✓ Created ${name} (${size}x${size})`);
  }
  
  console.log('\nDone! Icons generated in public/ folder.');
}

generateIcons().catch(console.error);


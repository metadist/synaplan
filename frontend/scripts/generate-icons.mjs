#!/usr/bin/env node
/**
 * Generate PNG icons from SVG for favicon and mobile bookmarks
 * 
 * Run: npm install sharp && node scripts/generate-icons.mjs
 * Or: npx sharp-cli ...
 */

import sharp from 'sharp';
import { readFileSync, writeFileSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const publicDir = join(__dirname, '..', 'public');
const svgPath = join(publicDir, 'single_bird.svg');

// Read SVG and add white background for better visibility on iOS
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
        background: { r: 255, g: 255, b: 255, alpha: 0 } // Transparent background
      })
      .png()
      .toFile(outputPath);
    
    console.log(`✓ Created ${name} (${size}x${size})`);
  }
  
  console.log('\nDone! Icons generated in public/ folder.');
}

generateIcons().catch(console.error);


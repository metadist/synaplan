// tailwind.config.js
import plugin from 'tailwindcss/plugin'

/** @type {import('tailwindcss').Config} */
export default {
  content: ["./index.html","./src/**/*.{vue,js,ts,jsx,tsx}"],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        primary: '#003FC7',
        light: {
          bg: '#F2F2F2',
          surface: '#FFFFFF',
          surfaceElevated: '#FAFAFA',
          surfaceSidebar: '#EBEBEB',
          border: '#D4D4D4',
          text: { primary: '#000000', secondary: '#6B6B6B' },
        },
        dark: {
          bg: '#020003',
          surface: '#0F0F10',
          surfaceElevated: '#1A1A1C',
          surfaceSidebar: '#0A0A0B',
          border: '#2E2E30',
          text: { primary: '#FFFFFF', secondary: '#A1A1A3' },
        },
        chat: {
          light: { bg:'#F8F8F8', assistant:'#ECECEC', input:'#FFFFFF', code:'#F5F5F5' },
          dark:  { bg:'#0D0D0E', assistant:'#151517', input:'#1C1C1E', code:'#0A0A0B' },
        },
      },
      boxShadow: {
        subtle: '0 1px 2px 0 rgb(0 0 0 / 0.05)',
      },
    },
  },
  safelist: [
    'surface-card','surface-chip','hover-surface',
    'bubble-ai','bubble-user','nav-item','nav-item--active'
  ],
  plugins: [
    plugin(function({ addBase, theme, addComponents, addUtilities }) {
      // CSS Vars (Light/Dark)
      addBase({
        ':root': {
          '--brand':'#003FC7',
          '--surface-card-bg': theme('colors.light.surface'),
          '--surface-card-ring':'rgba(0,0,0,.06)',
          '--surface-card-shadow':'0 1px 2px rgba(0,0,0,.04)',
          '--surface-chip-bg': theme('colors.light.surfaceElevated'),
          '--surface-chip-ring':'rgba(0,0,0,.06)',
          '--hover-surface':'rgba(0,63,199,.06)',
          '--hover-surface-strong':'rgba(0,63,199,.08)',
          '--bubble-ai-bg':'#F5F5F6',
          '--bubble-ai-ring':'rgba(0,0,0,.06)',
        },
        '.dark': {
          '--surface-card-bg': theme('colors.dark.surface'),
          '--surface-card-ring':'rgba(255,255,255,.08)',
          '--surface-card-shadow':'0 1px 2px rgba(0,0,0,.22)',
          '--surface-chip-bg': theme('colors.dark.surfaceElevated'),
          '--surface-chip-ring':'rgba(255,255,255,.08)',
          '--hover-surface':'rgba(0,63,199,.12)',
          '--hover-surface-strong':'rgba(0,63,199,.16)',
          '--bubble-ai-bg':'#161619',
          '--bubble-ai-ring':'rgba(255,255,255,.08)',
        }
      })

      // Components
      addComponents({
        '.surface-card': {
          background: 'var(--surface-card-bg)',
          borderRadius: '0.75rem',
          boxShadow: `inset 0 0 0 1px var(--surface-card-ring), var(--surface-card-shadow)`,
        },
        '.surface-chip': {
          background: 'var(--surface-chip-bg)',
          borderRadius: '9999px',
          boxShadow: 'inset 0 0 0 1px var(--surface-chip-ring)',
        },
        '.bubble-ai': {
          background: 'var(--bubble-ai-bg)',
          borderRadius: '0.75rem',
          boxShadow: 'inset 0 0 0 1px var(--bubble-ai-ring)',
        },
        '.bubble-user': {
          background: '#003FC7',
          color: '#fff',
          borderRadius: '0.75rem',
          boxShadow: '0 1px 2px rgba(0,0,0,.12)',
        },
        '.nav-item': {
          background: 'rgba(0,0,0,.02)',
          borderRadius: '0.75rem',
          color: '#444',
          boxShadow:
            'inset 0 0 0 1px rgba(0,63,199,.08), inset 0 0 0 1px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04)',
          transition: 'background .15s, box-shadow .15s',
        },
        '.nav-item:hover': {
          background: 'rgba(0,63,199,.03)',
          boxShadow:
            'inset 0 0 0 1px rgba(0,63,199,.12), inset 0 0 0 1px rgba(0,0,0,.06), 0 4px 12px rgba(0,0,0,.06)',
        },
        '.nav-item--active': {
          background: 'rgba(0,63,199,.06)',
          color: 'var(--brand)',
          boxShadow:
            'inset 0 0 0 2px rgba(0,63,199,.28), inset 0 0 0 1px rgba(0,0,0,.06)',
        },
        '.dark .nav-item': {
          background: 'transparent',
          color: '#D7DAE0',
          boxShadow:
            'inset 0 0 0 1px rgba(0,63,199,.10), inset 0 0 0 1px rgba(255,255,255,.04)',
        },
        '.dark .nav-item:hover': {
          background: 'rgba(0,63,199,.035)',
          boxShadow:
            'inset 0 0 0 1px rgba(0,63,199,.14), inset 0 0 0 1px rgba(255,255,255,.06)',
        },
        '.dark .nav-item--active': {
          background: 'rgba(0,63,199,.055)',
          color: '#E6EEFF',
          boxShadow:
            'inset 0 0 0 2px rgba(0,63,199,.38), inset 0 0 0 1px rgba(255,255,255,.08)',
        },
      })

      // Utilities
      addUtilities({
        '.hover-surface:hover': { background: 'var(--hover-surface)' },
        '.hover-surface-strong:hover': { background: 'var(--hover-surface-strong)' },
      })
    })
  ],
}

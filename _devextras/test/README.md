# Synaplan Widget Test App

Mini Vue3 + Vite app to test the Synaplan chat widget locally.

## Setup

```bash
npm install
```

## Run

```bash
npm run dev
```

The app will open at `http://localhost:3000`

## What it does

- Loads the widget from `http://localhost:8000/widget.js`
- Tests widget integration
- Shows live events and statistics
- Allows you to control the widget (open, close, new chat)

## Important

Make sure:
1. Backend is running on `http://localhost:8000`
2. Widget is configured to allow `localhost:3000` in allowed domains
3. Widget ID in `src/App.vue` matches your widget


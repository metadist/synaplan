# Synaplan Vue Frontend

A modern Vue 3 application for AI-powered chat, document processing, and widget management with a clean, responsive design system.

## 🚀 Quick Start

```bash
# Install dependencies
npm install

# Run development server
npm run dev

# Build for production
npm run build

# Run tests
npm run test
```

## 📋 Requirements

- Node.js 16+
- npm or pnpm

## ⚙️ Environment Variables

Create a `.env` file:

```env
VITE_APP_ENV=development
VITE_API_BASE_URL=http://localhost:3000/api
VITE_ENABLE_MOCK_DATA=true
VITE_SHOW_ERROR_STACK=true
VITE_AUTO_LOGIN_DEV=true
```

For production, set all debug flags to `false` and update `VITE_API_BASE_URL`.

## 🏗️ Project Structure

```
src/
├── components/          # Reusable UI components
│   ├── widgets/        # Widget-related components
│   ├── config/         # Configuration pages
│   └── ...
├── views/              # Page-level components (routes)
├── stores/             # Pinia state management
├── services/           # API service layer
├── mocks/              # Mock data (API preparation)
├── composables/        # Reusable composition functions
├── router/             # Vue Router configuration
├── i18n/               # Internationalization (English only)
└── utils/              # Helper functions
```

## ✨ Features

### 1. AI Chat Interface
- Real-time SSE streaming with live responses
- Multiple AI model support (Ollama, OpenAI, Anthropic, Groq, Google)
- Rich message rendering: text, code blocks, links, images, videos
- Thinking/reasoning blocks display (collapsible)
- Chat sessions with history and lazy loading
- Model selection and "Again" functionality
- Processing status indicators during classification and generation
- Custom dialogs replacing native browser alerts

### 2. Widget Management
- Create and manage embeddable chat widgets
- Live preview on custom URLs
- Customizable appearance (colors, position, theme)
- Responsive design with mobile support
- Embed code generation

### 3. Document Processing
- Document summarization
- File upload and management
- Multiple format support

### 4. Mail Handler
- Email processing automation
- Department routing
- Connection testing

### 5. User Management
- Profile settings
- Billing information
- Account management
- Unsaved changes protection

## 🎨 Design System

The app uses a consistent design system with:

### CSS Tokens (in `style.css`)
- **Backgrounds**: `bg-app`, `bg-sidebar`, `bg-chat`, `surface-card`, `surface-chip`
- **Text**: `txt-primary`, `txt-secondary`
- **Buttons**: `btn-primary`
- **Navigation**: `nav-item`, `nav-item--active`
- **Scrollbars**: `scroll-thin`, `sidebar-scroll`, `chat-input-scroll`
- **Interactive**: `hover-surface`, `icon-ghost`, `pill`, `pill--active`

### Tailwind Integration
The design system combines custom CSS tokens with Tailwind utilities for spacing, layout, and responsive design.

### Dark Mode
Automatic theme switching based on system preferences. All components support both light and dark modes through CSS variables.

## 🌐 Internationalization

- Currently supports **English only**
- All UI text uses `$t()` function from Vue I18n
- Translation keys in `src/i18n/en.json`
- **Never hardcode text** in components

```vue
<!-- ✅ Correct -->
<h1>{{ $t('welcome') }}</h1>

<!-- ❌ Wrong -->
<h1>Welcome</h1>
```

## 📦 State Management

Uses **Pinia** for centralized state:

- `useHistoryStore` - Chat history
- `useModelsStore` - AI models
- `useSidebarStore` - Sidebar state
- `useCommandsStore` - Command definitions

## 🔌 API Integration

Currently using **mock data** for rapid development. All mock files are in `src/mocks/`:

```typescript
// Example: src/mocks/widgets.ts
export interface Widget {
  id: string
  userId: string
  // ...
}

export const mockWidgets: Widget[] = [...]
```

### Migration Path
```
Component → Store → Service (API) → Backend
          ↓
       Mock Data (fallback)
```

Replace mock imports with actual API calls in `src/services/apiService.ts` when backend is ready.

## 🧩 Key Components

### UnsavedChangesBar
A reusable component for forms with unsaved changes:
- Discord-inspired slide-up bar
- Save/Discard/Preview actions
- Escape key support
- Auto-focus and loading states
- Navigation guards

```vue
<UnsavedChangesBar
  :show="hasChanges"
  :show-preview="true"
  @save="handleSave"
  @discard="handleDiscard"
  @preview="handlePreview"
/>
```

### ChatWidget
Embeddable chat widget with:
- Light/dark theme switching
- Customizable colors and position
- Preview mode for editor
- Mobile-responsive
- File upload support

### MainLayout
Core layout component with:
- Responsive sidebar
- Header with user menu
- Theme toggle
- Notification system

## 🛠️ Development Guidelines

### Component Creation
1. Use **Composition API** with TypeScript
2. Keep components **small and focused**
3. Use **@ alias** for imports: `@/components/X.vue`
4. Extract mock data to `src/mocks/`
5. Add i18n keys to `src/i18n/en.json`

### Styling Rules
- ✅ Use existing design tokens
- ✅ Use Tailwind utilities for spacing/layout
- ❌ Don't add new CSS variables
- ❌ Don't override tokens in components
- ❌ Don't use inline hex colors

### Code Style
```typescript
// ✅ Good
const user = ref<User>({ name: 'John' })
const fullName = computed(() => `${user.value.name}`)

// ❌ Avoid
var user = { name: 'John' }
let fullName = user.name
```

## 🧪 Testing

```bash
# Run unit tests
npm run test

# Run with coverage
npm run test:coverage
```

Test files are in `tests/` directory, mirroring the `src/` structure.

## 📱 Responsive Design

Breakpoints follow Tailwind defaults:
- `sm`: 640px
- `md`: 768px
- `lg`: 1024px
- `xl`: 1280px

Key responsive features:
- Collapsible sidebar on mobile
- Adaptive widget preview
- Touch-friendly buttons (min 48px height)
- Optimized padding/spacing

## 🔐 Security

- All external URLs in iframes use `sandbox` attribute
- File uploads validate size and type
- XSS protection via Vue's automatic escaping
- CORS-ready API service layer

## 📚 Commands System

The app supports special commands in chat:

- `/pic [description]` - Generate AI images
- `/vid [description]` - Generate short videos
- `/search [query]` - Web search
- `/lang [target] [text]` - Translation
- `/web [url]` - Website screenshots
- `/docs [query]` - Document search
- `/link` - Profile linking

## 🚧 Future Enhancements

- [x] Real API integration (authentication, chat, sessions)
- [x] SSE streaming for real-time responses
- [x] Multi-provider AI support
- [ ] File upload and analysis
- [ ] Web search tool integration
- [ ] Media generation (images, videos, audio)
- [ ] Advanced widget analytics
- [ ] Multi-language support

## 📄 License

[Your License Here]

## 🤝 Contributing

1. Follow the design system in `AI-README.md`
2. Add tests for new features
3. Update i18n for any new text
4. Keep components small and reusable
5. Document complex logic

---

For detailed design system rules and development guidelines, see [AI-README.md](./AI-README.md).

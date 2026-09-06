import assert from 'node:assert/strict'
import test from 'node:test'

import {
  checksumManifest,
  classifyFiles,
  createManifest,
  escalateWithLabels,
  loadPolicy,
  parseExplicitFiles,
  serializeManifest
} from '../scripts/mobile-impact.mjs'

const policy = loadPolicy()
const entry = (path, status = 'M') => ({ path, status })

test('classifies documentation as no-app-impact', () => {
  const result = classifyFiles([entry('docs/mobile-release.md')], policy)

  assert.equal(result.classification, 'no-app-impact')
})

test('classifies allow-listed internal backend files as backend-only', () => {
  const result = classifyFiles([
    entry('backend/src/Service/ReportExportService.php', 'M'),
    entry('backend/tests/Unit/Service/ReportExportServiceTest.php', 'M')
  ], policy)

  assert.equal(result.classification, 'backend-only')
  assert.equal(
    classifyFiles([entry('backend/src/Service/Client/MobileVersionService.php', 'M')], policy)
      .classification,
    'store-required'
  )
})

test('classifies IAM AccessGate as backend-only', () => {
  const result = classifyFiles([entry('backend/src/Service/Iam/AccessGate.php', 'A')], policy)

  assert.equal(result.classification, 'backend-only')
})

test('classifies People page as ota-candidate', () => {
  const result = classifyFiles([entry('frontend/src/views/PeopleView.vue', 'A')], policy)

  assert.equal(result.classification, 'ota-candidate')
})

test('classifies Share dialog as ota-candidate', () => {
  const result = classifyFiles([entry('frontend/src/components/iam/ShareDialog.vue', 'A')], policy)

  assert.equal(result.classification, 'ota-candidate')
})

test('classifies web-layer application code and assets as ota-candidate', () => {
  const paths = [
    'frontend/src/views/ChatView.styles.css',
    'frontend/src/i18n/de.json',
    'frontend/src/assets/logo.svg',
    'frontend/src/components/icons/ProviderIcon.vue',
    'frontend/src/components/ChatComposer.vue',
    'frontend/src/components/ChatErrorNotice.vue',
    'frontend/src/composables/useNewFeature.ts',
    'frontend/src/router/index.ts',
    'frontend/src/generated/api-schemas.ts',
    'frontend/index.html',
    'frontend/package.json',
    'frontend/package-lock.json',
    'frontend/vite.config.ts',
    'frontend/tailwind.config.js'
  ]

  for (const path of paths) {
    assert.equal(classifyFiles([entry(path, 'A')], policy).classification, 'ota-candidate', path)
  }
})

test('classifies mobile contracts and native seams as store-required', () => {
  const paths = [
    'frontend/src/services/nativeIap.ts',
    'frontend/src/services/iapPostAuthRedemption.ts',
    'frontend/src/stores/auth.ts',
    'frontend/src/stores/config.ts',
    'frontend/src/services/api/nativeRuntime.ts',
    'frontend/public/sw.js',
    'package.json',
    'package-lock.json',
    'capacitor.config.ts',
    'backend/src/Service/Client/MobileVersionService.php'
  ]

  for (const path of paths) {
    assert.equal(classifyFiles([entry(path, 'A')], policy).classification, 'store-required', path)
  }
})

test('classifies the release-critical categories as store-required', () => {
  const categories = {
    'in-app purchases and payments': [
      'frontend/src/services/purchases.ts',
      'frontend/src/components/subscription/PaywallModal.vue',
      'frontend/src/composables/useSubscriptionPurchase.ts',
      'frontend/src/views/SubscriptionView.vue',
      'frontend/src/components/StripeCheckoutButton.vue'
    ],
    'native projects': [
      'android/app/src/main/AndroidManifest.xml',
      'ios/App/App/Info.plist',
      'ios/App/App/PrivacyInfo.xcprivacy'
    ],
    'authentication transport': [
      'frontend/src/services/authService.ts',
      'frontend/src/utils/pendingAuthRedirect.ts',
      'frontend/src/components/auth/OAuthCallback.vue',
      'frontend/src/composables/useSocialAuth.ts'
    ],
    'update mechanism': [
      'frontend/public/site.webmanifest',
      'frontend/src/services/otaUpdates.ts',
      'frontend/src/registerServiceWorker.ts'
    ]
  }

  for (const [category, paths] of Object.entries(categories)) {
    for (const path of paths) {
      assert.equal(
        classifyFiles([entry(path, 'A')], policy).classification,
        'store-required',
        `${category}: ${path}`
      )
    }
  }
})

test('release and classification tooling does not trigger an app release', () => {
  const toolingPaths = [
    '.github/workflows/ci.yml',
    '.github/workflows/release-tag.yml',
    '.github/mobile-impact-policy.json',
    'scripts/next-release-tag.mjs',
    'tests/next-release-tag.test.mjs'
  ]

  for (const path of toolingPaths) {
    assert.equal(classifyFiles([entry(path, 'M')], policy).classification, 'no-app-impact', path)
  }
})

test('local development, deployment, and test tooling does not trigger an app release', () => {
  const toolingPaths = [
    '_docker/backend/Dockerfile',
    '_docker/backend/docker-entrypoint.sh',
    '_devextras/reorganize-env.sh',
    '_devextras/screenvideo/package.json',
    '_1st_install_linux.sh',
    'docker-compose.yml',
    'docker-compose.test.yml',
    'deploy/compose.yaml',
    'deploy/scripts/selfhost.sh',
    'cloudflare/src/index.ts',
    'elestio.yml',
    'server.json',
    'renovate.json5',
    'LICENSE',
    'Makefile',
    '.editorconfig',
    '.vscode/settings.json',
    'scripts/resolve-app-version.mjs',
    'tests/mobile-impact.test.mjs',
    'frontend/tests/unit/setup.ts',
    'frontend/tests/e2e/tests/memories.spec.ts',
    'frontend/eslint.config.js',
    'frontend/.prettierrc'
  ]

  for (const path of toolingPaths) {
    assert.equal(classifyFiles([entry(path, 'M')], policy).classification, 'no-app-impact', path)
  }
})

test('classifies all server-side code and server-delivered plugins as backend-only', () => {
  const backendPaths = [
    'backend/config/packages/messenger.yaml',
    'backend/config/packages/security.yaml',
    'backend/config/services.yaml',
    'backend/migrations/Version20260729120000.php',
    'backend/src/AI/Provider/OpenAIProvider.php',
    'backend/src/AI/Exception/ChatFailureReason.php',
    'backend/translations/ai_errors.en.yaml',
    'backend/src/Controller/ConfigController.php',
    'backend/src/Controller/OpenApiController.php',
    'backend/src/DTO/UserMemoryDTO.php',
    'backend/src/Model/ModelCatalog.php',
    'backend/src/Prompt/PromptCatalog.php',
    'backend/src/Service/SubscriptionService.php',
    'backend/src/Service/StripeCheckoutService.php',
    'backend/composer.json',
    'backend/.env.example',
    'plugins/synamail/frontend/index.js',
    'plugins/synamail/backend/Controller/ProfileController.php'
  ]

  for (const path of backendPaths) {
    assert.equal(classifyFiles([entry(path, 'M')], policy).classification, 'backend-only', path)
  }
})

test('classifies the model status surfaces as backend-only plus ota-candidate', () => {
  const backendPaths = [
    'backend/src/AI/Health/ModelHealthEvaluator.php',
    'backend/src/AI/Health/Probe/PlatformKeyModelListProbe.php',
    'backend/src/Command/ModelHealthCheckCommand.php',
    'backend/src/Controller/AdminModelHealthController.php',
    'backend/src/Entity/ModelHealth.php',
    'backend/src/Repository/ModelHealthRepository.php'
  ]

  for (const path of backendPaths) {
    assert.equal(classifyFiles([entry(path, 'A')], policy).classification, 'backend-only', path)
  }

  // The status page is ordinary web-layer code and must stay shippable over
  // the air. A store review for a monitoring screen would be pure friction.
  const webPaths = [
    'frontend/src/views/ModelStatusView.vue',
    'frontend/src/services/api/adminModelStatusApi.ts'
  ]

  for (const path of webPaths) {
    assert.equal(classifyFiles([entry(path, 'A')], policy).classification, 'ota-candidate', path)
  }

  // The scheduler slot that runs the check lives in container tooling and is
  // never delivered to an installed app.
  assert.equal(
    classifyFiles([entry('_docker/backend/lib/container-runtime.sh', 'M')], policy).classification,
    'no-app-impact'
  )
})

test('classifies the first-run setup wizard as backend-only plus ota-candidate', () => {
  const backendPaths = [
    'backend/src/Command/AdminResetPasswordCommand.php',
    'backend/src/Command/SetupResetCommand.php',
    'backend/src/Controller/SetupController.php',
    'backend/src/DTO/SetupAdminRequest.php',
    'backend/src/DTO/SetupCompleteRequest.php',
    'backend/src/EventSubscriber/SetupLockdownSubscriber.php',
    'backend/src/Service/Setup/SetupConstants.php',
    'backend/src/Service/Setup/SetupStateService.php'
  ]

  for (const path of backendPaths) {
    assert.equal(classifyFiles([entry(path, 'A')], policy).classification, 'backend-only', path)
  }

  // The wizard is the first screen a fresh install shows, so it has to stay
  // fixable over the air. It touches no native seam on purpose: the router gate
  // reads the setup flag straight from the runtime config instead of through
  // `stores/config.ts`, and the server-switch escape hatch calls the existing
  // native bridge rather than changing it.
  const webPaths = [
    'frontend/src/components/setup/SetupAccessStep.vue',
    'frontend/src/components/setup/SetupAdminStep.vue',
    'frontend/src/components/setup/SetupDoneStep.vue',
    'frontend/src/components/setup/SetupProviderKeyForm.vue',
    'frontend/src/components/setup/SetupProviderStep.vue',
    'frontend/src/components/setup/SetupProviderTile.vue',
    'frontend/src/composables/useSetupState.ts',
    'frontend/src/router/setupGate.ts',
    'frontend/src/services/api/setupApi.ts',
    'frontend/src/views/SetupWizardView.vue'
  ]

  for (const path of webPaths) {
    assert.equal(classifyFiles([entry(path, 'A')], policy).classification, 'ota-candidate', path)
  }

  // The demo-fixture opt-out that makes a virgin instance reproducible locally
  // is container tooling and never reaches an installed app.
  assert.equal(
    classifyFiles([entry('_docker/backend/docker-entrypoint.sh', 'M')], policy).classification,
    'no-app-impact'
  )
})

test('uses the highest classification for mixed changes', () => {
  assert.equal(classifyFiles([
    entry('README.md'),
    entry('backend/src/Service/NewReportService.php', 'A')
  ], policy).classification, 'backend-only')

  assert.equal(classifyFiles([
    entry('backend/src/Service/NewReportService.php', 'A'),
    entry('frontend/src/style.css')
  ], policy).classification, 'ota-candidate')

  assert.equal(classifyFiles([
    entry('frontend/src/style.css'),
    entry('frontend/src/services/nativeIap.ts')
  ], policy).classification, 'store-required')
})

test('fails closed for unknown repository paths', () => {
  assert.equal(
    classifyFiles([entry('new-tool/config.json', 'A')], policy).classification,
    'store-required'
  )
  assert.equal(
    classifyFiles([entry('sdk/index.ts', 'A')], policy).classification,
    'store-required'
  )
})

test('labels only escalate and OTA approval never bypasses classification', () => {
  assert.equal(
    escalateWithLabels('store-required', ['mobile-impact:ota-candidate'], policy),
    'store-required'
  )
  assert.equal(
    escalateWithLabels('backend-only', ['mobile-impact:store-required'], policy),
    'store-required'
  )
  assert.equal(
    escalateWithLabels('store-required', ['mobile-ota-approved'], policy),
    'store-required'
  )
  assert.equal(
    escalateWithLabels('ota-candidate', ['mobile-ota-approved'], policy),
    'ota-candidate'
  )
})

test('creates deterministic manifests and checksums', () => {
  const input = {
    repository: 'metadist/synaplan',
    baseSha: '1111111111111111111111111111111111111111',
    headSha: '2222222222222222222222222222222222222222',
    tag: 'v4.0.0-rc.1',
    apiContractHash: 'a'.repeat(64),
    createdAt: '2026-07-10T08:00:00+00:00',
    entries: [
      entry('frontend/src/i18n/de.json'),
      entry('frontend/src/style.css')
    ],
    policy
  }
  const first = serializeManifest(createManifest(input))
  const second = serializeManifest(createManifest({
    ...input,
    entries: [...input.entries].reverse()
  }))

  assert.equal(first, second)
  assert.match(checksumManifest(first), /^[a-f0-9]{64}$/)
  assert.equal(JSON.parse(first).createdAt, '2026-07-10T08:00:00.000Z')
  assert.equal(JSON.parse(first).tag, 'v4.0.0-rc.1')
  assert.equal(JSON.parse(first).apiContractHash, 'a'.repeat(64))
})

test('parses explicit name-status and status:path file lists', () => {
  assert.deepEqual(
    parseExplicitFiles(
      'A\tbackend/src/Service/NewService.php\n' +
      'R100\tfrontend/src/old.css\tfrontend/src/new.css\n' +
      'M:frontend/src/style.css\nREADME.md\n'
    ),
    [
      { status: 'A', path: 'backend/src/Service/NewService.php' },
      {
        status: 'R100',
        previousPath: 'frontend/src/old.css',
        path: 'frontend/src/new.css'
      },
      { status: 'M', path: 'frontend/src/style.css' },
      { status: 'M', path: 'README.md' }
    ]
  )
})

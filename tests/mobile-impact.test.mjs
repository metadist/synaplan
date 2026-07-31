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

test('classifies styling, translations, assets, and presentational icons as ota-candidate', () => {
  const result = classifyFiles([
    entry('frontend/src/views/ChatView.styles.css'),
    entry('frontend/src/i18n/de.json'),
    entry('frontend/src/assets/logo.svg'),
    entry('frontend/src/components/icons/ProviderIcon.vue')
  ], policy)

  assert.equal(result.classification, 'ota-candidate')
})

test('classifies mobile contracts and dependencies as store-required', () => {
  const paths = [
    'frontend/src/services/nativeIap.ts',
    'frontend/src/stores/auth.ts',
    'frontend/src/router/index.ts',
    'frontend/src/stores/config.ts',
    'frontend/src/services/api/nativeRuntime.ts',
    'frontend/src/generated/api-schemas.ts',
    'frontend/public/sw.js',
    'frontend/package.json',
    'backend/src/Service/Client/MobileVersionService.php',
    'backend/src/Controller/ConfigController.php'
  ]

  for (const path of paths) {
    assert.equal(classifyFiles([entry(path, 'A')], policy).classification, 'store-required', path)
  }
})

test('classifies the four release-critical categories as store-required', () => {
  const categories = {
    'in-app purchases': [
      'frontend/src/services/purchases.ts',
      'frontend/src/components/subscription/PaywallModal.vue',
      'backend/src/Service/SubscriptionService.php',
      'backend/src/Service/StripeCheckoutService.php'
    ],
    'device permissions': [
      'frontend/src/composables/useCameraCapture.ts',
      'android/app/src/main/AndroidManifest.xml',
      'ios/App/App/Info.plist'
    ],
    'authentication transport': [
      'frontend/src/services/authService.ts',
      'frontend/src/utils/pendingAuthRedirect.ts',
      'backend/config/packages/security.yaml'
    ],
    privacy: [
      'ios/App/App/PrivacyInfo.xcprivacy',
      'frontend/public/site.webmanifest',
      'frontend/src/services/otaUpdates.ts'
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

test('executable code never qualifies as an over-the-air asset', () => {
  const executableAssets = [
    'frontend/src/assets/tracking.ts',
    'frontend/src/assets/vendor/analytics.js',
    'frontend/src/components/icons/iconRegistry.ts',
    'frontend/src/assets/embed.html'
  ]

  for (const path of executableAssets) {
    const result = classifyFiles([entry(path, 'A')], policy)
    assert.equal(result.classification, 'store-required', path)
    // The manifest must name the exclusion rather than claim the path was
    // never allow-listed — it is, and is held back for being executable.
    assert.equal(result.reasons[0].reason, policy.otaCandidate.excludedReason, path)
  }

  assert.equal(
    classifyFiles([
      entry('frontend/src/assets/logo.svg'),
      entry('frontend/src/components/icons/ProviderIcon.vue')
    ], policy).classification,
    'ota-candidate'
  )
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

test('local development, container, and test tooling does not trigger an app release', () => {
  const toolingPaths = [
    '_docker/backend/Dockerfile',
    '_docker/backend/docker-entrypoint.sh',
    '_devextras/reorganize-env.sh',
    '_devextras/screenvideo/package.json',
    '_1st_install_linux.sh',
    'docker-compose.yml',
    'docker-compose.test.yml',
    'backend/.env.example',
    'backend/phpstan-baseline.neon',
    'backend/phpunit.xml.dist',
    'frontend/tests/unit/setup.ts',
    'frontend/tests/e2e/tests/memories.spec.ts'
  ]

  for (const path of toolingPaths) {
    assert.equal(classifyFiles([entry(path, 'M')], policy).classification, 'no-app-impact', path)
  }
})

test('classifies server-side configuration, migrations, and providers as backend-only', () => {
  const backendPaths = [
    'backend/config/packages/messenger.yaml',
    'backend/config/services.yaml',
    'backend/migrations/Version20260729120000.php',
    'backend/src/AI/Provider/OpenAIProvider.php',
    'backend/src/DTO/UserMemoryDTO.php',
    'backend/src/Model/ModelCatalog.php',
    'backend/src/Prompt/PromptCatalog.php'
  ]

  for (const path of backendPaths) {
    assert.equal(classifyFiles([entry(path, 'M')], policy).classification, 'backend-only', path)
  }
})

test('security, mobile, and payment implementations stay store-required by an explicit rule', () => {
  const guardedPaths = [
    'backend/config/packages/security.yaml',
    'backend/src/Service/MobilePurchaseService.php',
    'backend/src/AI/Service/StripeBillingBridge.php',
    'frontend/package.json',
    'frontend/package-lock.json'
  ]

  for (const path of guardedPaths) {
    const result = classifyFiles([entry(path, 'M')], policy)
    assert.equal(result.classification, 'store-required', path)
    // A named rule must catch these, otherwise the manifest only reports that
    // the path was never allow-listed and hides why the release is gated.
    assert.equal(result.reasons[0].reason, policy.storeRequired.reason, path)
  }
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
    entry('frontend/src/router/index.ts')
  ], policy).classification, 'store-required')
})

test('fails closed for unknown frontend and repository paths', () => {
  assert.equal(
    classifyFiles([entry('frontend/src/composables/useNewFeature.ts')], policy).classification,
    'store-required'
  )
  assert.equal(
    classifyFiles([entry('new-tool/config.json', 'A')], policy).classification,
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

<?php

declare(strict_types=1);

namespace App\Module\Contract;

/**
 * One optional feature, declared in one place.
 *
 * A feature module says what it is, what configures it and what it exposes.
 * Everything else — the Feature status page, the capability inventory, the
 * request gate and the frontend's "hide what is absent" — derives from these
 * answers, so adding an optional feature means adding one class and nothing
 * else (feature modules master plan §1, §4.1).
 *
 * Implementations are `final readonly`, live under `App\Module\…`, are tagged
 * `app.feature_module` by autoconfiguration and MUST declare
 * `public const ID = '<id>'` with the same value `id()` returns; the
 * FeatureModuleTagPass indexes the registry by that constant and refuses
 * duplicates at container compile time.
 *
 * Descriptors must be cheap to construct: never open a connection or probe a
 * sidecar in the constructor or in `isConfigured()`. Health probes belong in
 * `status()` only.
 */
interface FeatureModuleInterface
{
    /** Stable identifier, e.g. `tika`. Lower-case snake case. */
    public function id(): string;

    /** Frontend i18n key for the human label, e.g. `modules.tika.label`. */
    public function labelKey(): string;

    /** Which env keys, BCONFIG rows and provider/plug keys make this module configured. */
    public function configuredBy(): ConfiguredBy;

    /**
     * Is the module configured on this installation?
     *
     * Reads env AND the runtime stores (ProviderKeyStore, PlugKeyStore, BCONFIG)
     * so a key entered in the UI counts without a restart; never cached longer
     * than those stores cache themselves (≤ 5 minutes). Cheap: no I/O to the
     * feature itself.
     */
    public function isConfigured(): bool;

    /** Live status for the admin Feature status page. May probe the feature; keep it bounded (short timeouts). */
    public function status(): ModuleStatus;

    /**
     * Ids in PlatformCapabilityInventory this module provides. Empty when the
     * module is not a user-facing capability (e.g. billing).
     *
     * @return list<string>
     */
    public function capabilityIds(): array;

    /**
     * Route names whose controllers exist only for this module and answer
     * `404 feature_not_configured` when it is absent and its gate is on.
     * An entry is an exact route name, or a prefix when it ends in `*`
     * (`api_whatsapp_assistant_*`). Credential/connect/status routes that let
     * an admin *configure* the module, and store/Meta webhooks that must keep
     * their own status codes, are never listed here.
     *
     * @return list<string>
     */
    public function routeNames(): array;

    /**
     * Service classes owned by this module (architecture test only; no runtime effect).
     *
     * @return list<class-string>
     */
    public function serviceIds(): array;

    /** Anchor in the admin docs explaining how to enable the module, e.g. `modules/tika`. */
    public function docsAnchor(): string;

    /** Release class of a change to this module's files, for the mobile-impact policy. */
    public function mobileClass(): MobileClass;
}

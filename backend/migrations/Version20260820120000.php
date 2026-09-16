<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retire the two xAI speech models the provider no longer serves:
 *
 *   - BID 320 grok-tts (text2sound)
 *   - BID 321 grok-stt (sound2text)
 *
 * Both answer 404 on GET /v1/models/<id> and are absent from the xAI model
 * listing, so any install routing speech at them fails at request time (#1514).
 * Deactivated, never deleted: BMESSAGES rows reference the BIDs via FK. Same
 * contract as {@see Version20260819080000}.
 *
 * Unlike the Groq retirement there is NO successor to repoint to: xAI offers no
 * replacement for either capability, and binding an install to another
 * provider's model would assume an API key the operator may not hold. The
 * DEFAULTMODEL bindings are therefore REMOVED rather than repointed, which
 * hands the capability back to the normal resolution chain — the same state as
 * an install that never chose a speech model. {@see \App\Service\ModelConfigService}
 * treats a deactivated row as unusable and degrades through its logged
 * fallback, so removing the row loses nothing that still worked.
 *
 * The xAI SOUND2TEXT recommendation is dropped from ProviderDefaultsService in
 * the same release. Without that, `app:provider:apply-defaults --auto` would
 * write the dead binding straight back on the next container start.
 */
final class Version20260820120000 extends AbstractMigration
{
    /**
     * Retired BID => upstream API model id, used as a guard so the row is left
     * alone when it was manually repurposed, and a re-run stays idempotent.
     *
     * @var array<int, string>
     */
    private const RETIRED_MODELS = [
        320 => 'grok-tts',
        321 => 'grok-stt',
    ];

    public function getDescription(): string
    {
        return 'Deactivate the retired xAI speech models (grok-tts BID 320, grok-stt BID 321) and drop the '
            .'DEFAULTMODEL bindings pointing at them, so speech resolution falls back to a live model.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::RETIRED_MODELS as $retiredBid => $providerId) {
            // Global (ownerId 0) and per-user bindings alike: a dead id is no
            // more usable for one user than for the install.
            $this->addSql(<<<'SQL'
                DELETE FROM BCONFIG
                 WHERE BGROUP = 'DEFAULTMODEL'
                   AND BVALUE = :retired
            SQL, [
                'retired' => (string) $retiredBid,
            ]);

            $this->addSql(<<<'SQL'
                UPDATE BMODELS
                   SET BACTIVE = 0,
                       BSELECTABLE = 0,
                       BISDEFAULT = 0
                 WHERE BID = :retired
                   AND BPROVID = :providerId
            SQL, [
                'retired' => $retiredBid,
                'providerId' => $providerId,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        // Reactivate the rows so they reappear in the admin UI. The deleted
        // bindings are deliberately NOT restored: they pointed at a model the
        // provider does not serve, and the capability has since resolved
        // through the fallback chain. Same contract as Version20260819080000.
        foreach (self::RETIRED_MODELS as $retiredBid => $providerId) {
            $this->addSql(<<<'SQL'
                UPDATE BMODELS
                   SET BACTIVE = 1,
                       BSELECTABLE = 1
                 WHERE BID = :retired
                   AND BPROVID = :providerId
            SQL, [
                'retired' => $retiredBid,
                'providerId' => $providerId,
            ]);
        }
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill card.variation from the reference\'s own B/P/A segment — CardBuilder never derived it from there, so every non-kickstarter/non-serialized card was stuck at the default "standard" (promo cards only got flagged correctly when the fr-fr collectorNumberFormatted heuristic happened to run, and alt-art was never detected at all)';
    }

    public function up(Schema $schema): void
    {
        // Reference format: ALT_SET_VARIANT_FACTION_NUM_RARITY[_UNIQUENUM]
        // split_part(..., 3) (1-indexed) is the VARIANT segment: B=standard, P=promo, A=alt-art.
        // kickstarter/serialized cards are left untouched — those two variations were already
        // set correctly from their own dedicated flags, independent of this bug.
        $this->addSql(<<<'SQL'
            UPDATE card
            SET    variation = CASE split_part(reference, '_', 3)
                       WHEN 'P' THEN 'promo'
                       WHEN 'A' THEN 'alt-art'
                       ELSE 'standard'
                   END
            WHERE  kickstarter = FALSE
            AND    is_serialized = FALSE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Not a true revert (the original buggy values aren't recoverable) — restores the
        // pre-migration default every affected row effectively had.
        $this->addSql(<<<'SQL'
            UPDATE card
            SET    variation = 'standard'
            WHERE  kickstarter = FALSE
            AND    is_serialized = FALSE
        SQL);
    }
}

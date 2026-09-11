<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill unique card names from their non-unique sibling family — unique prints '
            . 'are imported from their own per-instance Equinox JSON, which for a number of '
            . 'families carries a wrong/English-leaked name in some locales; the non-unique '
            . 'printing sharing the same faction+number slot already has the correct name and '
            . 'is the same in-universe character, so its translation is the source of truth. '
            . 'Run family by family (own transaction each) instead of one bulk UPDATE — some '
            . 'families have 10k+ serialized instances.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE PROCEDURE pg_temp.backfill_unique_names()
            LANGUAGE plpgsql
            AS $proc$
            DECLARE
                fam RECORD;
            BEGIN
                FOR fam IN
                    SELECT DISTINCT substring(cg.slug FROM '^([A-Z]+-[0-9]+)-') AS family_key
                    FROM   card_group cg
                    WHERE  cg.slug !~ '-U-'
                      AND  substring(cg.slug FROM '^([A-Z]+-[0-9]+)-') IS NOT NULL
                LOOP
                    UPDATE card_group_translation cgt
                    SET    name = commune.name
                    FROM   card_group cg_u
                    JOIN ( SELECT cgt2.locale, cgt2.name
                           FROM   card_group cg2
                           JOIN   card_group_translation cgt2 ON cgt2.card_group_id = cg2.id
                           WHERE  cg2.slug !~ '-U-'
                             AND  substring(cg2.slug FROM '^([A-Z]+-[0-9]+)-') = fam.family_key
                         ) commune ON substring(cg_u.slug FROM '^([A-Z]+-[0-9]+)-') = fam.family_key
                    WHERE  cg_u.slug ~ '-U-'
                      AND  cgt.card_group_id = cg_u.id
                      AND  cgt.locale = commune.locale
                      AND  cgt.name IS DISTINCT FROM commune.name;

                    UPDATE card_translation ct
                    SET    name = commune.name
                    FROM   card c
                    JOIN   card_group cg_u ON cg_u.id = c.card_group_id AND cg_u.slug ~ '-U-'
                    JOIN ( SELECT cgt2.locale, cgt2.name
                           FROM   card_group cg2
                           JOIN   card_group_translation cgt2 ON cgt2.card_group_id = cg2.id
                           WHERE  cg2.slug !~ '-U-'
                             AND  substring(cg2.slug FROM '^([A-Z]+-[0-9]+)-') = fam.family_key
                         ) commune ON substring(cg_u.slug FROM '^([A-Z]+-[0-9]+)-') = fam.family_key
                    WHERE  ct.card_id = c.id
                      AND  ct.locale = commune.locale
                      AND  ct.name IS DISTINCT FROM commune.name;

                    COMMIT;
                END LOOP;
            END;
            $proc$
        SQL);

        $this->addSql('CALL pg_temp.backfill_unique_names()');
        $this->addSql('DROP PROCEDURE pg_temp.backfill_unique_names()');
    }

    public function down(Schema $schema): void
    {
        // Not reversible: the original per-card names this overwrote were never recorded
        // anywhere, so there is nothing to restore them from.
    }
}

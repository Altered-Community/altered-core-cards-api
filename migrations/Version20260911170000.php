<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260911170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Merge the PERMANENT card_type into LANDMARK_PERMANENT — CardsData folded these into one type after review, but the merge was only ever applied to CardsData\'s own reference CSVs, never ported to this app\'s import pipeline';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DO $$
            DECLARE
                permanent_id INT;
                landmark_id  INT;
            BEGIN
                SELECT id INTO permanent_id FROM card_type WHERE reference = 'PERMANENT';
                SELECT id INTO landmark_id  FROM card_type WHERE reference = 'LANDMARK_PERMANENT';

                IF permanent_id IS NOT NULL AND landmark_id IS NOT NULL THEN
                    UPDATE card_group SET card_type_id = landmark_id WHERE card_type_id = permanent_id;
                    DELETE FROM card_type WHERE id = permanent_id;
                END IF;
            END $$
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Not a true revert — which card_group rows originally pointed at PERMANENT vs
        // LANDMARK_PERMANENT isn't recoverable. This only restores the reference itself.
        $this->addSql(<<<'SQL'
            INSERT INTO card_type (reference)
            SELECT 'PERMANENT'
            WHERE NOT EXISTS (SELECT 1 FROM card_type WHERE reference = 'PERMANENT')
        SQL);
    }
}

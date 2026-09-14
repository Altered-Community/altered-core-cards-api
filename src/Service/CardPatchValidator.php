<?php

namespace App\Service;

/**
 * Validates the structure of a card-patch JSON file before anything is written to the DB.
 *
 * A patch targets Card by reference (exact, or a single trailing "*" wildcard).
 * Fields are split in four whitelists because they don't live on the same entity,
 * or don't take the same shape of value:
 *  - CARD_FIELDS            live on Card itself, scalars, exact reference only.
 *  - CARD_GROUP_FIELDS      live on CardGroup (shared by every print/unique instance of
 *    a card), boolean-only status flags, settable via exact or wildcard reference.
 *  - LOCALIZED_CARD_FIELDS  live on Card's per-locale translation, value is an object
 *    {locale: text}, exact reference only (each print has its own translation).
 *  - LIST_CARD_GROUP_FIELDS live on CardGroup, value is an array of references applied
 *    as a full replacement, settable via exact or wildcard reference.
 */
final class CardPatchValidator
{
    public const CARD_FIELDS = [
        'collectorNumberFormatedId',
        'lowerPrice',
        'cardProduct',
        'isPublic',
        'isExclusive',
    ];

    public const CARD_GROUP_FIELDS = [
        'isBanned',
        'isSuspended',
        'isErrated',
    ];

    /** Card-level, localized text — exact reference only (each print has its own name). */
    public const LOCALIZED_CARD_FIELDS = [
        'name',
    ];

    /** CardGroup-level, list-valued — settable via exact or wildcard reference, full replacement. */
    public const LIST_CARD_GROUP_FIELDS = [
        'subTypes',
    ];

    public const VALID_LOCALES = ['fr_FR', 'en_US', 'de_DE', 'es_ES', 'it_IT'];

    /** @return string[] error messages; empty means the file is valid */
    public function validate(mixed $data, string $filename): array
    {
        if (!is_array($data)) {
            return ['Le fichier ne contient pas un objet JSON valide.'];
        }

        $errors = [...$this->validateDate($data, $filename), ...$this->validateDescription($data)];

        if (empty($data['updates']) || !is_array($data['updates'])) {
            $errors[] = 'Champ "updates" manquant ou vide.';

            return $errors;
        }

        foreach (array_values($data['updates']) as $index => $update) {
            $errors = [...$errors, ...$this->validateUpdate($update, $index)];
        }

        return $errors;
    }

    /** @return string[] */
    private function validateDate(array $data, string $filename): array
    {
        $date = $data['date'] ?? null;
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['Champ "date" manquant ou invalide (format attendu YYYY-MM-DD).'];
        }

        if (!str_starts_with($filename, $date . '__')) {
            return [sprintf(
                'La date "%s" ne correspond pas au préfixe du nom de fichier "%s".',
                $date,
                $filename,
            )];
        }

        return [];
    }

    /** @return string[] */
    private function validateDescription(array $data): array
    {
        if (empty($data['description']) || !is_string($data['description'])) {
            return ['Champ "description" manquant ou invalide.'];
        }

        return [];
    }

    /** @return string[] */
    private function validateUpdate(mixed $update, int $index): array
    {
        if (!is_array($update)) {
            return [sprintf('updates[%d] : doit être un objet.', $index)];
        }

        $errors = [];

        $reference  = $update['reference'] ?? null;
        $isWildcard = false;

        if (!is_string($reference) || trim($reference) === '') {
            $errors[] = sprintf('updates[%d] : "reference" manquante ou invalide.', $index);
        } else {
            if (!preg_match('/^[A-Z0-9_]+\*?$/', $reference)) {
                $errors[] = sprintf('updates[%d] : "reference" contient des caractères invalides.', $index);
            }

            $starCount  = substr_count($reference, '*');
            $isWildcard = $starCount === 1;

            if ($starCount > 1) {
                $errors[] = sprintf('updates[%d] : un seul "*" est autorisé dans "reference".', $index);
            } elseif ($starCount === 1 && !str_ends_with($reference, '*')) {
                $errors[] = sprintf('updates[%d] : le "*" doit être en position finale de "reference".', $index);
            }
        }

        $fields = $update['fields'] ?? null;
        if (!is_array($fields) || empty($fields)) {
            $errors[] = sprintf('updates[%d] : "fields" manquant ou vide.', $index);

            return $errors;
        }

        $allowed = $isWildcard
            ? [...self::CARD_GROUP_FIELDS, ...self::LIST_CARD_GROUP_FIELDS]
            : [...self::CARD_FIELDS, ...self::CARD_GROUP_FIELDS, ...self::LOCALIZED_CARD_FIELDS, ...self::LIST_CARD_GROUP_FIELDS];

        foreach ($fields as $field => $value) {
            if (!in_array($field, $allowed, true)) {
                $errors[] = sprintf(
                    'updates[%d] : champ "%s" non autorisé%s.',
                    $index,
                    $field,
                    $isWildcard ? ' pour une référence wildcard' : '',
                );
                continue;
            }

            if (in_array($field, self::CARD_GROUP_FIELDS, true) && !is_bool($value)) {
                $errors[] = sprintf('updates[%d] : le champ "%s" doit être un booléen.', $index, $field);
            }

            if (in_array($field, self::LOCALIZED_CARD_FIELDS, true)) {
                $errors = [...$errors, ...$this->validateLocalizedField($field, $value, $index)];
            }

            if (in_array($field, self::LIST_CARD_GROUP_FIELDS, true)) {
                $errors = [...$errors, ...$this->validateListField($field, $value, $index)];
            }
        }

        return $errors;
    }

    /** @return string[] */
    private function validateLocalizedField(string $field, mixed $value, int $index): array
    {
        if (!is_array($value) || empty($value)) {
            return [sprintf('updates[%d] : le champ "%s" doit être un objet {locale: valeur} non vide.', $index, $field)];
        }

        $errors = [];
        foreach ($value as $locale => $text) {
            if (!in_array($locale, self::VALID_LOCALES, true)) {
                $errors[] = sprintf(
                    'updates[%d] : locale "%s" invalide pour "%s" (attendu : %s).',
                    $index, $locale, $field, implode(', ', self::VALID_LOCALES),
                );
            }
            if (!is_string($text)) {
                $errors[] = sprintf('updates[%d] : la valeur de "%s.%s" doit être une chaîne.', $index, $field, $locale);
            }
        }

        return $errors;
    }

    /** @return string[] */
    private function validateListField(string $field, mixed $value, int $index): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [sprintf('updates[%d] : le champ "%s" doit être une liste de références.', $index, $field)];
        }

        $errors = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                $errors[] = sprintf('updates[%d] : "%s" doit contenir uniquement des références non vides.', $index, $field);
            }
        }

        return $errors;
    }
}

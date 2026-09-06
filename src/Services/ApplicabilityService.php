<?php

declare(strict_types=1);

namespace Rgesn\Services;

/**
 * Détermine, à partir des réponses aux questions de filtrage (gating) d'une évaluation,
 * quels critères du référentiel peuvent être écartés automatiquement (non applicables)
 * sans avoir à poser une question dédiée pour chacun d'entre eux.
 *
 * Un critère associé à un ou plusieurs tags de filtrage devient automatiquement
 * "non applicable" dès qu'au moins un de ces tags a été répondu "non" (false).
 * Un critère répond "oui" à tous ses tags, ou n'a aucun tag associé : il reste applicable
 * et sera posé normalement dans le questionnaire.
 */
final class ApplicabilityService
{
    /**
     * @param array<string, string[]> $gatingTagsByCriteria code critère => tags requis
     * @param array<string, bool> $gatingAnswers tag => valeur répondue
     */
    public function __construct(
        private array $gatingTagsByCriteria,
        private array $gatingAnswers
    ) {
    }

    /**
     * @return string[] libellés des tags manquants encore nécessaires pour statuer sur ce critère
     */
    public function missingTagsFor(string $criteriaCode): array
    {
        $tags = $this->gatingTagsByCriteria[$criteriaCode] ?? [];

        return array_values(array_filter($tags, fn (string $tag) => !array_key_exists($tag, $this->gatingAnswers)));
    }

    /**
     * true si le critère est écarté automatiquement par les réponses de filtrage déjà connues.
     */
    public function isAutoNotApplicable(string $criteriaCode): bool
    {
        $tags = $this->gatingTagsByCriteria[$criteriaCode] ?? [];
        foreach ($tags as $tag) {
            if (($this->gatingAnswers[$tag] ?? true) === false) {
                return true;
            }
        }

        return false;
    }

    /**
     * true si toutes les questions de filtrage nécessaires pour statuer sur ce critère ont une réponse.
     */
    public function isDecidable(string $criteriaCode): bool
    {
        return $this->missingTagsFor($criteriaCode) === [];
    }

    /**
     * @param array<int, array<string, mixed>> $allCriteria
     * @return array<int, array<string, mixed>> uniquement les critères encore à poser
     *   (ni écartés automatiquement, ni déjà répondus)
     */
    public function remainingCriteria(array $allCriteria, array $existingAnswers): array
    {
        return array_values(array_filter($allCriteria, function (array $criterion) use ($existingAnswers) {
            $code = $criterion['code'];
            if (isset($existingAnswers[$code])) {
                return false;
            }
            if (!$this->isDecidable($code)) {
                return true;
            }

            return !$this->isAutoNotApplicable($code);
        }));
    }

    /**
     * @param string[] $gatingTagOrder ordre des tags tel que défini par gating_questions.position
     * @return string|null le prochain tag de filtrage à poser, ou null si tous ont une réponse
     */
    public function nextGatingTag(array $gatingTagOrder): ?string
    {
        foreach ($gatingTagOrder as $tag) {
            if (!array_key_exists($tag, $this->gatingAnswers)) {
                return $tag;
            }
        }

        return null;
    }
}

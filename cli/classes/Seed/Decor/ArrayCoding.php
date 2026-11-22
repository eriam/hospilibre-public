<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed\Decor;

use Ox\Cli\Seed\SeedException;

/**
 * Codage piloté par des DONNÉES, comme `ArrayCatalogue` l'est pour les unités.
 *
 * Un décor déclare trois tableaux ; la recherche est ici. Les deux premiers sont indépendants — un acte
 * peut être coté sans que son diagnostic le soit, et réciproquement — de sorte qu'un décor puisse coder
 * ce qu'il sait coder sans devoir tout couvrir d'un coup.
 *
 * LA FORME DES CODES EST CONTRÔLÉE À LA CONSTRUCTION, et non à l'écriture. C'est délibéré : un code mal
 * formé est refusé par le socle au moment du `store()`, c'est-à-dire après des dizaines de milliers
 * d'objets écrits, avec un message qui ne désigne pas le décor fautif. Le contrôle ici nomme la
 * spécialité et l'acte.
 */
final class ArrayCoding implements ClinicalCoding
{
    /**
     * Forme d'un code CCAM, telle que `CCodable::checkCodeCcam()` la contrôle.
     *
     * Reproduite ici plutôt que réutilisée : la constante du socle n'existe pas, le motif y est écrit
     * en clair dans la méthode.
     */
    private const CCAM_PATTERN = '/^[A-Z]{4}[0-9]{3}(-[0-9](-[0-9])?)?$/';

    /**
     * Forme d'un code CIM-10, telle que `CCodeSpec::checkProperty()` la contrôle — en plus strict.
     *
     * Le socle accepte `^[a-z][0-9x+.]{2,6}$` avec l'option insensible à la casse, donc aussi le point.
     * Le seeder l'interdit : le dictionnaire ne répond QUE sur la forme sans point (colonne `abbrev`),
     * et un code ponctué passerait le contrôle de forme pour se faire vider ensuite par
     * `CSejour::check()`, sans trace en ligne de commande.
     */
    private const CIM10_PATTERN = '/^[A-Z][0-9]{1,5}([+X][0-9])?$/';

    /** @var array<string, string> Libellé d'acte => code CCAM */
    private $ccam;

    /** @var array<string, string[]> Unité => codes CIM-10 d'admission */
    private $diagnoses;

    /** @var array<string, string[]> Libellé d'acte => codes CIM-10 qui le motivent */
    private $procedure_diagnoses;

    /** @var array<string, true> Libellés d'actes faisables en hospitalisation de jour */
    private $ambulatory;

    /**
     * @param array<string, string>   $ccam                Libellé d'acte => code CCAM
     * @param array<string, string[]> $diagnoses           Unité => codes CIM-10
     * @param array<string, string[]> $procedure_diagnoses Libellé d'acte => codes CIM-10
     * @param string[]                $ambulatory          Libellés d'actes faisables en journée
     *
     * @throws SeedException
     */
    public function __construct(
        array $ccam = [],
        array $diagnoses = [],
        array $procedure_diagnoses = [],
        array $ambulatory = []
    ) {
        foreach ($ccam as $procedure => $code) {
            if (!preg_match(self::CCAM_PATTERN, $code)) {
                throw new SeedException(
                    "Code CCAM mal formé pour « {$procedure} » : '{$code}'. Quatre lettres majuscules"
                    . ' puis trois chiffres sont attendus.'
                );
            }
        }

        foreach ($diagnoses as $speciality => $codes) {
            $this->assertCim10($codes, "l'unité '{$speciality}'");
        }

        foreach ($procedure_diagnoses as $procedure => $codes) {
            $this->assertCim10($codes, "l'acte « {$procedure} »");
        }

        $this->ccam                = $ccam;
        $this->diagnoses           = $diagnoses;
        $this->procedure_diagnoses = $procedure_diagnoses;
        $this->ambulatory          = array_fill_keys($ambulatory, true);
    }

    public function ccamFor(string $procedure): ?string
    {
        return $this->ccam[$procedure] ?? null;
    }

    /**
     * Un décor qui ne déclare AUCUN acte ambulatoire n'est pas un décor qui n'en fait pas : c'est un
     * décor qui ne se prononce pas. La contrainte est alors inactive plutôt que bloquante — sinon
     * déclarer un codage suffirait à rendre tout séjour de jour inopérable.
     */
    public function isAmbulatory(string $procedure): bool
    {
        return $this->ambulatory === [] || isset($this->ambulatory[$procedure]);
    }

    /**
     * @inheritDoc
     */
    public function diagnosesFor(string $speciality): array
    {
        return $this->diagnoses[$speciality] ?? [];
    }

    /**
     * @inheritDoc
     */
    public function diagnosesForProcedure(string $procedure): array
    {
        return $this->procedure_diagnoses[$procedure] ?? [];
    }

    /**
     * @param string[] $codes
     *
     * @throws SeedException
     */
    private function assertCim10(array $codes, string $where): void
    {
        if ($codes === []) {
            throw new SeedException("Liste de diagnostics vide pour {$where} : la retirer plutôt.");
        }

        foreach ($codes as $code) {
            if (!preg_match(self::CIM10_PATTERN, $code)) {
                throw new SeedException(
                    "Code CIM-10 mal formé pour {$where} : '{$code}'. Le dictionnaire ne répond que"
                    . " sur la forme sans point — « J189 » et non « J18.9 »."
                );
            }
        }
    }
}

<?php

/**
 * @package Hospilibre\Patients
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Patients\Services;

use DateInterval;
use DateTimeImmutable;
use Ox\Core\CMbObject;
use Ox\Core\CRequest;
use Ox\Core\CSQLDataSource;
use Hospilibre\Patients\CPatient;

class SourceIdentityFilesService
{
    /** @var string Motif ISO-8601 décrivant la durée de validité d'un justificatif, converti en DateInterval */
    private const VALIDITY_PERIOD = 'P5Y';

    /** @var DateTimeImmutable */
    private $date_time;

    /**
     * @param DateTimeImmutable|null $date_time
     */
    public function __construct(DateTimeImmutable $date_time = null)
    {
        // À défaut de borne fournie, on part de l'instant présent.
        $reference = $date_time ?? new DateTimeImmutable();

        // On recule de la période de validité pour obtenir la date pivot d'expiration.
        $this->date_time = $reference->sub(new DateInterval(self::VALIDITY_PERIOD));
    }

    /**
     * @param int $start
     * @param int $limit
     *
     * @return CPatient[]
     */
    public function getPatientsWithExpiredFiles(int $start = 0, int $limit = 20): array
    {
        $limit_clause = $limit > 0 ? "$start, $limit" : null;

        $result = (new CPatient())->loadList(
            [
                "EXISTS (" . $this->getFilesQuery() . ")",
                "NOT EXISTS (" . $this->getSejoursQuery() . ")",
                "NOT EXISTS (" . $this->getConsultationsQuery() . ")",
            ],
            'nom ASC, prenom ASC',
            $limit_clause,
            'patients.patient_id',
            [
                '`source_identite` AS i ON i.`patient_id` = patients.`patient_id`',
            ]
        );

        return is_array($result) ? $result : [];
    }

    public function countPatientsWithExpiredFiles(): int
    {
        $count = (new CPatient())->countList(
            [
                "EXISTS (" . $this->getFilesQuery() . ")",
                "NOT EXISTS (" . $this->getSejoursQuery() . ")",
                "NOT EXISTS (" . $this->getConsultationsQuery() . ")",
            ],
            null,
            [
                '`source_identite` AS i ON i.`patient_id` = `patients`.`patient_id`',
            ]
        );

        return $count === null ? 0 : (int)$count;
    }

    /**
     * @param CPatient[] $patients
     *
     * @return array
     */
    public function deleteExpiredIdentityFilesForPatients(array $patients): array
    {
        // Préchargement en masse des sources d'identité puis de leurs fichiers.
        $sources = CMbObject::massLoadBackRefs($patients, 'sources_identite');
        CMbObject::massLoadBackRefs($sources, 'files');

        $report = [
            'errors'  => [],
            'success' => 0,
        ];

        foreach ($patients as $patient) {
            foreach ($patient->loadRefsSourcesIdentite(false) as $source) {
                $source->loadRefJustificatif();

                $proof = $source->_ref_justificatif;
                if (!$proof || !$proof->_id) {
                    continue;
                }

                $error = $proof->delete();
                if ($error) {
                    $report['errors'][] = "{$patient->_view}: $error";
                    continue;
                }

                $report['success']++;

                // La source retenue comme identité principale bascule en statut PROV.
                if (
                    $patient->source_identite_id === $source->_id
                    && in_array($patient->status, ['VALI', 'QUAL'])
                ) {
                    $patient->status = 'PROV';
                    $patient->store();
                }

                $source->identity_proof_type_id = '';
                $source->store();
            }
        }

        return $report;
    }

    private function getFilesQuery(): string
    {
        return (new CRequest())
            ->addSelect('1')
            ->addTable('`files_hospilibre` AS f')
            ->addWhereClause('f.object_class', " = 'CSourceIdentite'")
            ->addWhereClause('f.object_id', " = i.`source_identite_id`")
            ->setLimit('0, 1')
            ->makeSelect();
    }

    private function getSejoursQuery(): string
    {
        $pivot = $this->date_time->format('Y-m-d');

        return (new CRequest())
            ->addSelect('1')
            ->addTable('`sejour` AS s')
            ->addWhereClause('s.patient_id', " = patients.`patient_id`")
            ->addWhereClause(null, "DATE(s.sortie)  > '" . $pivot . "'")
            ->setLimit('0, 1')
            ->makeSelect();
    }

    private function getConsultationsQuery(): string
    {
        $pivot = $this->date_time->format('Y-m-d');

        return (new CRequest())
            ->addSelect('1')
            ->addTable('`consultation` AS c')
            ->addLJoin('`plageconsult` AS pl ON pl.`plageconsult_id` = c.`plageconsult_id`')
            ->addWhereClause('c.patient_id', " = patients.`patient_id`")
            ->addWhereClause('pl.date', " > '" . $pivot . "'")
            ->setLimit('0, 1')
            ->makeSelect();
    }
}

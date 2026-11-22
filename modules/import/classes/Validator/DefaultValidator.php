<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Import\Framework\Validator;

use Ox\Core\Specification\SpecificationInterface;
use Ox\Import\Framework\Spec\ActeCCAMSpecBuilder;
use Ox\Import\Framework\Spec\ActeNGAPSpecBuilder;
use Ox\Import\Framework\Spec\AffectationSpecBuilder;
use Ox\Import\Framework\Spec\AntecedentSpecBuilder;
use Ox\Import\Framework\Spec\ConstanteSpecBuilder;
use Ox\Import\Framework\Spec\ConsultationAnesthSpecBuilder;
use Ox\Import\Framework\Spec\ConsultationSpecBuilder;
use Ox\Import\Framework\Spec\CorrespondantSpecBuilder;
use Ox\Import\Framework\Spec\DossierMedicalSpecBuilder;
use Ox\Import\Framework\Spec\EvenementPatientSpecBuilder;
use Ox\Import\Framework\Spec\FileSpecBuilder;
use Ox\Import\Framework\Spec\InjectionSpecBuilder;
use Ox\Import\Framework\Spec\MedecinSpecBuilder;
use Ox\Import\Framework\Spec\ObservationAbnormalFlagSpecBuilder;
use Ox\Import\Framework\Spec\ObservationIdentifierSpecBuilder;
use Ox\Import\Framework\Spec\ObservationResultSetSpecBuilder;
use Ox\Import\Framework\Spec\ObservationResultSpecBuilder;
use Ox\Import\Framework\Spec\ObservationResultValueSpecBuilder;
use Ox\Import\Framework\Spec\ObservationValueUnitSpecBuilder;
use Ox\Import\Framework\Spec\OperationSpecBuilder;
use Ox\Import\Framework\Spec\PatientSpecBuilder;
use Ox\Import\Framework\Spec\PlageConsultSpecBuilder;
use Ox\Import\Framework\Spec\SejourSpecBuilder;
use Ox\Import\Framework\Spec\TraitementSpecBuilder;
use Ox\Import\Framework\Spec\UserSpecBuilder;
use Ox\Import\Framework\Spec\VaccinationSpecBuilder;

/**
 * Description
 */
class DefaultValidator extends AbstractValidator
{
    /**
     * @inheritDoc
     */
    protected function getExternalUserSpec(): ?SpecificationInterface
    {
        return (new UserSpecBuilder())->build();
    }

    /**
     * @inheritDoc
     */
    public function getExternalPatientSpec(): ?SpecificationInterface
    {
        return (new PatientSpecBuilder())->build();
    }

    /**
     * @inheritDoc
     */
    protected function getExternalMedecinSpec(): ?SpecificationInterface
    {
        return (new MedecinSpecBuilder())->build();
    }

    /**
     * @inheritDoc
     */
    protected function getExternalPlageConsultSpec(): ?SpecificationInterface
    {
        return (new PlageConsultSpecBuilder())->build();
    }

    /**
     * @inheritDoc
     */
    protected function getExternalConsultationSpec(): ?SpecificationInterface
    {
        return (new ConsultationSpecBuilder())->build();
    }

    /**
     * @inheritDoc
     */
    protected function getConsultationAnesthSpec(): ?SpecificationInterface
    {
        return (new ConsultationAnesthSpecBuilder())->build();
    }

    protected function getExternalSejourSpec(): ?SpecificationInterface
    {
        return (new SejourSpecBuilder())->build();
    }

    protected function getExternalFileSpec(): ?SpecificationInterface
    {
        return (new FileSpecBuilder())->build();
    }

    protected function getExternalAntecedentSpec(): ?SpecificationInterface
    {
        return (new AntecedentSpecBuilder())->build();
    }

    protected function getExternalTraitementSpec(): ?SpecificationInterface
    {
        return (new TraitementSpecBuilder())->build();
    }

    protected function getExternalCorrespondantSpec(): ?SpecificationInterface
    {
        return (new CorrespondantSpecBuilder())->build();
    }

    protected function getExternalEvenementPatientSpec(): ?SpecificationInterface
    {
        return (new EvenementPatientSpecBuilder())->build();
    }

    protected function getExternalVaccinationSpec(): ?SpecificationInterface
    {
        return (new VaccinationSpecBuilder())->build();
    }

    protected function getExternalInjectionSpec(): ?SpecificationInterface
    {
        return (new InjectionSpecBuilder())->build();
    }

    protected function getExternalActeCCAMSpec(): ?SpecificationInterface
    {
        return (new ActeCCAMSpecBuilder())->build();
    }

    protected function getExternalActeNGAPSpec(): ?SpecificationInterface
    {
        return (new ActeNGAPSpecBuilder())->build();
    }

    protected function getExternalConstanteSpec(): ?SpecificationInterface
    {
        return (new ConstanteSpecBuilder())->build();
    }

    protected function getExternalDossierMedicalSpec(): ?SpecificationInterface
    {
        return (new DossierMedicalSpecBuilder())->build();
    }

    protected function getExternalAffectationSpec(): ?SpecificationInterface
    {
        return (new AffectationSpecBuilder())->build();
    }

    protected function getExternalOperationSpec(): ?SpecificationInterface
    {
        return (new OperationSpecBuilder())->build();
    }

    protected function getExternalObservationResultSpec(): ?SpecificationInterface
    {
        return (new ObservationResultSpecBuilder())->build();
    }

    protected function getExternalObservationIdentifierSpec(): ?SpecificationInterface
    {
        return (new ObservationIdentifierSpecBuilder())->build();
    }

    protected function getExternalObservationResultValueSpec(): ?SpecificationInterface
    {
        return (new ObservationResultValueSpecBuilder())->build();
    }

    protected function getExternalObservationResultSetSpec(): ?SpecificationInterface
    {
        return (new ObservationResultSetSpecBuilder())->build();
    }

    protected function getExternalObservationAbnormalFlagSpec(): ?SpecificationInterface
    {
        return (new ObservationAbnormalFlagSpecBuilder())->build();
    }

    protected function getExternalObservationValueUnitSpec(): ?SpecificationInterface
    {
        return (new ObservationValueUnitSpecBuilder())->build();
    }
}

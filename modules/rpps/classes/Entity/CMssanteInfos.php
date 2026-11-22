<?php
/**
 * @package Hospilibre\Rpps
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Import\Rpps\Entity;

use Exception;
use Ox\Core\CMbObjectSpec;
use Ox\Core\CMbString;
use Hospilibre\Patients\CMedecin;
use Hospilibre\Patients\CMedecinExercicePlace;

/**
 * Description
 */
class CMssanteInfos extends CAbstractExternalRppsObject
{
    /** @var int */
    public $mssante_info_id;

    /** @var string */
    public $type_bal;

    /** @var string */
    public $email;

    /** @var int */
    public $type_id_structure;

    /** @var string */
    public $id_structure;

    /** @var string */
    public $service_rattachement;

    /** @var string */
    public $civilite;

    /** @var string */
    public $categorie_profession;

    /** @var string */
    public $libelle_categorie_profession;

    /** @var string */
    public $code_profession;

    /** @var string */
    public $libelle_profession;

    /** @var string */
    public $code_savoir_faire;

    /** @var string */
    public $libelle_savoir_faire;

    /** @var string */
    public $dematerialisation;

    /** @var string */
    public $raison_sociale_bal;

    /**
     * @inheritdoc
     */
    public function getSpec(): CMbObjectSpec
    {
        $spec        = parent::getSpec();
        $spec->table = "mssante_info";
        $spec->key   = "mssante_info_id";

        return $spec;
    }

    /**
     * @inheritdoc
     */
    public function getProps(): array
    {
        $props = parent::getProps();

        $props['type_bal']                     = 'enum list|ORG|PER notNull';
        $props['email']                        = 'email notNull';
        $props['type_id_structure']            = 'str';
        $props['id_structure']                 = 'str';
        $props['service_rattachement']         = 'str';
        $props['civilite']                     = 'str';
        $props['categorie_profession']         = 'str';
        $props['libelle_categorie_profession'] = 'str';
        $props['code_profession']              = 'str';
        $props['libelle_profession']           = 'str';
        $props['code_savoir_faire']            = 'str';
        $props['libelle_savoir_faire']         = 'str';
        $props['dematerialisation']            = 'enum list|O|N';
        $props['raison_sociale_bal']           = 'str';

        return $props;
    }

    /**
     * @param CMedecinExercicePlace $medecin_exercice_place
     *
     * @return string|CMedecinExercicePlace
     * @throws Exception
     */
    public function synchronizeMssante(?CMedecinExercicePlace $medecin_exercice_place = null)
    {
        if (!$medecin_exercice_place) {
            return null;
        }

        $addresses = explode("\n", $medecin_exercice_place->mssante_address);

        if (!in_array($this->email, $addresses)) {
            $addresses[] = CMbString::lower($this->email);

            $medecin_exercice_place->mssante_address = implode("\n", $addresses);

            if ($msg = $medecin_exercice_place->store()) {
                return $msg;
            }
        }

        return $medecin_exercice_place;
    }

    public function synchronize(?CMedecin $medecin = null): CMedecin
    {
        return $medecin ?? new CMedecin();
    }

    /**
     * Comparison of objects (for PHPUnit assertions).
     * This is for the manual import of correspondant purpose
     *
     * @param CMssanteInfos $other
     *
     * @return bool
     */
    public function equals(self $other): bool
    {
        return (
            $this->email === $other->email
        );
    }
}

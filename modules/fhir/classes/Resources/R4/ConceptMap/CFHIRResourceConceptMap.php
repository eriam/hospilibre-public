<?php

/**
 * @package Hospilibre\Fhir
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Fhir\Resources\R4\ConceptMap;

use DOMDocument;
use DOMNode;
use Ox\Core\CAppUI;
use Ox\Core\CMbArray;
use Ox\Core\CStoredObject;
use Ox\Interop\Fhir\Actors\CReceiverFHIR;
use Ox\Interop\Fhir\CFHIRXPath;
use Ox\Interop\Fhir\Contracts\Resources\ResourceConceptMapInterface;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeCode;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeDateTime;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeMarkdown;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeString;
use Ox\Interop\Fhir\Datatypes\CFHIRDataTypeUri;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\ConceptMap\CFHIRDataTypeConceptMapElement;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\ConceptMap\CFHIRDataTypeConceptMapGroup;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\ConceptMap\CFHIRDataTypeConceptMapTarget;
use Ox\Interop\Fhir\Datatypes\Complex\Backbone\ConceptMap\CFHIRDataTypeConceptMapUnmapped;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeIdentifier;
use Ox\Interop\Fhir\Event\CFHIREvent;
use Ox\Interop\Fhir\Exception\CFHIRException;
use Ox\Interop\Fhir\Resources\CFHIRDomainResource;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Phast\CAideSaisieConceptMapLiaison;
use Hospilibre\CompteRendu\CAideSaisie;
use Hospilibre\Etablissement\CGroups;
use Hospilibre\Mediusers\CFunctions;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\Sante400\CIdSante400;

/**
 * FIHR patient resource
 */
class CFHIRResourceConceptMap extends CFHIRDomainResource implements ResourceConceptMapInterface
{
    /** @var string */
    public const RESOURCE_TYPE = 'ConceptMap';

    public const URL_SNOMED      = "http://snomed.info/sct";
    public const URL_CIM10       = "https://www.atih.sante.fr/cim-10";
    public const TAG_CODE_SNOMED = "code_snomed";
    public const TAG_CODE_CIM10  = "code_cim10";

    /** @var CFHIRDataTypeUri */
    public $url;

    /** @var CFHIRDataTypeIdentifier */
    public $identifier;

    /** @var CFHIRDataTypeString */
    public $version;

    /** @var CFHIRDataTypeString */
    public $name;

    /** @var CFHIRDataTypeString */
    public $title;

    /** @var CFHIRDataTypeCode */
    public $status;

    /** @var CFHIRDataTypeDateTime */
    public $date;

    /** @var CFHIRDataTypeString */
    public $publisher;

    /** @var CFHIRDataTypeMarkdown */
    public $description;

    /** @var CFHIRDataTypeMarkdown */
    public $purpose;

    /** @var array */
    public $source = [];

    /** @var array */
    public $target = [];

    /** @var CFHIRDataTypeConceptMapGroup[] */
    public $group;

    /** @var CFHIRDataTypeConceptMapUnmapped */
    public $unmapped;

    /**
     * @inheritdoc
     */
    public function build(CStoredObject $object, CFHIREvent $event): void
    {
        parent::build($object, $event);

        if (!$object instanceof CAideSaisie) {
            throw  new CFHIRException("Object is not aide saisie");
        }

        // Cas d'un ajout d'un code dans le conceptMap, on met donc l'identifiant de la resource du serveur
        if ($object->_ref_concept_map->_id) {
            $this->id = $object->_ref_concept_map->identifier_concept_map;
        }

        $group = $this->getGroupAideSaisie($object);

        $resource = new CFHIRResourceConceptMap();

        $url             = "http://" . CAppUI::conf("mb_oid") . "/" . CAppUI::conf("product_name") . "/"
            . $object->class . "-" . $object->field;
        $this->url       = new CFHIRDataTypeString($url);
        $this->status    = "draft";
        $this->name      = new CFHIRDataTypeString(CAppUI::tr("$object->class-$object->field"));
        $this->publisher = new CFHIRDataTypeString($group->_view);
        $this->purpose   = new CFHIRDataTypeString("__Objet__:" . $object->class . "|__Champ__:" . $object->field);

        $codes = [];
        if ($object->_ref_concept_map->_id) {
            $aide_saisie_liaison             = new CAideSaisieConceptMapLiaison();
            $where                           = [];
            $where["identifier_concept_map"] = " = '" . $object->_ref_concept_map->_id . "' ";
            $aide_saisie_liaisons            = $aide_saisie_liaison->loadList(
                ["identifier_concept_map" => " = '" . $object->_ref_concept_map->identifier_concept_map . "' "]
            );

            $elements_snomed = $elements_cim10 = [];
            // On ajoute les codes qui sont déjÎ° dans le conceptMap
            foreach ($aide_saisie_liaisons as $_aide_saisie_liaison) {
                $aide_saisie = $_aide_saisie_liaison->loadRefAideSaisie();
                $display     = "__Libelle__:$aide_saisie->name|__Description__:$aide_saisie->text";

                // Récupération des codes que l'on a déjÎ° récupérés
                $idex_cim10  = CIdSante400::getMatchFor($aide_saisie, self::TAG_CODE_CIM10);
                $idex_snomed = CIdSante400::getMatchFor($aide_saisie, self::TAG_CODE_SNOMED);

                $elements_snomed = $idex_snomed->_id
                    ? $this->addElementWithTarget($elements_snomed, $aide_saisie->_id, $display, $idex_snomed)
                    : $this->addElement($elements_snomed, $aide_saisie->_id, $display);

                $elements_cim10 = $idex_cim10->_id
                    ? $this->addElementWithTarget($elements_cim10, $aide_saisie->_id, $display, $idex_cim10)
                    : $this->addElement($elements_cim10, $aide_saisie->_id, $display);
            }

            $display         = "__Libelle__:$object->name|__Description__:$object->text";
            $elements_snomed = $this->addElement($elements_snomed, $object->_id, $display);
            $elements_cim10  = $this->addElement($elements_cim10, $object->_id, $display);

            // Ajout des elements dans le Group SNOMED
            $this->group[] = CFHIRDataTypeConceptMapGroup::build(
                [
                    "source"  => new CFHIRDataTypeString("$object->class-$object->field"),
                    "target"  => self::URL_SNOMED,
                    "element" => $elements_snomed,
                ]
            );

            // Ajout des elements dans le Group CIM10
            $this->group[] = CFHIRDataTypeConceptMapGroup::build(
                [
                    "source"  => new CFHIRDataTypeString("$object->class-$object->field"),
                    "target"  => self::URL_CIM10,
                    "element" => $elements_cim10,
                ]
            );
        } else {
            $display = "__Libelle__:$object->name|__Description__:$object->text";

            $codes[] = [
                "code"    => new CFHIRDataTypeCode($object->_id),
                "display" => new CFHIRDataTypeString($display),
            ];

            $this->group[] = CFHIRDataTypeConceptMapGroup::build(
                [
                    "source"  => new CFHIRDataTypeString("$object->class-$object->field"),
                    "target"  => self::URL_SNOMED,
                    "element" => CFHIRDataTypeConceptMapElement::build(
                        [
                            "code"    => new CFHIRDataTypeCode($object->_id),
                            "display" => new CFHIRDataTypeString($display),
                        ]
                    ),
                ]
            );

            $this->group[] = CFHIRDataTypeConceptMapGroup::build(
                [
                    "source"  => new CFHIRDataTypeString("$object->class-$object->field"),
                    "target"  => self::URL_CIM10,
                    "element" => CFHIRDataTypeConceptMapElement::build(
                        [
                            "code"    => new CFHIRDataTypeCode($object->_id),
                            "display" => new CFHIRDataTypeString($display),
                        ]
                    ),
                ]
            );
        }
    }

    /**
     * Add element
     *
     * @param array  $element
     * @param string $code
     * @param string $display
     *
     * @return array
     */
    public function addElement(array $element, string $code, string $display): array
    {
        $element[] = [
            "element" => CFHIRDataTypeConceptMapElement::build(
                [
                    "code"    => new CFHIRDataTypeCode($code),
                    "display" => new CFHIRDataTypeString($display),
                ]
            ),
        ];

        return $element;
    }

    /**
     * @param array       $element
     * @param string      $code
     * @param string      $display
     * @param CIdSante400 $idex
     *
     * @return array
     */
    public function addElementWithTarget(array $element, string $code, string $display, CIdSante400 $idex): array
    {
        $datas = explode("|", $idex->id400);

        $element[] = [
            "element" => CFHIRDataTypeConceptMapElement::build(
                [
                    "code"    => new CFHIRDataTypeCode($code),
                    "display" => new CFHIRDataTypeString($display),
                    "target"  => [
                        CFHIRDataTypeConceptMapTarget::build(
                            [
                                "code"        => new CFHIRDataTypeString(CMbArray::get($datas, 0)),
                                "display"     => new CFHIRDataTypeString(CMbArray::get($datas, 1)),
                                "equivalence" => new CFHIRDataTypeString(CMbArray::get($datas, 2)),
                            ]
                        ),
                    ],
                ]
            ),
        ];

        return $element;
    }

    /**
     * Get group
     *
     * @param CAideSaisie $aide_saisie aide saisie
     *
     * @return CGroups
     */
    public function getGroupAideSaisie(CAideSaisie $aide_saisie): CGroups
    {
        $group_id = null;
        // Récupération de l'établissement directement de l'aide Î° la saisie
        $group_id = $aide_saisie->group_id ? $aide_saisie->group_id : null;

        // Récupération de l'établissement Î° partir de la fonction de l'aide Î° la saisie
        if (!$group_id) {
            if ($aide_saisie->function_id) {
                $function = new CFunctions();
                $function->load($aide_saisie->function_id);
                $group_id = $function->loadRefGroup()->_id;
            }
        }

        // Récupération de l'établissement Î° partir de l'utilisateur de l'aide Î° la saisie
        if (!$group_id) {
            if ($aide_saisie->user_id) {
                $user = new CMediusers();
                $user->load($aide_saisie->user_id);

                $group_id = $user->loadRefFunction()->loadRefGroup()->_id;
            }
        }

        // Dans le pire des cas, on prend l'établissement courant
        if (!$group_id) {
            $group_id = CGroups::loadCurrent()->_id;
        }

        $group = new CGroups();
        $group->load($group_id);

        return $group;
    }

    /**
     * Mapping ConceptMap resource to create CAideSaisieConceptMapLiaison
     *
     * @param CFHIRXPath    $xpath             xpath
     * @param DOMNode       $node_doc_manifest node doc manifest
     * @param CFHIRResource $resource          resource
     *
     * @return CAideSaisieConceptMapLiaison
     * @throws CFHIRException
     */
    public static function mapping(
        DOMDocument $dom,
        CAideSaisie $aide_saisie,
        CReceiverFHIR $receiver_fhir
    ): CAideSaisieConceptMapLiaison {
        $xpath = new CFHIRXPath($dom);

        $liaison_aide_saisie                 = new CAideSaisieConceptMapLiaison();
        $liaison_aide_saisie->aide_saisie_id = $aide_saisie->_id;
        $liaison_aide_saisie->class          = $aide_saisie->class;
        $liaison_aide_saisie->field          = $aide_saisie->field;
        $liaison_aide_saisie->loadMatchingObject();

        if ($liaison_aide_saisie->_id) {
            throw new CFHIRException("Link with concept map always exist");
        }

        //$liaison_aide_saisie->url                    = $receiver_fhir->_source->_location_resource;
        $node_concept_map                            = $xpath->query("fhir:ConceptMap", $dom)->item(0);
        $liaison_aide_saisie->identifier_concept_map = $xpath->getAttributeValue("fhir:id", $node_concept_map);
        $liaison_aide_saisie->store();

        return $liaison_aide_saisie;
    }

    /**
     * MappingCodes
     *
     * @param string $response
     * @param int    $concept_map_id
     *
     * @throws \Exception
     */
    public static function mappingCodes(string $response, int $concept_map_id): void
    {
        $dom = new DOMDocument();
        $dom->loadXML($response);
        $xpath = new CFHIRXPath($dom);

        // On enlÎ¸ve tous les codes existants et on remplace par les nouveaux (comme Î·a gestion de la modification)
        self::deleteCodes($concept_map_id);

        $nodes_group = $xpath->query("fhir:ConceptMap/fhir:group", $dom);
        foreach ($nodes_group as $_node_group) {
            $terminologie = $xpath->getAttributeValue("fhir:target", $_node_group);

            if ($terminologie != self::URL_CIM10 && $terminologie != self::URL_SNOMED) {
                continue;
            }

            $nodes_element = $xpath->query("fhir:element", $_node_group);
            if (!$nodes_element) {
                continue;
            }

            foreach ($nodes_element as $_node_element) {
                $aide_saisie_id = $xpath->getAttributeValue("fhir:code", $_node_element);
                $aide_saisie    = new CAideSaisie();
                $aide_saisie->load($aide_saisie_id);
                if (!$aide_saisie->_id) {
                    continue;
                }

                $targets_node = $xpath->query("fhir:target", $_node_element);
                if (!$targets_node) {
                    continue;
                }

                foreach ($targets_node as $_target_node) {
                    $value = $xpath->getAttributeValue(
                            "fhir:code",
                            $_target_node
                        ) . "|" .
                        $xpath->getAttributeValue("fhir:display", $_target_node) . "|" .
                        $xpath->getAttributeValue("fhir:equivalence", $_target_node);
                    $idex  = CIdSante400::getMatch(
                        $aide_saisie->_class,
                        self::getTagIdex($terminologie),
                        $value,
                        $aide_saisie->_id
                    );
                    $idex->store();
                }
            }
        }
    }

    /**
     * Delete all codes CIM10 and SNOMED for an concept map
     *
     * @param $concept_map_id
     *
     * @throws \Exception
     */
    public static function deleteCodes(int $concept_map_id): void
    {
        $aide_saisie_liaison  = new CAideSaisieConceptMapLiaison();
        $aide_saisie_liaisons = $aide_saisie_liaison->loadList(
            ["identifier_concept_map" => " = '$concept_map_id' "]
        );

        foreach ($aide_saisie_liaisons as $_aide_saisie_liaison) {
            $aide_saisie = $_aide_saisie_liaison->loadRefAideSaisie();

            $idex                  = new CIdSante400();
            $where                 = [];
            $where["object_class"] = " = '$aide_saisie->_class' ";
            $where["object_id"]    = " = '$aide_saisie->_id' ";
            $where[]               = " tag = '" . self::TAG_CODE_SNOMED . "' OR tag = '" . self::TAG_CODE_CIM10 . "' ";
            foreach ($idex->loadList($where) as $_idex) {
                $_idex->purge();
            }
        }
    }

    /**
     * Get tag idex for terminologie name
     *
     * @param string $terminologie terminologie
     *
     * @return null|string
     */
    public static function getTagIdex(string $terminologie): ?string
    {
        switch ($terminologie) {
            case self::URL_SNOMED:
                return self::TAG_CODE_SNOMED;
            case self::URL_CIM10:
                return self::TAG_CODE_CIM10;
            default:
                return null;
        }
    }
}

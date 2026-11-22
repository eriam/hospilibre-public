<?php

/**
 * @package Ox\Interop\Fhir
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Fhir\Utilities;

use Ox\Interop\Fhir\Datatypes\CFHIRDataType;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeChoice;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeComplex;
use Ox\Interop\Fhir\Datatypes\Complex\CFHIRDataTypeExtension;
use Ox\Interop\Fhir\Resources\CFHIRResource;
use Ox\Interop\Fhir\Resources\R4\CFHIRDefinition;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Boîte à outils partagée pour la manipulation des ressources et types FHIR.
 */
trait CFHIRTools
{
    /**
     * Retourne les champs renseignés (non nuls, non vides) d'une ressource ou d'un type FHIR.
     *
     * @param CFHIRResource|CFHIRDataType $resource
     *
     * @return array
     * @throws InvalidArgumentException
     */
    public static function getNonEmptyFields($resource): array
    {
        // On n'accepte que des ressources ou des datatypes FHIR : sinon rien à extraire.
        if (!is_object($resource)) {
            return [];
        }

        $isResource = is_subclass_of($resource, CFHIRResource::class);
        $isDatatype = !$isResource && is_subclass_of($resource, CFHIRDataType::class);

        if (!$isResource && !$isDatatype) {
            return [];
        }

        $fieldNames = $resource->isSummary()
            ? CFHIRDefinition::getSummariesFields($resource)
            : CFHIRDefinition::getFields($resource);

        $retained = [];

        foreach ($fieldNames as $fieldName) {
            // La propriété doit exister sur l'objet.
            if (!property_exists($resource, $fieldName)) {
                continue;
            }

            /** @var CFHIRDataType $value */
            $value = $resource->{$fieldName};

            // Tableau vide : aucune donnée exploitable.
            if (is_array($value) && count($value) === 0) {
                continue;
            }

            // Doit être renseigné, et soit un CFHIRDataType, soit un tableau.
            if (
                !$resource->{$fieldName}
                || (!$resource->{$fieldName} instanceof CFHIRDataType && !is_array($value))
            ) {
                continue;
            }

            if (is_array($value)) {
                // On écarte les datatypes nuls du tableau.
                $value = array_filter($value, static function (CFHIRDataType $datatype) {
                    return !$datatype->isNull();
                });

                if (empty($value)) {
                    continue;
                }
            } elseif ($value->isNull()) {
                continue;
            }

            $retained[$fieldName] = $value;
        }

        // Résolution des types "choice" : le nom du champ est suffixé par celui du type retenu.
        $result = [];

        foreach ($retained as $fieldName => $value) {
            if ($value instanceof CFHIRDataTypeChoice) {
                unset($retained[$fieldName]);
                $value     = $value->getValue();
                $fieldName .= $value::NAME;
            }

            $result[$fieldName] = $value;
        }

        return $result;
    }

    /**
     * Sérialise en JSON un tableau de datatypes rattachés à un même champ.
     *
     * @param array  $datatypes
     * @param string $field
     *
     * @return array
     */
    public static function manageDatatypeJSONArray(array $datatypes, string $field): array
    {
        $shadowKey = "_$field";
        $result    = [];

        /** @var CFHIRDataType $datatype */
        foreach ($datatypes as $datatype) {
            $encoded = $datatype->toJSON($field);

            // Un type complexe (hors extension) n'a pas de représentation "shadow" (_field).
            $isComplex = $datatype instanceof CFHIRDataTypeComplex
                && !$datatype instanceof CFHIRDataTypeExtension;

            if ($isComplex) {
                $result[$field][] = $encoded[$field] ?? null;
            } else {
                $result[$field][]     = $encoded[$field] ?? null;
                $result[$shadowKey][] = $encoded[$shadowKey] ?? null;
            }
        }

        // On retire les entrées qui ne contiennent que des valeurs nulles.
        if (count(array_filter($result[$shadowKey] ?? [])) === 0) {
            unset($result[$shadowKey]);
        }

        if (count(array_filter($result[$field] ?? [])) === 0) {
            unset($result[$field]);
        }

        return $result;
    }
}

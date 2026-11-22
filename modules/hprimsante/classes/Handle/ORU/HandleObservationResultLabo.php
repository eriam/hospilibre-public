<?php

/**
 * @package Hospilibre\Hprimsante
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Interop\Hprimsante\Handle\ORU;

use DOMNode;
use Exception;
use Ox\Core\CMbDT;
use Ox\Core\CMbException;
use Ox\Interop\Hl7\CHL7v2Exception;
use Ox\Interop\Hprimsante\Exceptions\CHPrimSanteExceptionWarning;
use Hospilibre\ObservationResult\CObservationIdentifier;
use Hospilibre\ObservationResult\CObservationResult;
use Hospilibre\ObservationResult\CObservationResultExamen;
use Hospilibre\ObservationResult\CObservationResultValue;
use Hospilibre\ObservationResult\CObservationValueUnit;
use Hospilibre\ObservationResult\Interop\ObservationResultLocator;

class HandleObservationResultLabo extends HandleObservationResult
{
    /** @var string[] */
    private const SUPPORTED_STATUS = ['R', 'P', 'F', 'I', 'D', 'X', 'C', 'U'];

    /** @var DOMNode */
    protected $OBX_node;

    /**
     * @param array $params
     *
     * @throws CHL7v2Exception
     * @throws Exception
     */
    public function handle(array $params): void
    {
        $this->params = $params;

        $this->observation_result_set = $observation_result_set =
            $params[HandleObservation::KEY_OBSERVATION_RESULT_SET] ?? null;
        // si mode sas && !CObservationResultSet just skip integration
        if (!$observation_result_set && $this->isModeSAS()) {
            return;
        } elseif (!$observation_result_set) { // else if mode sas not active, stop integration
            throw $this->makeError('OBR', '19', '10.1');
        }

        if (!$this->OBX_node = $OBX_node = ($params[self::KEY_OBX_NODE] ?? null)) {
            throw $this->makeWarning('OBX', '22', '10.1');
        }

        $sub_identifier = $this->message->queryTextNode('OBX.4', $OBX_node);
        $identifier     = $this->mapIdentifier();

        try {
            $observation_result = (new ObservationResultLocator($observation_result_set))
                ->findOrNew($identifier, $sub_identifier);
        } catch (CMbException $exception) {
            throw $this->makeWarning('OBX', '22', '10.1', $exception->getMessage());
        }

        // Always update status
        $this->mapRequiredElements($observation_result);

        $this->mapResultExamen($observation_result);

        if ($msg = $observation_result->store()) {
            throw $this->makeWarning('OBX', '22', '10.1', $msg);
        }

        $observation_unit = $this->mapUnit();

        $this->mapResult($observation_result, $observation_unit);
    }

    /**
     * @param CObservationResult         $observation_result
     * @param CObservationValueUnit|null $observation_unit
     *
     * @throws CHPrimSanteExceptionWarning
     */
    protected function mapResult(
        CObservationResult $observation_result,
        ?CObservationValueUnit $observation_unit = null
    ): void {

        $OBX_nodes = $this->message->queryNodes('OBX.5', $this->OBX_node);
        $content = '';
        foreach ($OBX_nodes as $node) {
            $content .= ($content ? "\n" : '') . $this->message->queryTextNode('.', $node);
        }

        $content = $this->parseContent($content);

        $observation_value                        = new CObservationResultValue();
        $observation_value->value                 = $content;
        $observation_value->unit_id               = $observation_unit ? $observation_unit->_id : null;
        $observation_value->observation_result_id = $observation_result->_id;

        if ($reference_range = $this->message->queryTextNode('OBX.7', $this->OBX_node)) {
            if (str_starts_with($reference_range, '-')) {
                $high = substr($reference_range, 1);
            } elseif (str_ends_with($reference_range, '-')) {
                $low = substr($reference_range, 0, -1);
            } else {
                if (strpos($reference_range, '-') !== false) {
                    [$low, $high] = explode('-', $reference_range, 2);
                } else {
                    // InfÎ°
                    $high = $reference_range;
                }
            }

            $observation_value->setReferenceRange($low ?? null, $high ?? null);
        }

        if (!$observation_value->loadMatchingObjectEsc()) {
            if ($msg = $observation_value->store()) {
                throw $this->makeWarning('OBX', '22', '10.6', $msg);
            }
        }
    }

    /**
     * @param CObservationResult $observation_result
     *
     * @throws CHPrimSanteExceptionWarning
     */
    protected function mapRequiredElements(CObservationResult $observation_result): void
    {
        $status = $this->message->queryTextNode('OBX.11', $this->OBX_node);
        if (!in_array($status, self::SUPPORTED_STATUS)) {
            throw $this->makeWarning('OBX', '21', 'OBX.11');
        }

        // status (OBX.11)
        $observation_result->status = $status;

        // datetime (OBX.14)
        if ($datetime = $this->message->queryTextNode("OBX.14", $this->OBX_node)) {
            $observation_result->datetime = CMbDT::dateTime($datetime);
        }
    }

    /**
     * @return CObservationIdentifier
     * @throws Exception
     */
    protected function mapIdentifier(): CObservationIdentifier
    {
        $id            = $this->message->queryTextNode("OBX.3/CE.1", $this->OBX_node);
        $coding_system = $this->message->queryTextNode("OBX.3/CE.3", $this->OBX_node);
        $text          = $this->message->queryTextNode("OBX.3/CE.2", $this->OBX_node);

        if (!$id || !$coding_system) {
            throw $this->makeWarning('OBX', "22", '10.4');
        }

        $identifier                    = new CObservationIdentifier();
        $identifier->identifier        = $id;
        $identifier->text              = $text ?: $id;
        $identifier->coding_system     = $coding_system;
        $identifier->alt_identifier    = $this->message->queryTextNode("OBX.3/CE.4", $this->OBX_node) ?: null;
        $identifier->alt_text          = $this->message->queryTextNode("OBX.3/CE.5", $this->OBX_node) ?: null;
        $identifier->alt_coding_system = $this->message->queryTextNode("OBX.3/CE.6", $this->OBX_node) ?: null;

        return $identifier;
    }

    /**
     * @return CObservationValueUnit|null
     * @throws CHPrimSanteExceptionWarning
     */
    protected function mapUnit(): ?CObservationValueUnit
    {
        if (!$units = $this->message->queryTextNode("OBX.6/CE.1", $this->OBX_node)) {
            return null;
        }

        // Retrieve Observation Unit
        $identifier    = $units;
        $coding_system =
            $this->message->queryTextNode("OBX.6/CE.3", $this->OBX_node) ?: CObservationValueUnit::SYSTEM_UNKNOWN;
        $text          = $this->message->queryTextNode("OBX.6/CE.2", $this->OBX_node);
        $unit_type     = new CObservationValueUnit();
        if (!$unit_type->loadMatch($identifier, $coding_system, $text ?: $identifier)) {
            throw $this->makeWarning('OBX', '22', '10.7');
        }

        return $unit_type;
    }

    /**
     * @param string $content
     *
     * @return string
     */
    private function parseContent(string $content): string
    {
        $type = $this->message->getObservationType($this->OBX_node->parentNode);
        if ($type !== 'CE') {
            return $content;
        }

        $separator = $this->message->_ref_exchange_hpr->_event_message->message->componentSeparator;

        $exploded_content = explode($separator, $content, 3);

        return $exploded_content[1] ?? $exploded_content[0];
    }

    /**
     * @param CObservationResult $observation_result
     *
     * @return void
     * @throws Exception
     */
    private function mapResultExamen(CObservationResult $observation_result): void
    {
        if (!$chapter = $this->message->queryTextNode("OBX.15/CE.1", $this->OBX_node)) {
            return;
        }

        $result_examen = new CObservationResultExamen();
        $result_examen->observation_result_set_id = $this->observation_result_set->_id;
        $result_examen->code = $chapter;
        $result_examen->system = 'L';
        if (!$result_examen->loadMatchingObject()) {
            if ($msg = $result_examen->store()) {
                $this->message->addError(
                    $this->makeWarning('OBX', '23', '15', $msg)->getHprimError($this->message->_ref_exchange_hpr)
                );
            }
        }

        if ($result_examen->_id) {
            $observation_result->observation_result_examen_id = $result_examen->_id;
        }
    }
}

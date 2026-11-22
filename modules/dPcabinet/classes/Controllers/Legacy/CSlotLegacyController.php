<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Cabinet\Controllers\Legacy;

use Ox\Core\CApp;
use Ox\Core\CLegacyController;
use Ox\Core\CMbDT;
use Ox\Core\CSQLDataSource;
use Ox\Core\CStoredObject;
use Ox\Core\CView;
use Hospilibre\Cabinet\CPlageconsult;

class CSlotLegacyController extends CLegacyController
{
    public function modalReplaySlot(): void
    {
        $this->checkPermAdmin();
        CView::checkin();

        $this->renderSmarty('vw_replay_slot');
    }

    public function replaySlot(): void
    {
        $this->checkPermAdmin();

        $offset = CView::get("start", "num default|0");

        CView::checkin();

        CApp::setTimeLimit(300);
        CApp::setMemoryLimit("2048M");

        // On traite les plages de consultation par lots de 1000, depuis l'offset demandé.
        $pageSize     = 1000;
        $plageModel   = new CPlageconsult();
        $filter       = ["date" => ">= '2021-01-01'"];
        $plages       = $plageModel->loadList($filter, null, "$offset,$pageSize");

        $dataSource   = CSQLDataSource::get("std");
        $rowsToInsert = [];

        CStoredObject::massLoadBackRefs($plages, "slots");

        foreach ($plages as $_plage) {
            $_plage->loadRefsSlots();

            // Regroupe les slots existants par instant de début.
            $slotsByStart = [];
            foreach ($_plage->_ref_slots as $_slot) {
                $slotsByStart[$_slot->start][$_slot->_id] = $_slot;
            }

            $day    = CMbDT::date($_plage->date);
            $cursor = $day . " " . CMbDT::time($_plage->debut);
            $end    = $day . " " . CMbDT::time($_plage->fin);

            while ($cursor < $end) {
                $next = CMbDT::addDateTime($_plage->freq, $cursor);

                if (isset($slotsByStart[$cursor])) {
                    // Un slot existe déjà : si le premier (par id) est occupé, on annule la surréservation.
                    ksort($slotsByStart[$cursor]);
                    $existingSlot = reset($slotsByStart[$cursor]);
                    if ($existingSlot->status == "busy") {
                        $existingSlot->overbooked = 0;
                        $existingSlot->rawStore();
                    }
                } else {
                    // Aucun slot sur ce créneau : on prépare l'insertion d'un créneau disponible.
                    $rowsToInsert[] = [
                        "plageconsult_id" => $_plage->_id,
                        "start"           => $cursor,
                        "end"             => $next,
                    ];
                }

                $cursor = $next;
            }
        }

        if ($rowsToInsert !== []) {
            $dataSource->insertMulti("slot", $rowsToInsert, 1000);
        }

        $this->renderJson(["countPlage" => count($plages)]);
    }
}

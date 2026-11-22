<?php

/**
 * @package Hospilibre\Ccam
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Ccam;

use Exception;
use Ox\Core\Autoload\IShortNameAutoloadable;
use Ox\Core\CAppUI;
use Ox\Core\CSmartyDP;
use Hospilibre\CompteRendu\CCompteRendu;
use Hospilibre\CompteRendu\CHtmlToPDF;
use Hospilibre\Files\CFile;
use Hospilibre\Files\CFilesCategory;
use Hospilibre\Mediusers\CMediusers;

/**
 *  Impression d'un devis de codage en pdf et enregistrement en CFile avec le contexte du devis
 */
class CDevisCodageToPdfFile implements IShortNameAutoloadable
{
    /**
     * Generate the CFile object from CDevisCodage object
     *
     * @param CDevisCodage $devis
     *
     * @throws Exception
     */
    public static function generateFileFromDevisCodage(CDevisCodage $devis, bool $print = false): void
    {
        $file_category        = new CFilesCategory();
        $file_category->class = "CDevisCodage";
        $file_category->loadMatchingObjectEsc();
        
        if (!$file_category->_id) {
            CAppUI::setMsg(
                CAppUI::tr('CDevisCodage-configure a file category to generate pdf'),
                UI_MSG_ALERT
            );
            echo CAppUI::getMsg();
        } else {
            $file                   = new CFile();
            $file->file_category_id = $file_category->_id;
            $file->file_type        = "application/pdf";
            $file->author_id        = CMediusers::get()->_id;

            $file->fillFields();
            $file->updateFormFields();

            $file->_file_path = tempnam("./tmp", "tmp_devis");
            $file->file_name  = str_replace(" ", "_", $devis->libelle ?? 'Devis');

            // Add CDevisCodage context
            $file->object_class = $devis->codable_class;
            $file->object_id    = $devis->codable_id;

            $css_path = CAppUI::conf("base_url") . "/style/hospilibre_ext/standard.css";

            $smarty = new CSmartyDP("modules/dPccam");
            $smarty->assign("css_path", $css_path);
            $smarty->assign('devis', $devis);

            $content = (new self())->fetchTemplateContentForDevisCodage($devis);
            self::generatePdfFile($file, $content, $print);
        }
    }

    /**
     * Fetch the template content
     *
     * @param CDevisCodage $devis
     *
     * @return string
     * @throws Exception
     */
    private function fetchTemplateContentForDevisCodage(CDevisCodage $devis): string
    {
        $css_path = CAppUI::conf("base_url") . "/style/hospilibre_ext/standard.css";

        $smarty = new CSmartyDP("modules/dPccam");
        $smarty->assign("css_path", $css_path);
        $smarty->assign('devis', $devis);

        return $smarty->fetch('print_devis_codage_to_pdf');
    }

    /**
     * Generate the pdf content and store in the CFile object
     *
     * @param CFile  $file
     * @param string $content
     *
     * @throws Exception
     */
    public static function generatePdfFile(CFile $file, string $content, bool $print = false): void
    {
        $compte_rendu               = new CCompteRendu();
        $compte_rendu->_page_format = "A4";
        $compte_rendu->_orientation = "portrait";

        $pdf = (new CHtmlToPDF(null, ['old' => true]))->generatePDF($content, false, $compte_rendu, $file, false);

        $file->setContent($pdf);
        $file->store();
        if ($print) {
            ob_end_clean();
            $file->streamFile();
        }
    }
}

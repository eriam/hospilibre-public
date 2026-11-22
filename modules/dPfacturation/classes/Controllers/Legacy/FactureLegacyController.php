<?php

/**
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\Facturation\Controllers\Legacy;

use Ox\Core\CAppUI;
use Ox\Core\CLegacyController;
use Ox\Core\CMbDT;
use Ox\Core\CMbException;
use Ox\Core\CView;
use Hospilibre\Facturation\CFactureCabinet;
use Hospilibre\Facturation\FacturePrintService;
use Hospilibre\Files\MailReceiverService;
use Hospilibre\Mediusers\CMediusers;
use Hospilibre\System\CExchangeSource;
use Hospilibre\System\CSourceSMTP;
use phpmailerException;

class FactureLegacyController extends CLegacyController
{
    /**
     * Genere le PDF d'une facture et le renvoie au navigateur.
     */
    public function printFacture(): void
    {
        $this->checkPermRead();

        $factureClass = CView::get('facture_class', 'str notNull', true);
        $factureId    = CView::get('facture_id', 'ref meta|facture_class notNull', true);

        CView::checkin();

        /* @var CFactureCabinet $facture */
        $facture = new $factureClass();
        $facture->load($factureId);

        $printService = new FacturePrintService($facture);
        $pdfFile      = $printService->generatePdfFile(false, true);
        $pdfFile->store();
        $pdfFile->streamFile();
    }

    /**
     * Affiche le formulaire permettant d'envoyer une facture par courriel.
     */
    public function viewSendFactureByMail(): void
    {
        $this->checkPermRead();

        $factureClass = CView::get('facture_class', 'str notNull');
        $factureId    = CView::get('facture_id', 'ref meta|facture_class notNull');

        CView::checkin();

        /* @var CFactureCabinet $facture */
        $facture = new $factureClass();
        $facture->load($factureId);

        $patient = $facture->loadRefPatient();

        $receivers = (new MailReceiverService($patient))->getReceivers(
            MailReceiverService::ADDRESS_TYPE_MAIL,
            MailReceiverService::RECEIVER_TYPE_CASUAL
        );

        $facture->loadRefsConsultation();

        $firstConsult = $facture->_ref_first_consult;
        $firstConsult->loadRefPraticien();

        $formattedDate = CMbDT::format($firstConsult->_date, '%d/%m/%Y');

        $subject = CAppUI::tr('CFacture-msg-mail_subject', $formattedDate);
        $body    = str_replace(
            '<br />',
            '',
            CAppUI::tr('CFacture-msg-mail_body', $firstConsult->_ref_praticien->_view, $formattedDate)
        );

        $this->renderSmarty('view_send_facture_mail', [
            'facture'   => $facture,
            'subject'   => $subject,
            'body'      => $body,
            'patient'   => $patient,
            'receivers' => $receivers,
        ]);
    }

    /**
     * Envoie effectivement la facture par courriel via la source SMTP de l'utilisateur.
     */
    public function sendFactureByMail(): void
    {
        $this->checkPermRead();

        $factureClass = CView::post('facture_class', 'str notNull');
        $factureId    = CView::post('facture_id', 'ref meta|facture_class notNull');
        $receivers    = json_decode(utf8_encode(stripslashes(CView::post('receivers', 'str'))), true);
        $subject      = CView::post('subject', 'str');
        $body         = CView::post('body', 'str');

        CView::checkin();

        /* @var CFactureCabinet $facture */
        $facture = new $factureClass();
        $facture->load($factureId);

        $printService = new FacturePrintService($facture);
        $pdfFile      = $printService->generatePdfFile(false, false);
        $pdfFile->store();

        $currentUser = CMediusers::get();

        /** @var CSourceSMTP $source */
        $source = CExchangeSource::get("mediuser-" . $currentUser->_id, CSourceSMTP::TYPE);

        $source->setSenderNameFromUser($currentUser, true);
        $source->init();

        $source->setSubject($subject);
        $source->setBody(nl2br($body));

        $attachmentName = str_replace(['/', '  ', ' nÂ°'], ['', ' ', ''], $pdfFile->file_name);
        $source->addAttachment($pdfFile->_file_path, $attachmentName);

        $useBcc = CAppUI::loadPref('cciReceivers', $currentUser->_id);

        foreach ($receivers as $receiver) {
            if (!array_key_exists('email', $receiver) || $receiver['email'] == '') {
                continue;
            }

            if ($useBcc) {
                $source->addBcc($receiver['email'], $receiver['name']);
            } else {
                $source->setRecipient($receiver['email'], $receiver['name']);
            }
        }

        $mail = $source->createUserMail($currentUser->_id, $facture);

        if ($source->asynchronous != '0') {
            CAppUI::displayAjaxMsg('CUsermail-to_send');

            return;
        }

        try {
            $source->send();

            if ($mail) {
                $mail->sent = 1;
                $mail->store();
            }

            CAppUI::displayAjaxMsg('CFacture-msg-sent_by_mail');
        } catch (phpmailerException $e) {
            if ($mail) {
                $mail->delete();
            }

            CAppUI::displayAjaxMsg($e->errorMessage(), UI_MSG_WARNING);
        } catch (CMbException $e) {
            if ($mail) {
                $mail->delete();
            }

            $e->stepAjax();
        }
    }
}

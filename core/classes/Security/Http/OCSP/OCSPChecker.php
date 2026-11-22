<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Ox\Core\Security\Http\OCSP;

use Exception;
use Ocsp\Asn1\Element\Sequence;
use Ocsp\CertificateInfo;
use Ocsp\CertificateLoader;
use Ocsp\Exception\Asn1DecodingException;
use Ocsp\Ocsp;
use Ocsp\Response;
use Ox\Core\CHTTPClient;
use Ox\Core\Security\Http\OCSP\Exceptions\CouldNotCheckCertificate;
use Throwable;

/**
 * Create an OCSP to check the expiration of a certificate
 */
class OCSPChecker
{
    /** @var Sequence */
    protected $certificate;

    /** @var Sequence */
    protected $issuer_certificate;

    /** @var array  */
    public static $options = [];

    /**
     * OCSPChecker Construct
     *
     * @param Sequence $certificate
     * @param Sequence $issuer_certificate
     */
    protected function __construct(Sequence $certificate, Sequence $issuer_certificate)
    {
        $this->certificate        = $certificate;
        $this->issuer_certificate = $issuer_certificate;
    }

    /**
     * Create an OCSP Checker for an URL
     *
     * @param string $url_path URL to retrieve certificate information
     *
     * @return static
     * @throws Asn1DecodingException
     * @throws CouldNotCheckCertificate
     */
    public static function fromURL(string $url_path): self
    {
        $certificate_loader = new CertificateLoader();

        $cert_info = self::getCertificateInfo($url_path);

        $certificate        = $certificate_loader->fromString($cert_info[0]['Cert']);
        $issuer_certificate = $certificate_loader->fromString($cert_info[1]['Cert']);

        return new self($certificate, $issuer_certificate);
    }

    /**
     * Retrieves the response from the OCSP call to see if a certificate is revoked or not
     *
     * @return OCSPResponse
     * @throws CouldNotCheckCertificate
     */
    public function check(): OCSPResponse
    {
        return new OCSPResponse($this->getResponse());
    }

    /**
     * Set options when calling getCertificateInfo
     *
     * @param string $name  Name of option
     * @param string $value Value of option
     *
     * @return void
     */
    public static function setOption(string $name, string $value): void
    {
        self::$options[$name] = $value;
    }

    /**
     * Clear all options for getCertificateInfo
     *
     * @return void
     */

    public static function clearOptions(): void
    {
        self::$options = [];
    }

    /**
     * Get Certificate by an URL
     *
     * @param string $url_path URL to retrieve certificate information
     *
     * @return array
     * @throws CouldNotCheckCertificate
     * @throws Exception
     */
    protected static function getCertificateInfo(string $url_path): array
    {
        $http_client = new CHTTPClient($url_path);
        $http_client->setOption(CURLOPT_RETURNTRANSFER, false);
        $http_client->setOption(CURLOPT_CERTINFO, true);

        foreach (self::$options as $name => $value) {
            $http_client->setOption($name, $value);
        }

        $http_client->head(false);
        $cert_info = $http_client->getInfo(CURLINFO_CERTINFO);

        if (!(is_array($cert_info)) || !(count($cert_info) >= 2)) {
            throw CouldNotCheckCertificate::isNotComplete();
        }

        $http_client->closeConnection();

        return $cert_info;
    }

    /**
     * Return OCSP Response
     *
     * @return Response
     * @throws CouldNotCheckCertificate
     */
    protected function getResponse(): Response
    {
        return $this->loadOCSPResponse($this->certificate, $this->issuer_certificate);
    }

    /**
     * Returns the OCSP response for certificates passed
     *
     * @param Sequence $certificate        Certificate
     * @param Sequence $issuer_certificate Issuer Certificate
     *
     * @return Response
     * @throws CouldNotCheckCertificate
     */
    protected function loadOCSPResponse(Sequence $certificate, Sequence $issuer_certificate): Response
    {
        $ocsp             = new Ocsp();
        $certificate_info = new CertificateInfo();

        try {
            $request_info       = $certificate_info->extractRequestInfo($certificate, $issuer_certificate);
            $ocsp_responder_url = $certificate_info->extractOcspResponderUrl($certificate);
            $request_body       = $ocsp->buildOcspRequestBodySingle($request_info);

            $http_client         = new CHTTPClient($ocsp_responder_url);
            $http_client->header = ['Content-Type: ' . Ocsp::OCSP_REQUEST_MEDIATYPE];

            $result = $http_client->post($request_body);

            return $ocsp->decodeOcspResponseSingle($result);
        } catch (Throwable $t) {
            throw CouldNotCheckCertificate::cannotBuild($t->getMessage());
        }
    }
}

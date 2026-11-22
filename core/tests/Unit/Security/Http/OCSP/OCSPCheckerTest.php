<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Mssante\Tests\Unit;

use DateTimeImmutable;
use Exception;
use Ocsp\Exception\Asn1DecodingException;
use Ocsp\Response;
use Symfony\Component\HttpFoundation\Response as ResponseHTTP;
use Ox\Core\CHTTPClient;
use Ox\Core\Security\Http\OCSP\Exceptions\CouldNotCheckCertificate;
use Ox\Core\Security\Http\OCSP\OCSPChecker;
use Ox\Tests\UnitTestHospilibre;

/**
 * OCSPChecker class test
 * @group schedules
 */
class OCSPCheckerTest extends UnitTestHospilibre
{
    /**
     * @param string $url_path
     *
     * @dataProvider urlNotRevokedProvider
     *
     * @return void
     * @throws Asn1DecodingException
     * @throws CouldNotCheckCertificate
     * @throws Exception
     */
    public function testCheckerFromURL(string $url_path): void
    {
        $this->checkHTTPUrl($url_path);

        OCSPChecker::setOption(CURLOPT_SSL_VERIFYPEER, false);
        $this->assertNotEmpty(OCSPChecker::$options);

        $ocsp_response = (OCSPChecker::fromURL($url_path))->check();

        OCSPChecker::clearOptions();
        $this->assertEmpty(OCSPChecker::$options);

        $this->assertEquals($ocsp_response->isRevoked(), false);
        $this->assertNull($ocsp_response->getRevokedOn());
        $this->assertNull($ocsp_response->getRevocationReason());
        $this->assertNotNull($ocsp_response->getCertificateSerialNumber());
        $this->assertNotNull($ocsp_response->getValidatedDatetime());
    }

    public function urlNotRevokedProvider(): array
    {
        return [
            "gitlab"         => ["https://www.gitlab.com/"],
            "badssl_ecc384"  => ["https://ecc384.badssl.com/"],
            "badssl_rsa4096" => ["https://rsa4096.badssl.com/"],
        ];
    }

    /**
     * @param string $url_path
     *
     * @dataProvider urlRevokedProvider
     *
     * @return void
     * @throws Asn1DecodingException
     * @throws CouldNotCheckCertificate
     * @throws Exception
     */
    public function testCheckerRevokedFromURL(string $url_path): void
    {
        $this->checkHTTPUrl($url_path);

        OCSPChecker::setOption(CURLOPT_SSL_VERIFYPEER, false);
        $this->assertNotEmpty(OCSPChecker::$options);

        $ocsp_response = (OCSPChecker::fromURL($url_path))->check();

        OCSPChecker::clearOptions();
        $this->assertEmpty(OCSPChecker::$options);

        $this->assertEquals($ocsp_response->isRevoked(), true);
        $this->assertNotNull($ocsp_response->getRevokedOn());
        $this->assertNotNull($ocsp_response->getRevocationReason());
        $this->assertNotNull($ocsp_response->getCertificateSerialNumber());
        $this->assertNotNull($ocsp_response->getValidatedDatetime());
    }

    public function urlRevokedProvider(): array
    {
        return [
            "grc"    => ["https://revoked.grc.com/"],
            "badssl" => ["https://revoked.badssl.com/"],
        ];
    }

    /**
     * @param string $url_path
     *
     * @dataProvider urlExceptionProvider
     *
     * @return void
     * @throws Asn1DecodingException
     * @throws CouldNotCheckCertificate
     */
    public function testCheckerExceptionFromURL(string $url_path): void
    {
        $this->expectException(Exception::class);
        (OCSPChecker::fromURL($url_path))->check();
    }

    public function urlExceptionProvider(): array
    {
        return [
            "badssl_null"       => ["https://null.badssl.com/"],
            "badssl_wrong"      => ["https://wrong.host.badssl.com/"],
            "badssl_incomplete" => ["https://incomplete-chain.badssl.com/"],
            "empty"             => [""],
        ];
    }

    /**
     * Test the OCSP getter set
     *
     * @return void
     * @throws CouldNotCheckCertificate
     */
    public function testOCSPResponse(): void
    {
        $mock         = $this->OCSPCheckerMock();
        $ocsp_reponse = $mock->check();

        $this->assertEquals($ocsp_reponse->isRevoked(), true);
        $this->assertEquals($ocsp_reponse->getRevokedOn(), new DateTimeImmutable('2022-01-30'));
        $this->assertEquals($ocsp_reponse->getRevocationReason(), Response::REVOCATIONREASON_UNSPECIFIED);
        $this->assertEquals($ocsp_reponse->getCertificateSerialNumber(), '14853271989577973940433831223805371136');
        $this->assertEquals($ocsp_reponse->getValidatedDatetime(), new DateTimeImmutable('2022-02-08'));
    }

    private function OCSPCheckerMock(): OCSPChecker
    {
        $mock = $this->getMockBuilder(OCSPChecker::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResponse'])
            ->getMock();

        $mock_response = $this->createMock(Response::class);
        $mock_response->method('isRevoked')
            ->willReturn(true);
        $mock_response->method('getRevokedOn')
            ->willReturn(new DateTimeImmutable('2022-01-30'));
        $mock_response->method('getRevocationReason')
            ->willReturn(Response::REVOCATIONREASON_UNSPECIFIED);
        $mock_response->method('getCertificateSerialNumber')
            ->willReturn('14853271989577973940433831223805371136');
        $mock_response->method('getThisUpdate')
            ->willReturn(new DateTimeImmutable('2022-02-08'));

        $mock->method('getResponse')
            ->willReturn($mock_response);

        return $mock;
    }

    /**
     * Check if the url is accessible or not
     *
     * @param string $url_path
     *
     * @return void
     * @throws Exception
     */
    protected function checkHTTPUrl(string $url_path): void
    {
        $http_client = new CHTTPClient($url_path);

        try {
            $http_client->get(false);
            if (
                ($http_client->getInfo()['http_code'] < ResponseHTTP::HTTP_OK
                || $http_client->getInfo()['http_code'] >= ResponseHTTP::HTTP_BAD_REQUEST)
            ) {
                $this->markTestSkipped('Exception: Connection timed out');
            }
            $http_client->closeConnection();
        } catch (Exception $e) {
            $this->markTestSkipped('Exception: Connection timed out');
        }
    }
}

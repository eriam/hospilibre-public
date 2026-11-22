<?php

namespace Ox\Core\Tests\Unit;

use Exception;
use Ox\Core\CError;
use Hospilibre\System\CErrorLog;
use Ox\Tests\UnitTestHospilibre;
use ReflectionClass;

class CErrorTest extends UnitTestHospilibre
{

    public function testStoreBuffer()
    {
        $text = uniqid('error_');

        $codes = [E_ERROR, E_WARNING, E_PARSE, E_NOTICE];
        $code  = $codes[array_rand($codes)];

        CError::errorHandler($code, $text, __FILE__, __LINE__);

        $this->assertTrue(CError::countWaitingBuffer() > 0);

        $result = CError::storeBuffer(CError::getCurrentFileBuffer());
        $this->assertTrue($result);

        $log       = new CErrorLog();
        $log->text = $text;
        $log->loadMatchingObject();
        $this->assertNotNull($log->_id);
    }


    public function testWaitingBuffer()
    {
        $this->assertEquals(count(CError::globWaitingBuffer()), CError::countWaitingBuffer());
    }

    /**
     * @dataProvider puissanceProvider
     */
    public function testLogExponentialError(int $nb_error, int $nb_lines)
    {
        $text = uniqid('exception_');
        $i    = 1;
        while ($i <= $nb_error) {
            // trigger error
            CError::exceptionHandler(new Exception($text));
            $i++;
        }

        $buffer = CError::getCurrentFileBuffer();
        $lines  = @file($buffer);
        // check lines
        $this->assertEquals($nb_lines, count($lines));

        CError::$buffered_signatures = [];
        CError::storeBuffer(CError::getCurrentFileBuffer());

        $log       = new CErrorLog();
        $log->text = $text;
        $log->loadMatchingObject();

        // check bd
        $this->assertEquals($log->count, 2 ** ($nb_lines - 1));
    }

    /**
     * On stock dans le buffer des la première erreure
     * On a tjs un décalage d'une ligne en trop dans le buffer mais au store en bdd on corrige ce décalage
     */
    public function puissanceProvider()
    {
        return [
            [1, 1],
            [2, 2],
            [3, 2],
            [4, 3],
            [8, 4],
            [63, 6],
            [64, 7],
            [65, 7],
        ];
    }

    public function testErrorTriggerdInSuccessProcess()
    {
        $text = uniqid('exception_');
        $max  = rand(1, 10);
        $i    = 1;
        while ($i <= $max) {
            // trigger error
            CError::exceptionHandler(new Exception($text));

            if ($i % 2) {
                CError::exceptionHandler(new Exception(uniqid('random_exception_')));
            }

            $i++;
        }

        CError::storeBuffer(CError::getCurrentFileBuffer());

        $log       = new CErrorLog();
        $log->text = $text;
        $log->loadMatchingObject();

        // check bd
        $this->assertEquals($log->count, $max);
    }


    public function testGenerateSignatureHashSameHashDifferentText(): void
    {
        $class  = new ReflectionClass(CError::class);
        $method = $class->getMethod('generateSignatureHash');
        $method->setAccessible(true);
        $res_error = $method->invoke(null, "Test - 123", "warning", "index.php", "17");
        $res_error2 = $method->invoke(null, "Test - 456", "warning", "index.php", "17");
        $res_error3 = $method->invoke(null, "1717757675657Test - 45741730712762372175", "warning", "index.php", "17");
        self::assertEquals($res_error, $res_error2);
        self::assertEquals($res_error, $res_error3);
        self::assertEquals($res_error2, $res_error3);
    }

    public function testGenerateSignatureHashSameTextDifferentHash(): void
    {
        $class  = new ReflectionClass(CError::class);
        $method = $class->getMethod('generateSignatureHash');
        $method->setAccessible(true);
        $res_error = $method->invoke(null, "Test - 123", "warning", "index.php", "17");
        $res_error2 = $method->invoke(null, "Test - 123", "warning", "index.php", "18");
        $res_error3 = $method->invoke(null, "Test - 123", "warning", "test.php", "17");
        $res_error4 = $method->invoke(null, "Test - 123", "error", "index.php", "17");
        self::assertNotEquals($res_error, $res_error2);
        self::assertNotEquals($res_error2, $res_error3);
        self::assertNotEquals($res_error, $res_error3);
        self::assertNotEquals($res_error2, $res_error4);
        self::assertNotEquals($res_error3, $res_error4);
        self::assertNotEquals($res_error, $res_error4);
    }

    /**
     * IHM TU
     * $i = 1;
     * $error = 'perdu';
     * echo '<table><tr><td>errors</td><td>lines</td><td>estimated count</td><td>real count</td></tr>';
     *
     * //ini_set("display_errors",0);
     * $randname = uniqid('random_');
     * $count_random = 0;
     *
     * while ($i <= 2048) {
     * trigger_error($error);
     * echo '<tr><td>'.$i.'</td>';
     *
     * $lines = file(CError::getCurrentFileBuffer());
     * $count_lines = count($lines)-1;
     *
     * echo '<td>'.$count_lines.'</td>';
     *
     * echo '<td>'. 2 ** $count_lines.'</td>';
     *
     * $real = CError::$buffered_signatures['6d8670cb7cf496bba16ce12fa15fedd9'];
     *
     * echo '<td>'. $real.'</td></tr>';
     * //
     * //    if (rand(0,5) === 1){
     * //        $count_random++;
     * //        trigger_error($randname);
     * //    }
     *
     * $i++;
     * }
     */
}

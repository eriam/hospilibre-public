<?php
/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */
namespace Ox\Core\Profiler;

use Ox\Core\CApp;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class LegacyCollector extends AbstractDataCollector
{

    public function collect(Request $request, Response $response, \Throwable $exception = null)
    {
        // todo attach performance to response object ?
        $performance = CApp::getPerformance();

        // Times
        $request_time_float   = $request->server->get("REQUEST_TIME_FLOAT");
        $genere               = round(microtime(true) - $request_time_float, 3);
        $transport_tiers_time = $performance['transportTiers']['total']['time'] ?? null;
        $dataSourceTime       = $performance["dataSourceTime"] ?? null;
        $nosqlTime            = $performance["nosqlTime"] ?? null;
        $times                = [
            'php'       => $genere - $dataSourceTime - $nosqlTime - $transport_tiers_time,
            'sql'       => $dataSourceTime,
            'nosql'     => $nosqlTime,
            'transport' => $transport_tiers_time,
        ];
        foreach ($times as $key => $time) {
            $times[$key] = round($time * 1000, 3);
        }

        // Queries
        $dataSources = $performance['dataSources'] ?? [];
        $queries     = [];
        foreach ($dataSources as $key => $datas) {
            $queries[] = [strtoupper($key), $datas['count']];
        }

        // Cache
        $cache = $performance['cache'] ?? ['totals' => []];
        $data_cache = [];
        foreach ($cache['totals'] as $_layers) {
            foreach ($_layers as $layer => $count) {
                if (!isset($data_cache[$layer])) {
                    $data_cache[$layer] = 0;
                }
                $data_cache[$layer] = $data_cache[$layer] + $count;
            }
        }

        $this->data = [
            'times'   => $times,
            'queries' => $queries,
            'cache'   => $data_cache,
        ];
    }


    public static function getTemplate(): ?string
    {
        return 'data_collector/template.html.twig';
    }

    public function getTimes()
    {
        return $this->data['times'];
    }

    public function getQueries()
    {
        return $this->data['queries'];
    }

    public function getCache()
    {
        return $this->data['cache'];
    }
}

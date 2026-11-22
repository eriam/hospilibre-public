<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Sample\Controllers\Legacy;

use Exception;
use Ox\Core\Api\Exceptions\ApiException;
use Ox\Core\Api\Request\RequestFieldsets;
use Ox\Core\Api\Request\RequestRelations;
use Ox\Core\Api\Resources\AbstractResource;
use Ox\Core\CCanDo;
use Ox\Core\CLegacyController;
use Ox\Core\CMbException;
use Ox\Core\CMbModelNotFoundException;
use Ox\Core\CMbString;
use Ox\Core\CView;
use Ox\Core\EntryPoint;
use Hospilibre\Sample\Entities\CSampleCasting;
use Hospilibre\Sample\Entities\CSampleMovie;

/**
 * Legacy controller that display the tabs readme and movies.
 */
class SampleMoviesController extends CLegacyController
{
    public const LEGACY_MOVIE_DETAIL_LINK = '?m=sample&tab=displayMovieDetails&sample_movie_id=%d';

    private const README = 'readme.md';

    public function readme(): void
    {
        $this->checkPermRead();

        $readme = CMbString::markdown(
            file_get_contents(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . self::README)
        );

        $this->renderSmarty('readme', ['readme' => $readme,]);
    }

    /**
     * Legcay route to display movies
     *
     * @throws ApiException|CMbException|Exception
     */
    public function displayMovies(): void
    {
        $this->checkPermRead();

        CView::checkin();

        $entry = new EntryPoint('SampleMovieList');
        $entry->setScriptName('sampleMovieList')
            ->setLinks(
                [
                    'categories'    => $this->generateUrl('sample_categories_list'),
                    'nationalities' => $this->generateUrl('sample_nationalities_list'),
                    'movies'        => $this->generateUrl('sample_movies_list'),
                    'persons'       => $this->generateUrl('sample_persons_list'),
                ]
            );

        if (CCanDo::edit()) {
            $entry->addLinkValue('add-movie', $this->generateUrl('sample_movies_create'));
        }

        $this->renderEntryPoint($entry);
    }

    /**
     * Legacy route to display a movie with it's casting.
     *
     * @throws CMbModelNotFoundException|CMbException|Exception
     */
    public function displayMovieDetails(): void
    {
        $this->checkPermRead();

        $movie_id = CView::getRefCheckRead('sample_movie_id', 'ref class|CSampleMovie notNull');

        CView::checkin();

        $entry = new EntryPoint('SampleMovieDetails');
        $entry->setScriptName('sampleMovieDetails')
            ->setLinks(
                [
                    'movie'         => $this->generateUrl(
                        'sample_movies_show',
                        [
                            'sample_movie_id'                        => $movie_id,
                            RequestFieldsets::QUERY_KEYWORD         => RequestFieldsets::QUERY_KEYWORD_ALL,
                            RequestRelations::QUERY_KEYWORD_INCLUDE => CSampleMovie::RELATION_CATEGORY
                                . RequestRelations::RELATION_SEPARATOR . CSampleMovie::RELATION_DIRECTOR,
                            AbstractResource::PERMISSIONS_KEYWORD   => true,
                        ]
                    ),
                    'casting'       => $this->generateUrl(
                        'sample_casting_list',
                        [
                            'sample_movie_id'                        => $movie_id,
                            RequestRelations::QUERY_KEYWORD_INCLUDE => CSampleCasting::RELATION_ACTORS,
                        ]
                    ),
                    'back'          => '?m=sample&tab=displayMovies',
                    'categories'    => $this->generateUrl('sample_categories_list'),
                    'nationalities' => $this->generateUrl('sample_nationalities_list'),
                    'persons'       => $this->generateUrl('sample_persons_list'),
                ]
            );
        
        $this->renderEntryPoint($entry);
    }
}

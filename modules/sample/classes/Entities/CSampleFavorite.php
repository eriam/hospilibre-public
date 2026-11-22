<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Sample\Entities;

use Ox\Core\CMbObject;
use Ox\Core\CMbObjectSpec;

/**
 * Join table between movies and users telling if the user has added the movie to his favorite movies.
 * A user can only add the same movie to its favorites once.
 */
class CSampleFavorite extends CMbObject
{
    /** @var int */
    public $sample_favorite_id;

    /** @var int */
    public $user_id;

    /** @var int */
    public $movie_id;

    /**
     * The spec loggable is set to CMbObjectSpec::LOGGABLE_NEVER to never create user_action for this object.
     */
    public function getSpec(): CMbObjectSpec
    {
        $spec        = parent::getSpec();
        $spec->table = "sample_favorite";
        $spec->key   = "sample_favorite_id";

        $spec->loggable              = CMbObjectSpec::LOGGABLE_NEVER;
        $spec->uniques['user_movie'] = ['user_id', 'movie_id'];

        return $spec;
    }

    /**
     * Use the prop cascade to delete this object if the user or movie is deleted.
     */
    public function getProps(): array
    {
        $props = parent::getProps();

        $props['user_id']  = 'ref class|CMediusers notNull cascade back|favorite_movies';
        $props['movie_id'] = 'ref class|CSampleMovie notNull cascade back|favorites';

        return $props;
    }
}

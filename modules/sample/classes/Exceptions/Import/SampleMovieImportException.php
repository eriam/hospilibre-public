<?php

/**
 * @author  SAS OpenXtrem <dev@openxtrem.com>
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 * @license https://www.openxtrem.com/licenses/oxol.html OXOL OpenXtrem Open License
 */

namespace Hospilibre\Sample\Exceptions\Import;

use Ox\Core\CApp;
use Ox\Core\CLogger;
use Ox\Core\CMbException;
use Hospilibre\Sample\Import\MovieDb\SampleMovieImport;

class SampleMovieImportException extends CMbException
{
    public static function httpSourceNotFound(): self
    {
        return new static(
            'SampleMovieImportException-Error-Http-source-not-found',
            SampleMovieImport::BASE_HOST
        );
    }

    public static function httpResponseException(string $msg, string $path): self
    {
        CApp::log(SampleMovieImport::class, [$path, $msg], CLogger::LEVEL_WARNING);

        return new static('SampleMovieImportException-Error-An-error-occured-while-requesting-an-external-api', $path);
    }

    public static function unableToCreateCategory(string $category_name, string $msg): self
    {
        CApp::log(SampleMovieImport::class, $msg, CLogger::LEVEL_WARNING);

        return new static('SampleMovieImportException-Error-Unable-to-create-category', $category_name);
    }
}

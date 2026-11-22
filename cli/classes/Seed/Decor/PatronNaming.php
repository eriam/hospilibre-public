<?php

/**
 * @package Hospilibre\Cli
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 *
 * Code original Hospilibre.
 */

namespace Ox\Cli\Seed\Decor;

use Ox\Cli\Seed\SeedRandom;

/**
 * « Centre Hospitalier Sainte-Vérane — Écully », « Clinique du Val-Fleuri — Bourg-en-Bresse ».
 *
 * LE NOM PROPRE RESTE INVENTÉ ALORS QUE LA VILLE EST RÉELLE, et c'est la règle qui compte ici :
 * « Centre Hospitalier de Bordeaux » désignerait un établissement existant, et un jeu de démonstration
 * ne doit pas pouvoir être confondu avec lui. La ville réelle donne le territoire, le nom propre inventé
 * garantit qu'aucune institution n'est mise en cause.
 *
 * Un décor fournit son préfixe, ses noms propres et son préfixe de code ; la mécanique — casse des
 * communes, abréviation du code — est commune, parce qu'elle ne dépend pas du décor.
 */
final class PatronNaming implements EstablishmentNaming
{
    /** @var string Ce qui précède le nom propre : « Centre Hospitalier », « Clinique »… */
    private $prefix;

    /** @var string[] Noms propres inventés */
    private $patrons;

    /** @var string Préfixe du code de l'établissement */
    private $code_prefix;

    /** @var string[] Villes de repli, employées seulement sans géographie */
    private $cities;

    /**
     * @param string[] $patrons
     * @param string[] $cities
     */
    public function __construct(string $prefix, array $patrons, string $code_prefix, array $cities = [])
    {
        $this->prefix      = $prefix;
        $this->patrons     = $patrons;
        $this->code_prefix = $code_prefix;
        $this->cities      = $cities === [] ? self::cities() : $cities;
    }

    /**
     * @inheritDoc
     */
    public function name(SeedRandom $random, ?array $town): array
    {
        $patron = $random->pick($this->patrons);
        $city   = $town === null ? $random->pick($this->cities) : self::titleCase($town['commune']);

        return [
            'text'  => "{$this->prefix} {$patron} — {$city}",
            'code'  => $this->code_prefix . strtoupper(substr(self::slug($city), 0, 4)),
            'cp'    => $town === null ? null : $town['code_postal'],
            'ville' => $town === null ? null : $town['commune'],
        ];
    }

    /**
     * « BOURG-EN-BRESSE » → « Bourg-en-Bresse ».
     *
     * Le dictionnaire donne les communes en capitales, ce qui est l'usage dans un fichier administratif
     * mais se lit mal dans le nom d'un établissement. Les particules restent en minuscules, sans quoi on
     * obtiendrait « Bourg-En-Bresse ».
     */
    private static function titleCase(string $value): string
    {
        $particles = ['De', 'Du', 'Des', 'D', 'La', 'Le', 'Les', 'L', 'Sur', 'Sous', 'En', 'Et', 'Aux', 'Au', 'Lès'];

        $titled = mb_convert_case(mb_strtolower($value), MB_CASE_TITLE, 'UTF-8');

        return (string)preg_replace_callback(
            '/(?<=[-\s\x27])(' . implode('|', $particles) . ')(?=[-\s\x27]|$)/u',
            static function (array $match): string {
                return mb_strtolower($match[1]);
            },
            $titled
        );
    }

    private static function slug(string $value): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        $ascii = $ascii === false ? $value : $ascii;
        $ascii = preg_replace('/[^a-zA-Z0-9]+/', '-', $ascii);

        return strtolower(trim((string)$ascii, '-'));
    }

    /**
     * Villes de repli, employées seulement quand le décor n'a pas de géographie. Fictives : sans
     * dictionnaire, l'établissement ne doit pas se retrouver dans une ville réelle par hasard.
     *
     * @return string[]
     */
    private static function cities(): array
    {
        return [
            'Valmorin', 'Brécourt', 'Saint-Anselin', 'Courvieille', 'Montrebec',
            'Aubelieu', 'Pierregaud', 'Fontenailles', 'Larbrissac', 'Vaubourg',
        ];
    }

}

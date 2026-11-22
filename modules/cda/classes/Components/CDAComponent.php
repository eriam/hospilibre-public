<?php

/**
 * @package Ox\Interop\Cda\Components
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Ox\Interop\Cda\Components;

use Ox\Core\CMbSecurity;
use Ox\Core\CSmartyDP;
use Ox\Interop\Cda\CCDAClasseBase;
use Ox\Interop\Cda\CCDAFactory;
use Ox\Interop\Cda\Components\Entries\CDAEntry;
use Ox\Interop\Cda\Components\Sections\CDASection;

/**
 * Composant CDA de base : brique commune aux sections et aux entrées.
 *
 * @package Ox\Interop\Cda\Components
 */
abstract class CDAComponent
{
    /** @var string */
    public const TEMPLATE_ID = '';

    /** @var CCDAFactory */
    protected $factory;

    /** @var array  */
    protected $template_ids = [];

    /**
     * @param CCDAFactory $factory Fabrique de documents CDA
     */
    public function __construct(CCDAFactory $factory)
    {
        $this->factory = $factory;
    }

    /**
     * Rassemble l'ensemble des template ids issus de la hiérarchie du composant,
     * en s'appuyant sur la constante TEMPLATE_ID de chaque classe parente.
     *
     * @return string[]
     */
    public function getTemplateIds(): array
    {
        $collected = $this->template_ids;
        $skipped   = [self::class, CDASection::class, CDAEntry::class];

        foreach (class_parents($this) as $parent) {
            if (in_array($parent, $skipped, true)) {
                continue;
            }

            /** @var CDASection $parent */
            $inherited = $parent::TEMPLATE_ID;
            if ($inherited) {
                $collected[] = $inherited;
            }
        }

        $collected[] = static::TEMPLATE_ID;

        return array_unique($collected);
    }

    /**
     * Ajoute un ou plusieurs template ids au composant.
     *
     * @param string|string[] $ids
     *
     * @return array
     */
    public function addTemplateIds($ids): array
    {
        $additional = is_array($ids) ? $ids : [$ids];

        return $this->template_ids = array_unique(array_merge($this->template_ids, $additional));
    }

    /**
     * Construit le composant.
     *
     * @return CDAEntry|CDASection
     */
    abstract public function build(): CCDAClasseBase;

    /**
     * Produit un identifiant unique universel (UUID) en majuscules.
     *
     * @return string
     */
    protected function generateUUID(): string
    {
        return mb_strtoupper(CMbSecurity::generateUUID());
    }

    /**
     * Rend un template Smarty à partir des variables fournies.
     *
     * @param string      $tpl
     * @param array       $varriables
     * @param string|null $module
     *
     * @return string
     */
    protected function fetchSmarty(string $tpl, array $varriables = [], string $module = 'modules/cda'): string
    {
        $smarty = new CSmartyDP($module);

        foreach ($varriables as $name => $value) {
            if (is_string($name)) {
                $smarty->assign($name, $value);
            }
        }

        return $smarty->fetch($tpl);
    }
}

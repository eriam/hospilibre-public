<?php
/**
 * @package Hospilibre\System
 * @license https://www.gnu.org/licenses/gpl.html GNU General Public License
 */

namespace Hospilibre\System\Timeline;

use Ox\Core\Autoload\IShortNameAutoloadable;

/**
 * Element de menu affiche dans la timeline.
 */
class TimelineMenuItem implements ITimelineMenuItem, IShortNameAutoloadable {
  /** @var string */
  private $name;

  /** @var string */
  private $logo;

  /** @var MenuTimelineCategory */
  private $color;

  /** @var string */
  private $canonical_name;

  /** @var ITimelineMenuItem[] */
  private $children = [];

  /** @var ITimelineCategory */
  private $category;

  /** @var bool */
  private $visibility = true;

  /** @var bool */
  private $clickable = true;

  /** @var ITimelineAttachableMenuItem[] */
  private $attached_menus = [];

  /** @var bool */
  private $selected;

  /**
   * @return array
   */
  public function getInvolvedUsers(): array {
    // Sans categorie associee ou tant que l'element n'est pas selectionne, rien a remonter.
    if (!$this->category || !$this->selected) {
      return [];
    }

    return $this->category->getInvolvedUsers();
  }

  /**
   * @return int
   */
  public function getAmountEvents(): int {
    if (!$this->category || !$this->selected) {
      return 0;
    }

    return $this->category->getAmountEvents();
  }

  /**
   * @inheritDoc
   */
  public function getEventsByDate(): array {
    if (!$this->category || !$this->selected) {
      return [];
    }

    return $this->category->getEventsByDate();
  }

  /**
   * @inheritDoc
   */
  public function getName(): string {
    return $this->name;
  }

  /**
   * @inheritDoc
   */
  public function setName(string $name): void {
    $this->name = $name;
  }

  /**
   * @inheritDoc
   */
  public function getLogo(): string {
    return $this->logo;
  }

  /**
   * @inheritDoc
   */
  public function setLogo(string $logo): void {
    $this->logo = $logo;
  }

  /**
   * @inheritDoc
   */
  public function getCategoryColor(): MenuTimelineCategory {
    return $this->color;
  }

  /**
   * Renvoie la valeur de la couleur de categorie, normalisee en minuscules.
   *
   * @return string
   */
  public function getCategoryColorValue(): string {
    return strtolower($this->color->getValue());
  }

  /**
   * @inheritDoc
   */
  public function setCategoryColor(MenuTimelineCategory $color): void {
    $this->color = $color;
  }

  /**
   * @inheritDoc
   */
  public function getCanonicalName(): string {
    return $this->canonical_name;
  }

  /**
   * @inheritDoc
   */
  public function setCanonicalName(string $canonical_name): void {
    $this->canonical_name = $canonical_name;
  }

  /**
   * @return bool
   */
  public function isClickable(): bool {
    return $this->clickable;
  }

  /**
   * @param bool $clickable
   */
  public function setClickable(bool $clickable): void {
    $this->clickable = $clickable;
  }

  /**
   * @inheritDoc
   */
  public function getChildren(): array {
    return $this->children ?? [];
  }

  /**
   * @inheritDoc
   */
  public function setChildren(ITimelineMenuItem ...$items): void {
    $this->children = $items;
  }

  /**
   * @param ITimelineMenuItem $item
   *
   * @return void
   */
  public function addChild(ITimelineMenuItem $item): void {
    $this->children[] = $item;
  }

  /**
   * @return int
   */
  public function countChildren(): int {
    return count($this->children);
  }

  /**
   * @inheritDoc
   */
  public function setVisibility(bool $visibility): void {
    $this->visibility = $visibility;
  }

  /**
   * @inheritDoc
   */
  public function isVisible(): bool {
    return $this->visibility;
  }

  /**
   * @inheritDoc
   */
  public function setTimelineCategory(ITimelineCategory $category): void {
    $this->category = $category;
  }

  /**
   * @inheritDoc
   */
  public function getTimelineCategory(): ITimelineCategory {
    return $this->category;
  }

  /**
   * Rattache un menu secondaire a ce menu principal.
   *
   * @param ITimelineAttachableMenuItem $item Le menu rattache a ce menu principal
   *
   * @return void
   */
  public function attachMenu(ITimelineAttachableMenuItem $item): void {
    $this->attached_menus[] = $item;
  }

  /**
   * Renvoie les menus rattaches au menu principal.
   *
   * @return ITimelineAttachableMenuItem[]
   */
  public function attachedMenus(): array {
    return $this->attached_menus;
  }

  /**
   * @inheritDoc
   */
  public function setSelected(bool $selected): void {
    $this->selected = $selected;
  }

  /**
   * @inheritDoc
   */
  public function getSelected(): bool {
    return $this->selected ?? false;
  }
}

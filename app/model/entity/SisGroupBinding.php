<?php
namespace App\Model\Entity;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 */
class SisGroupBinding {
  /**
   * @ORM\Id
   * @ORM\Column(type="guid")
   * @ORM\GeneratedValue(strategy="UUID")
   */
  protected $id;

  /**
   * @ORM\ManyToOne(targetEntity="Group")
   */
  protected $group;

  /**
   * @ORM\Column(type="string")
   */
  protected $code;

  /**
   * SisGroupBinding constructor.
   * @param $group
   * @param $code
   */
  public function __construct($group, $code) {
    $this->group = $group;
    $this->code = $code;
  }

  public function getGroup() {
    return $this->group;
  }
}
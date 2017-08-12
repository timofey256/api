<?php
namespace App\Model\Repository;

use App\Model\Entity\Group;
use App\Model\Entity\SisGroupBinding;
use Kdyby\Doctrine\EntityManager;

class SisGroupBindings extends BaseRepository {
  public function __construct(EntityManager $em) {
    parent::__construct($em, SisGroupBinding::class);
  }

  /**
   * @param $code
   * @return SisGroupBinding[]
   */
  public function findByCode($code) {
    return $this->findBy([
      'code' => $code
    ]);
  }

  /**
   * @param $group
   * @param $code
   * @return SisGroupBinding|NULL
   */
  public function findByGroupAndCode(Group $group, $code) {
    return $this->findOneBy([
      'code' => $code,
      'group' => $group
    ]);
  }
}
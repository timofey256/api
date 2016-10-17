<?php

namespace App\Model\Repository;

use Nette;
use DateTime;
use Kdyby\Doctrine\EntityManager;

use App\Model\Entity\SolutionEvaluation;

class SolutionEvaluations extends BaseRepository {

  public function __construct(EntityManager $em) {
    parent::__construct($em, SolutionEvaluation::CLASS);
  }

}
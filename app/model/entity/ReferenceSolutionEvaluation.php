<?php

namespace App\Model\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use JsonSerializable;
use DateTime;
use App\Helpers\EvaluationStatus as ES;

/**
 * @ORM\Entity
 *
 * @method string getId()
 * @method string getResultsUrl()
 * @method ReferenceExerciseSolution getReferenceSolution()
 * @method string setResultsUrl(string $url)
 * @method string getJobConfigPath()
 */
class ReferenceSolutionEvaluation implements JsonSerializable, ES\IEvaluable
{
  use \Kdyby\Doctrine\Entities\MagicAccessors;

  const JOB_TYPE = "reference";

  /**
   * @ORM\Id
   * @ORM\Column(type="guid")
   * @ORM\GeneratedValue(strategy="UUID")
   */
  protected $id;

  /**
   * @ORM\ManyToOne(targetEntity="ReferenceExerciseSolution", inversedBy="evaluations")
   */
  protected $referenceSolution;

  /**
   * @ORM\ManyToOne(targetEntity="HardwareGroup")
   */
  protected $hwGroup;

  /**
   * @ORM\Column(type="string", nullable=true)
   */
  protected $resultsUrl;

  /**
   * @ORM\Column(type="string")
   */
  protected $jobConfigPath;

  public function canBeEvaluated(): bool {
    return $this->resultsUrl !== NULL;
  }

  /**
   * @ORM\OneToOne(targetEntity="SolutionEvaluation", cascade={"persist", "remove"})
   * @var SolutionEvaluation
   */
  protected $evaluation;

  public function hasEvaluation(): bool {
    return $this->evaluation !== NULL;
  }

  public function getEvaluation(): SolutionEvaluation {
    return $this->evaluation;
  }

  public function setEvaluation(SolutionEvaluation $evaluation) {
    $this->evaluation = $evaluation;
  }

  public function jsonSerialize() {
    $evaluationData = NULL;
    if ($this->evaluation !== NULL) {
      $evaluationData = $this->evaluation->getData(TRUE, TRUE);
    }

    return [
      "id" => $this->id,
      "referenceSolutionId" => $this->referenceSolution->getId(),
      "evaluationStatus" => ES\EvaluationStatus::getStatus($this),
      "evaluation" => $evaluationData
    ];
  }

  public function __construct(ReferenceExerciseSolution $referenceSolution, HardwareGroup $hwGroup, string $jobConfigPath) {
    $this->referenceSolution = $referenceSolution;
    $this->hwGroup = $hwGroup;
    $this->jobConfigPath = $jobConfigPath;
  }

  function isValid(): bool {
    return $this->evaluation && $this->evaluation->isValid();
  }

  function isCorrect(): bool {
    return TRUE;
  }

}

<?php

namespace App\Model\Entity;

use App\Exceptions\SubmissionEvaluationFailedException;
use App\Helpers\EvaluationResults\EvaluationResults;
use App\Helpers\IScoreCalculator;

use DateTime;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use JsonSerializable;


/**
 * @ORM\Entity
 *
 * @method string getId()
 * @method DateTime getEvaluatedAt()
 * @method bool getEvaluationFailed()
 * @method float getScore()
 * @method int getPoints()
 * @method setPoints(int $points)
 * @method setScore(float $score)
 * @method Collection getTestResults()
 * @method AssignmentSolution getAssignmentSolution()
 * @method ReferenceSolutionSubmission getReferenceSolutionSubmission()
 * @method bool getInitFailed()
 */
class SolutionEvaluation implements JsonSerializable
{
  use \Kdyby\Doctrine\Entities\MagicAccessors;

  /**
   * @ORM\Id
   * @ORM\Column(type="guid")
   * @ORM\GeneratedValue(strategy="UUID")
   */
  protected $id;

  /**
   * @ORM\Column(type="datetime")
   */
  protected $evaluatedAt;

  /**
   * If true, the solution cannot be compiled.
   * @ORM\Column(type="boolean")
   */
  protected $initFailed;

  /**
   * @ORM\Column(type="float")
   */
  protected $score;

  /**
   * @ORM\Column(type="integer")
   */
  protected $points;

  /**
   * @ORM\Column(type="text")
   */
  protected $resultYml;

  /**
   * @ORM\Column(type="text")
   */
  protected $initiationOutputs;

  /**
   * @ORM\OneToMany(targetEntity="TestResult", mappedBy="solutionEvaluation", cascade={"persist"})
   */
  protected $testResults;

  /**
   * @ORM\OneToOne(targetEntity="AssignmentSolution", mappedBy="evaluation")
   */
  protected $assignmentSolution;

  /**
   * @ORM\OneToOne(targetEntity="ReferenceSolutionSubmission", mappedBy="evaluation")
   */
  protected $referenceSolutionSubmission;


  public function getData(bool $canViewRatios, bool $canViewValues = false) {
    $testResults = $this->testResults->map(
      function (TestResult $res) use ($canViewRatios, $canViewValues) {
        return $res->getData($canViewRatios, $canViewValues);
      }
    )->getValues();

    return [
      "id" => $this->id,
      "evaluatedAt" => $this->evaluatedAt->getTimestamp(),
      "score" => $this->score,
      "points" => $this->points,
      "initFailed" => $this->initFailed,
      "initiationOutputs" => $this->initiationOutputs,
      "testResults" => $testResults
    ];
  }

  public function jsonSerialize() {
    return $this->getData(FALSE);
  }

  /**
   * Loads and processes the results of the submission.
   * @param EvaluationResults $results The interpreted results
   * @param AssignmentSolution|null $submission The submission. It can be null in case we're handling a reference solution evaluation
   * @param ReferenceSolutionSubmission|null $evaluation
   */
  public function __construct(EvaluationResults $results,
      AssignmentSolution $submission = null,
      ReferenceSolutionSubmission $evaluation = null) {
    $this->evaluatedAt = new \DateTime;
    $this->initFailed = !$results->initOK();
    $this->resultYml = (string) $results;
    $this->score = 0;
    $this->points = 0;
    $this->testResults = new ArrayCollection;
    $this->initiationOutputs = $results->getInitiationOutputs();
    $this->assignmentSolution = $submission;
    $this->referenceSolutionSubmission = $evaluation;

    // set test results
    foreach ($results->getTestsResults() as $result) {
      $testResult = new TestResult($this, $result);
      $this->testResults->add($testResult);
    }
  }

}

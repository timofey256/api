<?php

namespace App\Helpers\Notifications;

use App\Exceptions\InvalidStateException;
use App\Helpers\Emails\EmailLatteFactory;
use App\Helpers\Emails\EmailLocalizationHelper;
use App\Helpers\Emails\EmailLinkHelper;
use App\Model\Entity\AssignmentSolutionSubmission;
use App\Helpers\EmailHelper;
use DateTime;
use Nette\Utils\Arrays;

/**
 * Sending emails on submission evaluation.
 */
class SubmissionEmailsSender {

  /** @var EmailHelper */
  private $emailHelper;

  /** @var string */
  private $sender;
  /** @var string */
  private $submissionEvaluatedPrefix;
  /** @var string */
  private $submissionRedirectUrl;
  /** @var string */
  private $submissionNotificationThreshold;


  /**
   * Constructor.
   * @param EmailHelper $emailHelper
   * @param array $params
   */
  public function __construct(EmailHelper $emailHelper, array $params) {
    $this->emailHelper = $emailHelper;
    $this->sender = Arrays::get($params, ["emails", "from"], "noreply@recodex.mff.cuni.cz");
    $this->submissionEvaluatedPrefix = Arrays::get($params, ["emails", "submissionEvaluatedPrefix"], "Submission Evaluated - ");
    $this->submissionRedirectUrl = Arrays::get($params, ["submissionRedirectUrl"], "https://recodex.mff.cuni.cz");
    $this->submissionNotificationThreshold = Arrays::get($params, ["submissionNotificationThreshold"], "-5 minutes");
  }

  /**
   * Submission was evaluated and we have to let the user know it.
   * @param AssignmentSolutionSubmission $submission
   * @return bool
   * @throws InvalidStateException
   */
  public function submissionEvaluated(AssignmentSolutionSubmission $submission): bool {
    $solution = $submission->getAssignmentSolution();
    $assignment = $solution->getAssignment();

    // check the threshold for sending email notification
    $threshold = (new DateTime())->modify($this->submissionNotificationThreshold);
    if ($solution->getSolution()->getCreatedAt() >= $threshold) {
      return true;
    }

    $user = $submission->getAssignmentSolution()->getSolution()->getAuthor();
    if (!$user->getSettings()->getSubmissionEvaluatedEmails()) {
      return true;
    }

    $locale = $user->getSettings()->getDefaultLanguage();
    $subject = $this->submissionEvaluatedPrefix .
      EmailLocalizationHelper::getLocalization($locale, $assignment->getLocalizedTexts())->getName();

    // Send the mail
    return $this->emailHelper->send(
      $this->sender,
      [$user->getEmail()],
      $locale,
      $subject,
      $this->createSubmissionEvaluatedBody($submission, $locale)
    );
  }

  /**
   * Prepare and format body of the mail
   * @param AssignmentSolutionSubmission $submission
   * @param string $locale
   * @return string Formatted mail body to be sent
   * @throws InvalidStateException
   */
  private function createSubmissionEvaluatedBody(AssignmentSolutionSubmission $submission, string $locale): string {
    $assignment = $submission->getAssignmentSolution()->getAssignment();

    // render the HTML to string using Latte engine
    $latte = EmailLatteFactory::latte();
    $template = EmailLocalizationHelper::getTemplate($locale, __DIR__ . "/submissionEvaluated_{locale}.latte");
    return $latte->renderToString($template, [
      "assignment" => EmailLocalizationHelper::getLocalization($locale, $assignment->getLocalizedTexts())->getName(),
      "group" => EmailLocalizationHelper::getLocalization($locale, $assignment->getGroup()->getLocalizedTexts())->getName(),
      "date" => $submission->getEvaluation()->getEvaluatedAt(),
      "status" => $submission->isCorrect() === true ? "success" : "failure",
      "points" => $submission->getEvaluation()->getPoints(),
      "maxPoints" => $assignment->getMaxPoints($submission->getEvaluation()->getEvaluatedAt()),
      "link" => EmailLinkHelper::getLink($this->submissionRedirectUrl, [
        "assignmentId" => $assignment->getId(),
        "solutionId" => $submission->getAssignmentSolution()->getId()
      ])
    ]);
  }

}

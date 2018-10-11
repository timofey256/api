<?php

namespace App\V1Module\Presenters;

use App\Exceptions\BadRequestException;
use App\Exceptions\ForbiddenRequestException;
use App\Exceptions\InvalidArgumentException;
use App\Exceptions\InvalidStateException;
use App\Exceptions\NotFoundException;
use App\Helpers\Localizations;
use App\Helpers\Notifications\AssignmentEmailsSender;
use App\Helpers\Validators;
use App\Model\Entity\LocalizedExercise;
use App\Model\Entity\LocalizedShadowAssignment;
use App\Model\Entity\ShadowAssignment;
use App\Model\Entity\ShadowAssignmentPoints;
use App\Model\Repository\Groups;
use App\Model\Repository\ShadowAssignmentPointsRepository;
use App\Model\Repository\ShadowAssignments;
use App\Model\View\ShadowAssignmentPointsViewFactory;
use App\Model\View\ShadowAssignmentViewFactory;
use App\Security\ACL\IGroupPermissions;
use App\Security\ACL\IShadowAssignmentPointsPermissions;
use App\Security\ACL\IShadowAssignmentPermissions;
use DateTime;
use Nette\Utils\Arrays;

/**
 * Endpoints for points assignment manipulation
 * @LoggedIn
 */
class ShadowAssignmentsPresenter extends BasePresenter {

  /**
   * @var ShadowAssignments
   * @inject
   */
  public $shadowAssignments;

  /**
   * @var ShadowAssignmentPointsRepository
   * @inject
   */
  public $shadowAssignmentPointsRepository;

  /**
   * @var IShadowAssignmentPermissions
   * @inject
   */
  public $shadowAssignmentAcl;

  /**
   * @var IShadowAssignmentPointsPermissions
   * @inject
   */
  public $shadowAssignmentPointsAcl;

  /**
   * @var ShadowAssignmentViewFactory
   * @inject
   */
  public $shadowAssignmentViewFactory;

  /**
   * @var ShadowAssignmentPointsViewFactory
   * @inject
   */
  public $shadowAssignmentPointsViewFactory;

  /**
   * @var AssignmentEmailsSender
   * @inject
   */
  public $assignmentEmailsSender;

  /**
   * @var Groups
   * @inject
   */
  public $groups;

  /**
   * @var IGroupPermissions
   * @inject
   */
  public $groupAcl;



  public function checkDetail(string $id) {
    $assignment = $this->shadowAssignments->findOrThrow($id);
    if (!$this->shadowAssignmentAcl->canViewDetail($assignment)) {
      throw new ForbiddenRequestException("You cannot view this shadow assignment.");
    }
  }

  /**
   * Get details of a shadow assignment
   * @GET
   * @param string $id Identifier of the assignment
   * @throws NotFoundException
   */
  public function actionDetail(string $id) {
    $assignment = $this->shadowAssignments->findOrThrow($id);
    $this->sendSuccessResponse($this->shadowAssignmentViewFactory->getAssignment($assignment));
  }

  public function checkUpdateDetail(string $id) {
    $assignment = $this->shadowAssignments->findOrThrow($id);
    if (!$this->shadowAssignmentAcl->canUpdate($assignment)) {
      throw new ForbiddenRequestException("You cannot update this shadow assignment.");
    }
  }

  /**
   * Update details of an shadow assignment
   * @POST
   * @param string $id Identifier of the updated assignment
   * @Param(type="post", name="version", validation="numericint", description="Version of the edited assignment")
   * @Param(type="post", name="isPublic", validation="bool", description="Is the assignment ready to be displayed to students?")
   * @Param(type="post", name="isBonus", validation="bool", description="If set to true then points from this exercise will not be included in overall score of group")
   * @Param(type="post", name="localizedTexts", validation="array", description="A description of the assignment")
   * @Param(type="post", name="maxPoints", validation="numericint", description="A maximum of points that user can be awarded")
   * @Param(type="post", name="sendNotification", required=false, validation="bool", description="If email notification should be sent")
   * @throws BadRequestException
   * @throws InvalidArgumentException
   * @throws NotFoundException
   */
  public function actionUpdateDetail(string $id) {
    $assignment = $this->shadowAssignments->findOrThrow($id);

    $req = $this->getRequest();
    $version = intval($req->getPost("version"));
    if ($version !== $assignment->getVersion()) {
      throw new BadRequestException("The shadow assignment was edited in the meantime and the version has changed. Current version is {$assignment->getVersion()}.");
    }

    // localized texts cannot be empty
    if (count($req->getPost("localizedTexts")) == 0) {
      throw new InvalidArgumentException("No entry for localized texts given.");
    }

    // old values of some attributes
    $wasPublic = $assignment->isPublic();
    $isPublic = filter_var($req->getPost("isPublic"), FILTER_VALIDATE_BOOLEAN);
    $sendNotification = $req->getPost("sendNotification") ? filter_var($req->getPost("sendNotification"), FILTER_VALIDATE_BOOLEAN) : true;

    $assignment->incrementVersion();
    $assignment->updatedNow();
    $assignment->setIsPublic($isPublic);
    $assignment->setIsBonus(filter_var($req->getPost("isBonus"), FILTER_VALIDATE_BOOLEAN));
    $assignment->setMaxPoints($req->getPost("maxPoints"));

    if ($sendNotification && $wasPublic === false && $isPublic === true) {
      // assignment is moving from non-public to public, send notification to students
      $this->assignmentEmailsSender->assignmentCreated($assignment);
    }

    // go through localizedTexts and construct database entities
    $localizedTexts = [];
    foreach ($req->getPost("localizedTexts") as $localization) {
      $lang = $localization["locale"];

      if (array_key_exists($lang, $localizedTexts)) {
        throw new InvalidArgumentException("Duplicate entry for language '$lang' in localizedTexts");
      }

      // create all new localized texts
      $externalAssignmentLink = trim(Arrays::get($localization, "link", ""));
      if ($externalAssignmentLink !== "" && !Validators::isUrl($externalAssignmentLink)) {
        throw new InvalidArgumentException("External assignment link is not a valid URL");
      }

      $localized = new LocalizedShadowAssignment(
        $lang,
        trim(Arrays::get($localization, "name", "")),
        trim(Arrays::get($localization, "text", "")),
        $externalAssignmentLink ?: null
      );

      $localizedTexts[$lang] = $localized;
    }

    // make changes to database
    Localizations::updateCollection($assignment->getLocalizedTexts(), $localizedTexts);

    foreach ($assignment->getLocalizedTexts() as $localizedText) {
      $this->shadowAssignments->persist($localizedText, false);
    }

    $this->shadowAssignments->flush();
    $this->sendSuccessResponse($this->shadowAssignmentViewFactory->getAssignment($assignment));
  }

  /**
   * Create new shadow assignment in given group.
   * @POST
   * @Param(type="post", name="groupId", description="Identifier of the group")
   * @throws ForbiddenRequestException
   * @throws BadRequestException
   * @throws NotFoundException
   */
  public function actionCreate() {
    $req = $this->getRequest();
    $group = $this->groups->findOrThrow($req->getPost("groupId"));

    if (!$this->groupAcl->canCreateShadowAssignment($group)) {
      throw new ForbiddenRequestException("You are not allowed to create assignment in given group.");
    }

    if ($group->isOrganizational()) {
      throw new BadRequestException("You cannot create assignment in organizational groups");
    }

    $assignment = ShadowAssignment::createInGroup($group);
    $this->shadowAssignments->persist($assignment);
    $this->sendSuccessResponse($this->shadowAssignmentViewFactory->getAssignment($assignment));
  }

  public function checkRemove(string $id) {
    $assignment = $this->shadowAssignments->findOrThrow($id);

    if (!$this->shadowAssignmentAcl->canRemove($assignment)) {
      throw new ForbiddenRequestException("You cannot remove this shadow assignment.");
    }
  }

  /**
   * Delete shadow assignment
   * @DELETE
   * @param string $id Identifier of the assignment to be removed
   * @throws NotFoundException
   */
  public function actionRemove(string $id) {
    $assignment = $this->shadowAssignments->findOrThrow($id);
    $this->shadowAssignments->remove($assignment);
    $this->sendSuccessResponse("OK");
  }

  public function checkPointsList(string $id) {
    $assignment = $this->shadowAssignments->findOrThrow($id);
    if (!$this->shadowAssignmentAcl->canViewPointsList($assignment)) {
      throw new ForbiddenRequestException();
    }
  }

  /**
   * Get a list of points of all users for the assignment
   * @GET
   * @param string $id Identifier of the assignment
   * @throws NotFoundException
   */
  public function actionPointsList(string $id) {
    $assignment = $this->shadowAssignments->findOrThrow($id);

    $pointsList = array_filter($assignment->getShadowAssignmentPointsCollection()->getValues(),
      function (ShadowAssignmentPoints $points) {
        return $this->shadowAssignmentPointsAcl->canViewDetail($points);
      });

    $this->sendSuccessResponse($this->shadowAssignmentPointsViewFactory->getPointsList($pointsList));
  }

  public function checkPoints(string $pointsId) {
    $points = $this->shadowAssignmentPointsRepository->findOrThrow($pointsId);
    if (!$this->shadowAssignmentPointsAcl->canViewDetail($points)) {
      throw new ForbiddenRequestException();
    }
  }

  /**
   * Get shadow assignment points detail.
   * @GET
   * @param string $pointsId Identifier of the shadow assignment points
   * @throws NotFoundException
   */
  public function actionPoints(string $pointsId) {
    $points = $this->shadowAssignmentPointsRepository->findOrThrow($pointsId);
    $points = $this->shadowAssignmentPointsViewFactory->getPoints($points);
    $this->sendSuccessResponse($points);
  }

  public function checkCreatePoints(string $id) {
    $assignment = $this->shadowAssignments->findOrThrow($id);
    if (!$this->shadowAssignmentAcl->canCreatePoints($assignment)) {
      throw new ForbiddenRequestException();
    }
  }

  /**
   * Create new points for shadow assignment and user.
   * @POST
   * @param string $id Identifier of the shadow assignment
   * @Param(type="post", name="userId", validation="string", description="Identifier of the user which is marked as awardee for points")
   * @Param(type="post", name="points", validation="numericint", description="Number of points assigned to the user")
   * @Param(type="post", name="note", validation="string", description="Note about newly created points")
   * @Param(type="post", name="awardedAt", validation="timestamp", required=false, description="Datetime when the points were awarded, whatever that might means")
   * @throws NotFoundException
   * @throws ForbiddenRequestException
   * @throws BadRequestException
   */
  public function actionCreatePoints(string $id) {
    $req = $this->getRequest();
    $userId = $req->getPost("userId");
    $points = $req->getPost("points");
    $note = $req->getPost("note");

    $awardedAt = $req->getPost("awardedAt") ?: null;
    $awardedAt = $awardedAt ? DateTime::createFromFormat('U', $awardedAt) : null;

    $assignment = $this->shadowAssignments->findOrThrow($id);
    $user = $this->users->findOrThrow($userId);
    if (!$assignment->getGroup()->isStudentOf($user)) {
      throw new BadRequestException("User is not member of the group");
    }

    if ($assignment->getPointsByUser($user)) {
      throw new BadRequestException("Given user already has shadow assignment points");
    }

    $pointsEntity = new ShadowAssignmentPoints($points, $note, $assignment, $this->getCurrentUser(), $user, $awardedAt);
    $this->shadowAssignmentPointsRepository->persist($pointsEntity);
    $this->sendSuccessResponse($this->shadowAssignmentPointsViewFactory->getPoints($pointsEntity));
  }

  public function checkUpdatePoints(string $pointsId) {
    $points = $this->shadowAssignmentPointsRepository->findOrThrow($pointsId);
    if (!$this->shadowAssignmentPointsAcl->canUpdate($points)) {
      throw new ForbiddenRequestException();
    }
  }

  /**
   * Update detail of shadow assignment points.
   * @POST
   * @param string $pointsId Identifier of the shadow assignment points
   * @Param(type="post", name="points", validation="numericint", description="Number of points assigned to the user")
   * @Param(type="post", name="note", validation="string", description="Note about newly created points")
   * @Param(type="post", name="awardedAt", validation="timestamp", required=false, description="Datetime when the points were awarded, whatever that might means")
   * @throws NotFoundException
   */
  public function actionUpdatePoints(string $pointsId) {
    $pointsEntity = $this->shadowAssignmentPointsRepository->findOrThrow($pointsId);

    $req = $this->getRequest();
    $points = $req->getPost("points");
    $note = $req->getPost("note");

    $awardedAt = $req->getPost("awardedAt") ?: null;
    $awardedAt = $awardedAt ? DateTime::createFromFormat('U', $awardedAt) : null;

    $pointsEntity->updatedNow();
    $pointsEntity->setPoints($points);
    $pointsEntity->setNote($note);
    $pointsEntity->setAwardedAt($awardedAt);

    $this->shadowAssignmentPointsRepository->flush();
    $this->sendSuccessResponse($this->shadowAssignmentPointsViewFactory->getPoints($pointsEntity));
  }

  public function checkRemovePoints(string $pointsId) {
    $points = $this->shadowAssignmentPointsRepository->findOrThrow($pointsId);
    if (!$this->shadowAssignmentPointsAcl->canRemove($points)) {
      throw new ForbiddenRequestException();
    }
  }

  /**
   * Remove points of shadow assignment.
   * @DELETE
   * @param string $pointsId Identifier of the shadow assignment points
   * @throws NotFoundException
   */
  public function actionRemovePoints(string $pointsId) {
    $points = $this->shadowAssignmentPointsRepository->findOrThrow($pointsId);
    $this->shadowAssignmentPointsRepository->remove($points);
    $this->sendSuccessResponse("OK");
  }
}
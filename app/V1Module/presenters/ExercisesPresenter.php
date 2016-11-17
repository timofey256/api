<?php

namespace App\V1Module\Presenters;

use App\Exceptions\BadRequestException;
use App\Exceptions\ForbiddenRequestException;
use App\Exceptions\JobConfigStorageException;
use App\Exceptions\CannotReceiveUploadedFileException;
use App\Model\Repository\Exercises;
use App\Model\Entity\Exercise;
use App\Helpers\UploadedJobConfigStorage;
use App\Helpers\SupplementaryFileStorage;
use App\Model\Entity\SolutionRuntimeConfig;
use App\Model\Repository\RuntimeEnvironments;
use App\Model\Repository\HardwareGroups;
use App\Model\Entity\LocalizedAssignment;
use App\Model\Repository\UploadedFiles;
use App\Model\Repository\SupplementaryFiles;

/**
 * Endpoint for exercise manipulation
 * @LoggedIn
 */
class ExercisesPresenter extends BasePresenter {

  /**
   * @var Exercises
   * @inject
   */
  public $exercises;

  /**
   * @var UploadedJobConfigStorage
   * @inject
   */
  public $uploadedJobConfigStorage;

  /**
   * @var RuntimeEnvironments
   * @inject
   */
  public $runtimeEnvironments;

  /**
   * @var HardwareGroups
   * @inject
   */
  public $hardwareGroups;

  /**
   * @var UploadedFiles
   * @inject
   */
  public $uploadedFiles;

  /**
   * @var SupplementaryFiles
   * @inject
   */
  public $supplementaryFiles;

  /**
   * @var SupplementaryFileStorage
   * @inject
   */
  public $supplementaryFileStorage;

  /**
   * Get a list of exercises with an optional filter
   * @GET
   * @UserIsAllowed(exercises="view-all")
   */
  public function actionDefault(string $search = NULL) {
    $exercises = $search === NULL ? $this->exercises->findAll() : $this->exercises->searchByName($search);

    $this->sendSuccessResponse($exercises);
  }

  /**
   * Get details of an exercise
   * @GET
   * @UserIsAllowed(exercises="view-detail")
   */
  public function actionDetail(string $id) {
    $exercise = $this->exercises->findOrThrow($id);
    $this->sendSuccessResponse($exercise);
  }

  /**
   * @POST
   * @UserIsAllowed(exercises="update")
   * @Param(type="post", name="name")
   * @Param(type="post", name="difficulty")
   * @Param(type="post", name="localizedAssignments", description="A description of the assignment")
   */
  public function actionUpdateDetail(string $id) {
    $req = $this->getRequest();
    $name = $req->getPost("name");
    $difficulty = $req->getPost("difficulty");

    // check if user can modify requested exercise
    $user = $this->getCurrentUser();
    $exercise = $this->exercises->findOrThrow($id);
    if (!$exercise->isAuthor($user)) {
      throw new BadRequestException("You are not author of this exercise, thus you cannot update it.");
    }

    // make changes to newly created excercise
    $exercise->setName($name);
    $exercise->setDifficulty($difficulty);

    // add new and update old localizations
    $postLocalized = $req->getPost("localizedAssignments");
    $localizedAssignments = $postLocalized && is_array($postLocalized)? $postLocalized : array();
    $usedLocale = [];
    foreach ($localizedAssignments as $localization) {
      $lang = $localization["locale"];
      $description = $localization["description"];
      $localizationName = $localization["name"];

      // create all new localized assignments
      $originalLocalized = $exercise->getLocalizedAssignmentByLocale($lang);
      $localized = new LocalizedAssignment($localizationName, $description, $lang);
      if ($originalLocalized) {
        $localized->setLocalizedAssignment($originalLocalized);
        $exercise->removeLocalizedAssignment($originalLocalized);
      }
      $exercise->addLocalizedAssignment($localized);
      $usedLocale[] = $lang;
    }

    // remove unused languages
    foreach ($exercise->getLocalizedAssignments() as $localization) {
      if (!in_array($localization->getLocale(), $usedLocale)) {
        $exercise->removeLocalizedAssignment($localization);
      }
    }

    // make changes to database
    $this->exercises->persist($exercise);
    $this->exercises->flush();
    $this->sendSuccessResponse($exercise);
  }

  /**
   * @POST
   * @UserIsAllowed(exercises="update")
   * @Param(type="post", name="runtimeConfigs", description="A description of the assignment")
   */
  public function actionUpdateRuntimeConfigs(string $id) {
    $user = $this->getCurrentUser();
    $exercise = $this->exercises->findOrThrow($id);
    if (!$exercise->isAuthor($user)) {
      throw new ForbiddenRequestException("You are not author of this exercise, thus you cannot update it.");
    }

    // add new and update old runtime configs
    $req = $this->getRequest();
    $runtimeConfigs = $req->getPost("runtimeConfigs");
    $usedConfigs = [];
    foreach ($runtimeConfigs as $runtimeConfig) {
      $name = $runtimeConfig["name"];
      $environmentId = $runtimeConfig["runtimeEnvironmentId"];
      $jobConfig = $runtimeConfig["jobConfig"];
      $environment = $this->runtimeEnvironments->get($environmentId);

      // store job configuration into file
      $jobConfigPath = $this->uploadedJobConfigStorage->storeContent($jobConfig, $user);
      if ($jobConfigPath === NULL) {
        throw new JobConfigStorageException;
      }

      // create all new runtime configs
      $originalConfig = $exercise->getRuntimeConfigByEnvironment($environment);
      $config = new SolutionRuntimeConfig($name, $environment, $jobConfigPath);
      if ($originalConfig) {
        $config->setSolutionRuntimeConfig($originalConfig);
        $exercise->removeSolutionRuntimeConfig($originalConfig);
      }
      $exercise->addSolutionRuntimeConfig($config);
      $usedConfigs[] = $environmentId;
    }

    // remove unused configs
    foreach ($exercise->getSolutionRuntimeConfigs() as $runtimeConfig) {
      if (!in_array($runtimeConfig->getRuntimeEnvironment()->getId(), $usedConfigs)) {
        $exercise->removeSolutionRuntimeConfig($runtimeConfig);
      }
    }

    // make changes to database
    $this->exercises->persist($exercise);
    $this->exercises->flush();
    $this->sendSuccessResponse($exercise);
  }

  /**
   *
   * @POST
   * @UserIsAllowed(exercises="update")
   * @param string $id
   * @throws ForbiddenRequestException
   */
  public function actionUploadSupplementaryFile(string $id) {
    $user = $this->getCurrentUser();
    $exercise = $this->exercises->findOrThrow($id);
    if (!$exercise->isAuthor($user)) {
      throw new ForbiddenRequestException("You are not author of this exercise, thus you cannot upload files for it.");
    }

    $files = $this->getRequest()->getFiles();
    if (count($files) === 0) {
      throw new BadRequestException("No file was uploaded");
    } elseif (count($files) > 1) {
      throw new BadRequestException("Too many files were uploaded");
    }

    $file = array_pop($files);
    $supplementaryFile = $this->supplementaryFileStorage->store($file, $user, $exercise);
    if ($supplementaryFile !== NULL) {
      $this->supplementaryFiles->persist($supplementaryFile);
      $this->supplementaryFiles->flush();
      $this->sendSuccessResponse($supplementaryFile);
    } else {
      throw new CannotReceiveUploadedFileException($file->getSanitizedName());
    }
  }

  /**
   *
   * @GET
   * @UserIsAllowed(exercises="update")
   * @param string $id
   * @throws ForbiddenRequestException
   */
  public function actionGetSupplementaryFiles(string $id) {
    $user = $this->getCurrentUser();
    $exercise = $this->exercises->findOrThrow($id);
    if (!$exercise->isAuthor($user)) {
      throw new ForbiddenRequestException("You are not author of this exercise, thus you cannot view supplementary files for it.");
    }

    $this->sendSuccessResponse($exercise->getSupplementaryFiles()->getValues());
  }

  /**
   * @POST
   * @UserIsAllowed(exercises="create")
   */
  public function actionCreate() {
    $user = $this->getCurrentUser();

    $exercise = Exercise::create($user);
    $this->exercises->persist($exercise);
    $this->exercises->flush();

    $this->sendSuccessResponse($exercise);
  }

  /**
   * @POST
   * @UserIsAllowed(exercises="fork")
   */
  public function actionForkFrom(string $id) {
    $exercise = $this->exercises->findOrThrow($id);
    $user = $this->getCurrentUser();

    $forkedExercise = Exercise::forkFrom($exercise, $user);
    $this->exercises->persist($forkedExercise);
    $this->exercises->flush();

    $this->sendSuccessResponse($forkedExercise);
  }

}

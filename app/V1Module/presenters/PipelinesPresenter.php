<?php

namespace App\V1Module\Presenters;

use App\Exceptions\BadRequestException;
use App\Exceptions\CannotReceiveUploadedFileException;
use App\Exceptions\ForbiddenRequestException;
use App\Exceptions\NotFoundException;
use App\Helpers\ExerciseConfig\Loader;
use App\Helpers\ExerciseConfig\Pipeline\Box\BoxService;
use App\Helpers\ExerciseFileStorage;
use App\Helpers\UploadedFileStorage;
use App\Model\Entity\PipelineConfig;
use App\Model\Entity\UploadedFile;
use App\Model\Repository\Exercises;
use App\Model\Repository\UploadedFiles;
use App\Security\ACL\IExercisePermissions;
use App\Security\ACL\IPipelinePermissions;
use App\Model\Repository\Pipelines;
use App\Model\Entity\Pipeline;
use App\Helpers\ExerciseConfig\Validator as ConfigValidator;
use Exception;


/**
 * Endpoints for pipelines manipulation
 * @LoggedIn
 */

class PipelinesPresenter extends BasePresenter {

  /**
   * @var IPipelinePermissions
   * @inject
   */
  public $pipelineAcl;

  /**
   * @var IExercisePermissions
   * @inject
   */
  public $exerciseAcl;

  /**
   * @var Pipelines
   * @inject
   */
  public $pipelines;

  /**
   * @var Exercises
   * @inject
   */
  public $exercises;

  /**
   * @var Loader
   * @inject
   */
  public $exerciseConfigLoader;

  /**
   * @var BoxService
   * @inject
   */
  public $boxService;

  /**
   * @var ConfigValidator
   * @inject
   */
  public $configValidator;

  /**
   * @var UploadedFiles
   * @inject
   */
  public $uploadedFiles;

  /**
   * @var ExerciseFileStorage
   * @inject
   */
  public $supplementaryFileStorage;

  /**
   * @var UploadedFileStorage
   * @inject
   */
  public $uploadedFileStorage;


  /**
   * Get a list of default boxes which might be used in pipeline.
   * @GET
   * @throws ForbiddenRequestException
   */
  public function actionGetDefaultBoxes() {
    if (!$this->pipelineAcl->canViewAll()) {
      throw new ForbiddenRequestException("You cannot list default boxes.");
    }

    $boxes = $this->boxService->getAllBoxes();
    $this->sendSuccessResponse($boxes);
  }

  /**
   * Get a list of pipelines with an optional filter
   * @GET
   * @param string $search text which will be searched in pipeline names
   * @throws ForbiddenRequestException
   */
  public function actionGetPipelines(string $search = null) {
    if (!$this->pipelineAcl->canViewAll()) {
      throw new ForbiddenRequestException("You cannot list all pipelines.");
    }

    $pipelines = $this->pipelines->searchByName($search);
    $pipelines = array_filter($pipelines, function (Pipeline $pipeline) {
      return $this->pipelineAcl->canViewDetail($pipeline);
    });
    $this->sendSuccessResponse($pipelines);
  }

  /**
   * Create pipeline.
   * @POST
   * @throws ForbiddenRequestException
   * @throws NotFoundException
   */
  public function actionCreatePipeline() {
    if (!$this->pipelineAcl->canCreate()) {
      throw new ForbiddenRequestException("You are not allowed to create pipeline.");
    }

    // create pipeline entity, persist it and return it
    $pipeline = Pipeline::create($this->getCurrentUser());
    $pipeline->setName("Pipeline by {$this->getCurrentUser()->getName()}");
    $this->pipelines->persist($pipeline);

    $this->sendSuccessResponse($pipeline);
  }

  /**
   * Fork pipeline, if exercise identification is given pipeline is forked
   * to specified exercise.
   * @POST
   * @param string $id identification of pipeline
   * @Param(type="post", name="exerciseId", description="Exercise identification", required=false)
   * @throws ForbiddenRequestException
   */
  public function actionForkPipeline(string $id) {
    $req = $this->getRequest();
    $exerciseId = $req->getPost("exerciseId");
    $exercise = $exerciseId ? $this->exercises->findOrThrow($exerciseId) : null;
    $pipeline = $this->pipelines->findOrThrow($id);

    if (!$this->pipelineAcl->canFork($pipeline) ||
        ($exercise && !$this->exerciseAcl->canCreatePipeline($exercise))) {
      throw new ForbiddenRequestException("You are not allowed to fork pipeline.");
    }

    // fork pipeline entity, persist it and return it
    $pipeline = Pipeline::forkFrom($this->getCurrentUser(), $pipeline, $exercise);
    $this->pipelines->persist($pipeline);
    $this->sendSuccessResponse($pipeline);
  }

  /**
   * Delete an pipeline
   * @DELETE
   * @param string $id
   * @throws ForbiddenRequestException
   */
  public function actionRemovePipeline(string $id) {
    /** @var Pipeline $pipeline */
    $pipeline = $this->pipelines->findOrThrow($id);
    if (!$this->pipelineAcl->canRemove($pipeline)) {
      throw new ForbiddenRequestException("You are not allowed to remove this pipeline.");
    }

    $this->pipelines->remove($pipeline);
    $this->sendSuccessResponse("OK");
  }

  /**
   * Get pipeline based on given identification.
   * @GET
   * @param string $id Identifier of the pipeline
   * @throws ForbiddenRequestException
   * @throws NotFoundException
   */
  public function actionGetPipeline(string $id) {
    /** @var Pipeline $pipeline */
    $pipeline = $this->pipelines->findOrThrow($id);
    if (!$this->pipelineAcl->canViewDetail($pipeline)) {
      throw new ForbiddenRequestException("You are not allowed to get this pipeline.");
    }

    $this->sendSuccessResponse($pipeline);
  }

  /**
   * Update pipeline with given data.
   * @POST
   * @param string $id Identifier of the pipeline
   * @Param(type="post", name="name", validation="string:2..", description="Name of the pipeline")
   * @Param(type="post", name="version", validation="numericint", description="Version of the edited pipeline")
   * @Param(type="post", name="description", description="Human readable description of pipeline")
   * @Param(type="post", name="pipeline", description="Pipeline configuration")
   * @throws ForbiddenRequestException
   * @throws NotFoundException
   * @throws BadRequestException
   */
  public function actionUpdatePipeline(string $id) {
    /** @var Pipeline $pipeline */
    $pipeline = $this->pipelines->findOrThrow($id);
    if (!$this->pipelineAcl->canUpdate($pipeline)) {
      throw new ForbiddenRequestException("You are not allowed to update this pipeline.");
    }

    $req = $this->getRequest();
    $version = intval($req->getPost("version"));
    if ($version !== $pipeline->getVersion()) {
      throw new BadRequestException("The pipeline was edited in the meantime and the version has changed. Current version is {$pipeline->getVersion()}.");
    }

    // update fields of the pipeline
    $name = $req->getPost("name");
    $description = $req->getPost("description");
    $pipeline->setName($name);
    $pipeline->setDescription($description);
    $pipeline->setUpdatedAt(new \DateTime);
    $pipeline->incrementVersion();

    // get new configuration from parameters, parse it and check for format errors
    $pipelinePost = $req->getPost("pipeline");
    $pipelineArr = !empty($pipelinePost) ? $pipelinePost : array();
    $pipelineConfig = $this->exerciseConfigLoader->loadPipeline($pipelineArr);
    $oldConfig = $pipeline->getPipelineConfig();

    // validate new pipeline configuration
    $this->configValidator->validatePipeline($pipelineConfig);

    // create new pipeline configuration based on given data and store it in pipeline entity
    $newConfig = new PipelineConfig((string) $pipelineConfig, $this->getCurrentUser(), $oldConfig);
    $pipeline->setPipelineConfig($newConfig);
    $this->pipelines->flush();

    $this->sendSuccessResponse($pipeline);
  }

  /**
   * Check if the version of the pipeline is up-to-date.
   * @POST
   * @Param(type="post", name="version", validation="numericint", description="Version of the pipeline.")
   * @param string $id Identifier of the pipeline
   * @throws ForbiddenRequestException
   */
  public function actionValidatePipeline(string $id) {
    $pipeline = $this->pipelines->findOrThrow($id);

    if (!$this->pipelineAcl->canUpdate($pipeline)) {
      throw new ForbiddenRequestException("You cannot modify this pipeline.");
    }

    $req = $this->getRequest();
    $version = intval($req->getPost("version"));

    $this->sendSuccessResponse([
      "versionIsUpToDate" => $pipeline->getVersion() === $version
    ]);
  }

  /**
   * Associate supplementary files with a pipeline and upload them to remote file server
   * @POST
   * @Param(type="post", name="files", description="Identifiers of supplementary files")
   * @param string $id identification of pipeline
   * @throws BadRequestException
   * @throws CannotReceiveUploadedFileException
   * @throws ForbiddenRequestException
   */
  public function actionUploadSupplementaryFiles(string $id) {
    $pipeline = $this->pipelines->findOrThrow($id);
    if (!$this->pipelineAcl->canUpdate($pipeline)) {
      throw new ForbiddenRequestException("You cannot update this pipeline.");
    }

    $files = $this->uploadedFiles->findAllById($this->getRequest()->getPost("files"));
    $supplementaryFiles = [];
    $deletedFiles = [];

    /** @var UploadedFile $file */
    foreach ($files as $file) {
      if (get_class($file) !== UploadedFile::class) {
        throw new ForbiddenRequestException("File {$file->getId()} was already used somewhere else");
      }

      $supplementaryFiles[] = $pipelineFile = $this->supplementaryFileStorage->store($file, null, $pipeline);
      $this->uploadedFiles->persist($pipelineFile, FALSE);
      $this->uploadedFiles->remove($file, FALSE);
      $deletedFiles[] = $file;
    }

    $this->uploadedFiles->flush();

    /** @var UploadedFile $file */
    foreach ($deletedFiles as $file) {
      try {
        $this->uploadedFileStorage->delete($file);
      } catch (Exception $e) {}
    }

    $this->sendSuccessResponse($supplementaryFiles);
  }

  /**
   * Get list of all supplementary files for a pipeline
   * @GET
   * @param string $id identification of pipeline
   * @throws ForbiddenRequestException
   */
  public function actionGetSupplementaryFiles(string $id) {
    $pipeline = $this->pipelines->findOrThrow($id);
    if (!$this->pipelineAcl->canViewDetail($pipeline)) {
      throw new ForbiddenRequestException("You cannot view supplementary files for this pipeline.");
    }

    $this->sendSuccessResponse($pipeline->getSupplementaryEvaluationFiles()->getValues());
  }

}

<?php
namespace App\Security\ACL;


use App\Model\Entity\Exercise;

interface IExercisePermissions {
  function canViewAll(): bool;
  function canViewDetail(Exercise $exercise): bool;
  function canUpdate(Exercise $exercise): bool;
  function canCreate(): bool;
  function canRemove(Exercise $exercise): bool;
  function canFork(Exercise $exercise): bool;
  function canViewLimits(Exercise $exercise): bool;
  function canSetLimits(Exercise $exercise): bool;
  function canAddReferenceSolution(Exercise $exercise): bool;
  function canEvaluateReferenceSolution(Exercise $exercise): bool;
}
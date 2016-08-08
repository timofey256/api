<?php

namespace App\Model\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use JsonSerializable;
use forxer\Gravatar\Gravatar;

/**
 * @ORM\Entity
 */
class User implements JsonSerializable
{
    use \Kdyby\Doctrine\Entities\MagicAccessors;

    /**
     * @ORM\Id
     * @ORM\Column(type="guid")
     * @ORM\GeneratedValue(strategy="UUID")
     */
    protected $id;

    public function getId() {
      return $this->id;
    }

    /**
     * @ORM\Column(type="string")
     */
    protected $degreesBeforeName;

    /**
     * @ORM\Column(type="string")
     */
    protected $firstName;

    /**
     * @ORM\Column(type="string")
     */
    protected $lastName;

    public function getName() {
      return trim("{$this->degreesBeforeName} {$this->firstName} {$this->lastName} {$this->degreesAfterName}");
    }

    /**
     * @ORM\Column(type="string")
     */
    protected $degreesAfterName;

    /**
     * @ORM\Column(type="string")
     */
    protected $email;

    /**
     * @ORM\Column(type="string")
     */
    protected $avatarUrl;

    public function getAvatarUrl() { return $this->avatarUrl; }

    /**
     * @ORM\Column(type="boolean")
     */
    protected $isVerified;

    /**
     * @ORM\Column(type="boolean")
     */
    protected $isAllowed;

    public function isAllowed() { return $this->isAllowed; }

    /**
     * @ORM\ManyToMany(targetEntity="Group", inversedBy="students")
     * @ORM\JoinTable(name="group_student")
     */
    protected $studentOf;

    public function getGroupsAsStudent() {
        return $this->studentOf;
    }

    /**
     * @ORM\ManyToMany(targetEntity="Group", inversedBy="supervisors")
     * @ORM\JoinTable(name="group_supervisor")
     */
    protected $supervisorOf;

    public function getGroupsAsSupervisor() {
        return $this->supervisorOf;
    }
    
    /**
     * @ORM\OneToMany(targetEntity="Exercise", mappedBy="author")
     */
    protected $exercises;
    
    public function getUsersExercises() {
      return $this->exercises;
    }

    /**
     * @ORM\ManyToOne(targetEntity="Role")
     */
    protected $role;

    public function getRole() { return $this->role; }

    public function jsonSerialize() {
      return [
        "id" => $this->id,
        "fullName" => $this->getName(),
        "name" => [
          "degreesBeforeName" => $this->degreesBeforeName,
          "firstName" => $this->firstName,
          "lastName" => $this->lastName,
          "degreesAfterName" => $this->degreesAfterName,
        ],
        "avatarUrl" => $this->avatarUrl,
        "isVerified" => $this->isVerified,
        "role" => $this->role,
        "groups" => [
          "studentOf" => array_map(function ($group) { return $group->getId(); }, $this->getGroupsAsStudent()->toArray()),
          "supervisorOf" => array_map(function ($group) { return $group->getId(); }, $this->getGroupsAsSupervisor()->toArray())
        ]
      ];
    }
  
    /**
     * The name of the user
     * @param  string $name   Name of the user     
     * @param  string $email  Email address of the user     
     * @return User
     */
    public static function createUser(
      string $email,
      string $firstName,
      string $lastName,
      string $degreesBeforeName,
      string $degreesAfterName,
      Role   $role
    ) {
        $user = new User;
        $user->firstName = $firstName;
        $user->lastName = $lastName;
        $user->degreesBeforeName = $degreesBeforeName;
        $user->degreesAfterName = $degreesAfterName;
        $user->email = $email;
        $user->avatarUrl = Gravatar::image($email, 45, "retro", "g", "jpg", true, false);
        $user->isVerified = FALSE;
        $user->isAllowed = TRUE;
        $user->studentOf = new ArrayCollection;
        $user->supervisorOf = new ArrayCollection;
        $user->exercises = new ArrayCollection;
        $user->role = $role;
        return $user;
    }
}

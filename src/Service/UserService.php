<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class UserService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserRepository $userRepository,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function createUser(array $data): User|array
    {
        // Basic check for required fields
        if (empty($data['email']) || empty($data['password'])) {
            return ['error' => 'Email and password are required.'];
        }

        // Check if user already exists
        if ($this->userRepository->findOneBy(['email' => $data['email']])) {
            return ['error' => 'Email already exists.'];
        }

        $user = new User();
        $user->setEmail($data['email']);

        // TODO: Add role setting if needed
        // $user->setRoles(['ROLE_USER']);

        // Hash password
        $hashedPassword = $this->passwordHasher->hashPassword(
            $user,
            $data['password']
        );
        $user->setPassword($hashedPassword);

        // Optional: Validate the User entity before persisting
        $errors = $this->validator->validate($user);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $violation) {
                $errorMessages[$violation->getPropertyPath()][] = $violation->getMessage();
            }

            return ['validation_errors' => $errorMessages];
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}

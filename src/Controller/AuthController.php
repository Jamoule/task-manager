<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\UserService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

#[Route('/api')]
class AuthController extends AbstractController
{
    public function __construct(
        private readonly UserService $userService,
        private readonly JWTTokenManagerInterface $jwtManager,
        private readonly SerializerInterface $serializer,
    ) {
    }

    #[Route('/register', name: 'auth_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (null === $data) {
            return $this->json(['message' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        // Attempt to create the user using the existing service
        $result = $this->userService->createUser($data);

        // Handle potential errors from userService
        if (!$result instanceof User) {
            if (isset($result['validation_errors'])) {
                return $this->json(['success' => false, 'message' => 'Validation failed', 'errors' => $result['validation_errors']], Response::HTTP_BAD_REQUEST);
            } elseif (isset($result['error'])) {
                $statusCode = ('Email already exists.' === $result['error'])
                                ? Response::HTTP_CONFLICT
                                : Response::HTTP_BAD_REQUEST;

                return $this->json(['success' => false, 'message' => $result['error']], $statusCode);
            } else {
                return $this->json(['success' => false, 'message' => 'An unexpected error occurred during registration.'], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        // User created successfully, now generate JWT
        $user = $result;
        $token = $this->jwtManager->create($user);

        // Serialize user data (excluding password) for the response
        $jsonUser = $this->serializer->serialize($user, 'json', ['groups' => 'user:read']);

        return new JsonResponse([
            'success' => true,
            'token' => $token,
            'user' => json_decode($jsonUser), // Decode the JSON string to embed the object
        ], Response::HTTP_CREATED);
    }

    // You might want to add a separate /api/login endpoint here later
    // using username/password to generate a token for existing users.
}

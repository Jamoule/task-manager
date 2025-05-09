<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\TaskService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/tasks')]
final class TaskController extends AbstractController
{
    public function __construct(
        private readonly TaskService $taskService,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly Security $security,
    ) {
    }

    #[Route('', name: 'task_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (null === $data) {
            return $this->json(['message' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        if (!isset($data['title'])) {
            return $this->json(['message' => 'Missing required field: title'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User|null $currentUser */
        $currentUser = $this->security->getUser();

        if (!$currentUser) {
            return $this->json(['message' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $task = $this->taskService->createTask($data, $currentUser);

            return $this->json(['success' => true], Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->json(['message' => 'An error occurred while creating the task.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('', name: 'task_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $filters = $request->query->all('filter');
            $sortBy = $request->query->get('sort', 'createdAt');
            $order = $request->query->get('order', 'ASC');

            $tasks = $this->taskService->getTasks($filters, $sortBy, $order);
            $jsonTasks = $this->serializer->serialize($tasks, 'json', ['groups' => 'task:read']);

            return new JsonResponse($jsonTasks, Response::HTTP_OK, [], true);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->json(['message' => 'An error occurred while fetching tasks.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}', name: 'task_get', methods: ['GET'])]
    public function getTask(int $id): JsonResponse
    {
        $task = $this->taskService->getTask($id);

        if (!$task) {
            return $this->json(['message' => 'Task not found'], Response::HTTP_NOT_FOUND);
        }

        $jsonTask = $this->serializer->serialize($task, 'json', ['groups' => 'task:read']);

        return new JsonResponse($jsonTask, Response::HTTP_OK, [], true);
    }

    #[Route('/{id}', name: 'task_update_put', methods: ['PUT'])]
    public function updatePut(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (null === $data) {
            return $this->json(['message' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $task = $this->taskService->updateTask($id, $data, true);
            if (!$task) {
                return $this->json(['message' => 'Task not found'], Response::HTTP_NOT_FOUND);
            }
            $jsonTask = $this->serializer->serialize($task, 'json', ['groups' => 'task:read']);

            return new JsonResponse($jsonTask, Response::HTTP_OK, [], true);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->json(['message' => 'An error occurred while updating the task.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}', name: 'task_update_patch', methods: ['PATCH'])]
    public function updatePatch(int $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (null === $data) {
            return $this->json(['message' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $task = $this->taskService->updateTask($id, $data, false);
            if (!$task) {
                return $this->json(['message' => 'Task not found'], Response::HTTP_NOT_FOUND);
            }
            $jsonTask = $this->serializer->serialize($task, 'json', ['groups' => 'task:read']);

            return new JsonResponse($jsonTask, Response::HTTP_OK, [], true);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->json(['message' => 'An error occurred while updating the task.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}', name: 'task_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        try {
            $success = $this->taskService->deleteTask($id);
            if (!$success) {
                return $this->json(['message' => 'Task not found'], Response::HTTP_NOT_FOUND);
            }

            return $this->json(null, Response::HTTP_NO_CONTENT);
        } catch (\Exception $e) {
            return $this->json(['message' => 'An error occurred while deleting the task.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

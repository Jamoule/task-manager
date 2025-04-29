<?php

namespace App\Service;

use App\Entity\Task;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class TaskService
{
    public function __construct(
        private readonly TaskRepository $taskRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository
    ) {
    }

    public function getTasks(array $filters = [], ?string $sortBy = 'createdAt', string $order = 'ASC'): array
    {
        // Basic filtering example (by status)
        $criteria = [];
        if (isset($filters['status'])) {
            $criteria['status'] = TaskStatus::tryFrom($filters['status']);
            if ($criteria['status'] === null) {
                // Handle invalid status filter
                throw new \InvalidArgumentException("Invalid status value: {$filters['status']}");
            }
        }
        // TODO: Add more filters (priority, ownerId, date ranges etc.)

        // Basic sorting example
        $orderBy = [];
        if ($sortBy && in_array($sortBy, ['createdAt', 'dueAt', 'priority', 'title', 'position'])) {
            $direction = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
            $orderBy[$sortBy] = $direction;
        } else {
            $orderBy['createdAt'] = 'ASC'; // Default sort
        }

        return $this->taskRepository->findBy($criteria, $orderBy);
    }

    public function getTask(int $id): ?Task
    {
        return $this->taskRepository->find($id);
    }

    public function createTask(array $data): Task
    {
        $task = new Task();
        $task->setTitle($data['title']);
        $task->setDescription($data['description'] ?? null);

        if (isset($data['dueAt'])) {
            $task->setDueAt(new \DateTimeImmutable($data['dueAt']));
        }
        if (isset($data['priority'])) {
            $task->setPriority(TaskPriority::from($data['priority']));
        }
        if (isset($data['status'])) {
            $task->setStatus(TaskStatus::from($data['status']));
        }
        if (isset($data['position'])) {
            $task->setPosition((int)$data['position']);
        }

        if (isset($data['ownerId'])) {
            $owner = $this->userRepository->find($data['ownerId']);
            if ($owner) {
                $task->setOwner($owner);
            } else {
                throw new \InvalidArgumentException("Owner with ID {$data['ownerId']} not found.");
            }
        }
        // TODO: Handle tags if necessary

        $this->entityManager->persist($task);
        $this->entityManager->flush();

        return $task;
    }

    public function updateTask(int $id, array $data, bool $isPut = false): ?Task
    {
        $task = $this->taskRepository->find($id);
        if (!$task) {
            return null; // Or throw an exception
        }

        // Handle fields present in data
        if (array_key_exists('title', $data)) {
            $task->setTitle($data['title']);
        } elseif ($isPut) {
            // PUT requires all fields or set to null/default if applicable
            throw new \InvalidArgumentException("Missing required field: title for PUT request.");
        }

        if (array_key_exists('description', $data)) {
            $task->setDescription($data['description']);
        } elseif ($isPut) {
            $task->setDescription(null);
        }

        if (array_key_exists('dueAt', $data)) {
            $task->setDueAt($data['dueAt'] ? new \DateTimeImmutable($data['dueAt']) : null);
        } elseif ($isPut) {
             throw new \InvalidArgumentException("Missing required field: dueAt for PUT request.");
        }

        if (array_key_exists('priority', $data)) {
            $task->setPriority(TaskPriority::from($data['priority']));
        } elseif ($isPut) {
            // Assuming MEDIUM is the default or required
            $task->setPriority(TaskPriority::MEDIUM);
        }

        if (array_key_exists('status', $data)) {
            $task->setStatus(TaskStatus::from($data['status']));
        } elseif ($isPut) {
            // Assuming PENDING is the default or required
             $task->setStatus(TaskStatus::PENDING);
        }

        if (array_key_exists('position', $data)) {
            $task->setPosition((int)$data['position']);
        } elseif ($isPut) {
             throw new \InvalidArgumentException("Missing required field: position for PUT request.");
        }

        if (array_key_exists('ownerId', $data)) {
            $owner = $this->userRepository->find($data['ownerId']);
            if ($owner) {
                $task->setOwner($owner);
            } else {
                 throw new \InvalidArgumentException("Owner with ID {$data['ownerId']} not found.");
            }
        } elseif ($isPut) {
             throw new \InvalidArgumentException("Missing required field: ownerId for PUT request.");
        }

        // TODO: Handle tags update (add/remove)

        $this->entityManager->flush(); // Doctrine automatically tracks changes

        return $task;
    }

    public function deleteTask(int $id): bool
    {
        $task = $this->taskRepository->find($id);
        if (!$task) {
            return false; // Task not found
        }

        $this->entityManager->remove($task);
        $this->entityManager->flush();

        return true;
    }
} 
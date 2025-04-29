<?php

namespace App\Service;

use App\Entity\Task;
use App\Entity\User;
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
        // TODO: Add more filters (priority, owner, date ranges etc.) - Note: Filter by User object now, not ID.

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

    public function createTask(array $data, User $owner): Task
    {
        $task = new Task();
        $task->setTitle($data['title']);
        $task->setDescription($data['description'] ?? null);

        // Set the owner directly from the authenticated user passed in
        $task->setOwner($owner);

        if (isset($data['dueAt'])) {
            // Add validation or proper exception handling for date format
            try {
                 $task->setDueAt(new \DateTimeImmutable($data['dueAt']));
            } catch (\Exception $e) {
                 throw new \InvalidArgumentException("Invalid date format for dueAt: {$data['dueAt']}");
            }
        }
        if (isset($data['priority'])) {
            try {
                $task->setPriority(TaskPriority::from($data['priority']));
            } catch (\ValueError $e) {
                 throw new \InvalidArgumentException("Invalid priority value: {$data['priority']}");
            }
        }
        if (isset($data['status'])) {
             try {
                $task->setStatus(TaskStatus::from($data['status']));
             } catch (\ValueError $e) {
                 throw new \InvalidArgumentException("Invalid status value: {$data['status']}");
             }
        }
        if (isset($data['position'])) {
             if (!is_numeric($data['position'])) {
                 throw new \InvalidArgumentException("Invalid position value: must be a number.");
             }
            $task->setPosition((int)$data['position']);
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
             if (empty($data['title'])) { // Add basic validation
                 throw new \InvalidArgumentException("Title cannot be empty.");
             }
            $task->setTitle($data['title']);
        } elseif ($isPut) {
            throw new \InvalidArgumentException("Missing required field: title for PUT request.");
        }

        if (array_key_exists('description', $data)) {
            $task->setDescription($data['description']);
        } elseif ($isPut) {
            $task->setDescription(null); // Allow null description for PUT
        }

        if (array_key_exists('dueAt', $data)) {
            try {
                 $task->setDueAt($data['dueAt'] ? new \DateTimeImmutable($data['dueAt']) : null);
            } catch (\Exception $e) {
                 throw new \InvalidArgumentException("Invalid date format for dueAt: {$data['dueAt']}");
            }
        } elseif ($isPut) {
             // Decide if dueAt is mandatory for PUT or can be null
             $task->setDueAt(null); 
             // OR: throw new \InvalidArgumentException("Missing required field: dueAt for PUT request.");
        }

        if (array_key_exists('priority', $data)) {
             try {
                $task->setPriority(TaskPriority::from($data['priority']));
            } catch (\ValueError $e) {
                 throw new \InvalidArgumentException("Invalid priority value: {$data['priority']}");
            }
        } elseif ($isPut) {
            $task->setPriority(TaskPriority::MEDIUM); // Default for PUT if not provided
        }

        if (array_key_exists('status', $data)) {
             try {
                $task->setStatus(TaskStatus::from($data['status']));
             } catch (\ValueError $e) {
                 throw new \InvalidArgumentException("Invalid status value: {$data['status']}");
             }
        } elseif ($isPut) {
            $task->setStatus(TaskStatus::PENDING); // Default for PUT if not provided
        }

        if (array_key_exists('position', $data)) {
             if (!is_numeric($data['position'])) {
                 throw new \InvalidArgumentException("Invalid position value: must be a number.");
             }
            $task->setPosition((int)$data['position']);
        } elseif ($isPut) {
             // Decide if position is mandatory for PUT or has a default
             throw new \InvalidArgumentException("Missing required field: position for PUT request."); // Or set a default
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
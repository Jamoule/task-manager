<?php

namespace App\Tests\Service;

use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Service\TaskService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

class TaskServiceTest extends TestCase
{
    private MockObject|TaskRepository $taskRepository;
    private MockObject|EntityManagerInterface $entityManager;
    private MockObject|UserRepository $userRepository;
    private TaskService $taskService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->taskRepository = $this->createMock(TaskRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->userRepository = $this->createMock(UserRepository::class);

        $this->taskService = new TaskService(
            $this->taskRepository,
            $this->entityManager,
            $this->userRepository
        );
    }

    // Test methods will go here

    public function testGetTasks(): void
    {
        $task1 = (new Task())->setTitle('Test Task 1');
        $task2 = (new Task())->setTitle('Test Task 2');
        $tasks = [$task1, $task2];

        $this->taskRepository->expects($this->once())
            ->method('findBy')
            ->with([], ['createdAt' => 'ASC']) // Default sort
            ->willReturn($tasks);

        $result = $this->taskService->getTasks();
        $this->assertSame($tasks, $result);
    }

    public function testGetTasksWithFiltersAndSort(): void
    {
        $task1 = (new Task())->setTitle('Filtered Task');
        $tasks = [$task1];
        $filters = ['status' => 'pending']; // Use string value as passed from controller
        $sortBy = 'title';
        $order = 'DESC';

        $expectedCriteria = ['status' => TaskStatus::PENDING];
        $expectedOrderBy = ['title' => 'DESC'];

        $this->taskRepository->expects($this->once())
            ->method('findBy')
            ->with($expectedCriteria, $expectedOrderBy)
            ->willReturn($tasks);

        $result = $this->taskService->getTasks($filters, $sortBy, $order);
        $this->assertSame($tasks, $result);
    }

    public function testGetTasksWithInvalidStatusFilter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid status value: invalid-status');

        $this->taskRepository->expects($this->never())->method('findBy');

        $this->taskService->getTasks(['status' => 'invalid-status']);
    }

    public function testGetTaskFound(): void
    {
        $taskId = 1;
        $task = (new Task())->setTitle('Found Task');

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn($task);

        $result = $this->taskService->getTask($taskId);
        $this->assertSame($task, $result);
    }

    public function testGetTaskNotFound(): void
    {
        $taskId = 999;

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn(null);

        $result = $this->taskService->getTask($taskId);
        $this->assertNull($result);
    }

    public function testCreateTaskSuccess(): void
    {
        $ownerId = 10;
        $owner = (new User()); // Mock or real user entity
        $taskData = [
            'title' => 'New Task',
            'description' => 'Description here',
            'dueAt' => '2024-12-31T23:59:59+00:00',
            'priority' => 'high',
            'status' => 'in_progress',
            'position' => 1,
            'ownerId' => $ownerId,
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with($ownerId)
            ->willReturn($owner);

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (Task $task) use ($taskData, $owner) {
                $this->assertEquals($taskData['title'], $task->getTitle());
                $this->assertEquals($taskData['description'], $task->getDescription());
                $this->assertEquals(new \DateTimeImmutable($taskData['dueAt']), $task->getDueAt());
                $this->assertEquals(TaskPriority::HIGH, $task->getPriority());
                $this->assertEquals(TaskStatus::IN_PROGRESS, $task->getStatus());
                $this->assertEquals($taskData['position'], $task->getPosition());
                $this->assertSame($owner, $task->getOwner());
                return true;
            }));

        $this->entityManager->expects($this->once())->method('flush');

        $createdTask = $this->taskService->createTask($taskData);

        // Assertions on the returned task (optional, as we assert during persist)
        $this->assertInstanceOf(Task::class, $createdTask);
        $this->assertEquals($taskData['title'], $createdTask->getTitle());
    }

    public function testCreateTaskOwnerNotFound(): void
    {
        $ownerId = 999;
        $taskData = [
            'title' => 'Task with missing owner',
            'ownerId' => $ownerId,
        ];

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with($ownerId)
            ->willReturn(null);

        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Owner with ID {$ownerId} not found.");

        $this->taskService->createTask($taskData);
    }

    public function testUpdateTaskPatchSuccess(): void
    {
        $taskId = 1;
        $ownerId = 11;
        $newOwner = (new User());
        $task = (new Task())->setTitle('Original Title'); // Assume task exists
        $updateData = [
            'title' => 'Updated Title',
            'status' => 'done',
            'ownerId' => $ownerId,
        ];

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn($task);

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with($ownerId)
            ->willReturn($newOwner);

        $this->entityManager->expects($this->once())->method('flush');

        $updatedTask = $this->taskService->updateTask($taskId, $updateData, false); // isPut = false for PATCH

        $this->assertSame($task, $updatedTask);
        $this->assertEquals('Updated Title', $updatedTask->getTitle());
        $this->assertEquals(TaskStatus::DONE, $updatedTask->getStatus());
        $this->assertSame($newOwner, $updatedTask->getOwner());
        // Description, dueAt etc. should remain unchanged from original task object
    }

    public function testUpdateTaskPutSuccess(): void
    {
        $taskId = 2;
        $ownerId = 12;
        $owner = (new User());
        $task = (new Task()); // Assume task exists
        $updateData = [
            'title' => 'Full Update Title',
            'description' => 'Full Desc',
            'dueAt' => '2025-01-01T12:00:00+00:00',
            'priority' => 'low',
            'status' => 'pending',
            'position' => 5,
            'ownerId' => $ownerId,
        ];

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn($task);

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with($ownerId)
            ->willReturn($owner);

        $this->entityManager->expects($this->once())->method('flush');

        $updatedTask = $this->taskService->updateTask($taskId, $updateData, true); // isPut = true for PUT

        $this->assertSame($task, $updatedTask);
        $this->assertEquals($updateData['title'], $updatedTask->getTitle());
        $this->assertEquals($updateData['description'], $updatedTask->getDescription());
        $this->assertEquals(new \DateTimeImmutable($updateData['dueAt']), $updatedTask->getDueAt());
        $this->assertEquals(TaskPriority::LOW, $updatedTask->getPriority());
        $this->assertEquals(TaskStatus::PENDING, $updatedTask->getStatus());
        $this->assertEquals($updateData['position'], $updatedTask->getPosition());
        $this->assertSame($owner, $updatedTask->getOwner());
    }

    public function testUpdateTaskNotFound(): void
    {
        $taskId = 999;
        $updateData = ['title' => 'Does not matter'];

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn(null);

        $this->entityManager->expects($this->never())->method('flush');

        $result = $this->taskService->updateTask($taskId, $updateData);
        $this->assertNull($result);
    }

    public function testUpdateTaskPutMissingField(): void
    {
        $taskId = 3;
        $task = (new Task());
        $updateData = ['description' => 'Only description']; // Missing title, required for PUT

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn($task);

        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing required field: title for PUT request.');

        $this->taskService->updateTask($taskId, $updateData, true);
    }

    public function testUpdateTaskInvalidOwner(): void
    {
        $taskId = 4;
        $ownerId = 888;
        $task = (new Task());
        $updateData = ['ownerId' => $ownerId];

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn($task);

        $this->userRepository->expects($this->once())
            ->method('find')
            ->with($ownerId)
            ->willReturn(null);

        $this->entityManager->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Owner with ID {$ownerId} not found.");

        $this->taskService->updateTask($taskId, $updateData);
    }

    public function testDeleteTaskSuccess(): void
    {
        $taskId = 1;
        $task = (new Task());

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn($task);

        $this->entityManager->expects($this->once())
            ->method('remove')
            ->with($this->identicalTo($task));

        $this->entityManager->expects($this->once())
            ->method('flush');

        $result = $this->taskService->deleteTask($taskId);
        $this->assertTrue($result);
    }

    public function testDeleteTaskNotFound(): void
    {
        $taskId = 999;

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with($taskId)
            ->willReturn(null);

        $this->entityManager->expects($this->never())->method('remove');
        $this->entityManager->expects($this->never())->method('flush');

        $result = $this->taskService->deleteTask($taskId);
        $this->assertFalse($result);
    }
} 
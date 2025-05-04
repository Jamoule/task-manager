<?php

namespace App\Tests\Controller;

use App\Entity\Task;
use App\Entity\User;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class TaskControllerTest extends WebTestCase
{
    private ?EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private ?User $testUser = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // self::bootKernel(); // Removed explicit kernel boot

        // Get container and services after creating the client
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        $userRepository = static::getContainer()->get(UserRepository::class);
        $taskRepository = static::getContainer()->get(TaskRepository::class);

        $this->testUser = $userRepository->findOneBy(['email' => 'testuser@example.com']);

        if (!$this->testUser) {
            $this->testUser = new User();
            $this->testUser->setEmail('testuser@example.com');
            $this->testUser->setPassword('password');
            $this->entityManager->persist($this->testUser);
            $this->entityManager->flush();
        }

        // Clear existing tasks for the test user before each test
        $tasks = $taskRepository->findBy(['owner' => $this->testUser]);
        foreach ($tasks as $task) {
            $this->entityManager->remove($task);
        }
        $this->entityManager->flush();

        $this->client->loginUser($this->testUser); // Login user using the client
    }

    private function createTaskInDb(array $data = []): Task
    {
        $task = new Task();
        $task->setTitle($data['title'] ?? 'Default Test Title');
        $task->setDescription($data['description'] ?? 'Default Test Description');
        $task->setOwner($this->testUser);
        $task->setPriority($data['priority'] ?? TaskPriority::MEDIUM);
        $task->setStatus($data['status'] ?? TaskStatus::PENDING);
        $task->setPosition($data['position'] ?? 1);
        // Always set a dueAt to satisfy potential NOT NULL constraints
        $task->setDueAt($data['dueAt'] ?? new \DateTimeImmutable());
        // if (isset($data['dueAt'])) {
        //     $task->setDueAt(new \DateTimeImmutable($data['dueAt']));
        // }

        // Use the entity manager obtained in setUp
        $this->entityManager->persist($task);
        $this->entityManager->flush();
        return $task;
    }

    public function testCreateTaskSuccess(): void
    {
        $taskData = [
            'title' => 'Test Task Title',
            'description' => 'Test Task Description',
            'priority' => 'medium',
            'status' => 'pending',
            'position' => 1,
        ];

        $this->client->request(
            'POST',
            '/api/tasks',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($taskData)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $responseContent = $this->client->getResponse()->getContent();
        $this->assertJson($responseContent);
        $responseData = json_decode($responseContent, true);
        $this->assertTrue($responseData['success']);

        // Verify in DB
        $taskRepository = static::getContainer()->get(TaskRepository::class);
        $dbTask = $taskRepository->findOneBy(['title' => 'Test Task Title']);
        $this->assertNotNull($dbTask);
        $this->assertSame('Test Task Description', $dbTask->getDescription());
        $this->assertSame($this->testUser->getId(), $dbTask->getOwner()->getId());
        $this->assertSame(TaskPriority::MEDIUM, $dbTask->getPriority());
        $this->assertSame(TaskStatus::PENDING, $dbTask->getStatus());
        $this->assertSame(1, $dbTask->getPosition());
    }

    public function testCreateTaskFailureMissingTitle(): void
    {
        $taskData = [
            'description' => 'Test Task Description',
            'priority' => 'medium',
            'status' => 'pending',
            'position' => 1,
        ];

        $this->client->request(
            'POST',
            '/api/tasks',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($taskData)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testCreateTaskFailureInvalidData(): void
    {
        $taskData = [
            'title' => 'Invalid Data Task',
            'priority' => 'invalid_priority',
        ];

        $this->client->request(
            'POST',
            '/api/tasks',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($taskData)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testCreateTaskFailureUnauthenticated(): void
    {
        $this->client->insulate(); // Clear authentication state
        $this->client->restart();  // Restart the client to clear history/cookies and ensure kernel is ready

        $taskData = ['title' => 'Unauth Create'];

        $this->client->request(
            'POST',
            '/api/tasks',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($taskData)
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }


    public function testListTasksSuccess(): void
    {
        $this->createTaskInDb(['title' => 'Task One']);
        $this->createTaskInDb(['title' => 'Task Two']);

        $this->client->request('GET', '/api/tasks');

        $this->assertResponseIsSuccessful();
        $responseContent = $this->client->getResponse()->getContent();
        $this->assertJson($responseContent);
        $responseData = json_decode($responseContent, true);
        $this->assertIsArray($responseData);
        $this->assertCount(2, $responseData);
        // Note: Order isn't guaranteed unless specified, adjust assertion if needed
        // $this->assertEquals('Task One', $responseData[0]['title']);
        // $this->assertEquals('Task Two', $responseData[1]['title']);
        $titles = array_column($responseData, 'title');
        $this->assertContains('Task One', $titles);
        $this->assertContains('Task Two', $titles);
    }

    public function testListTasksWithFiltersAndSort(): void
    {
        $this->createTaskInDb(['title' => 'A Pending', 'status' => TaskStatus::PENDING, 'priority' => TaskPriority::HIGH]);
        $this->createTaskInDb(['title' => 'B Completed', 'status' => TaskStatus::COMPLETED, 'priority' => TaskPriority::LOW]);
        $this->createTaskInDb(['title' => 'C Pending', 'status' => TaskStatus::PENDING, 'priority' => TaskPriority::MEDIUM]);

        $this->client->request('GET', '/api/tasks?filter[status]=pending&sort=priority&order=DESC');

        $this->assertResponseIsSuccessful();
        $responseContent = $this->client->getResponse()->getContent();
        $responseData = json_decode($responseContent, true);

        $this->assertCount(2, $responseData);
        $this->assertEquals('A Pending', $responseData[0]['title']);
        $this->assertEquals(TaskPriority::HIGH->value, $responseData[0]['priority']);
        $this->assertEquals('C Pending', $responseData[1]['title']);
        $this->assertEquals(TaskPriority::MEDIUM->value, $responseData[1]['priority']);
    }

    public function testShowTaskSuccess(): void
    {
        $task = $this->createTaskInDb(['title' => 'Show Me']);

        $this->client->request('GET', '/api/tasks/' . $task->getId());

        $this->assertResponseIsSuccessful();
        $responseContent = $this->client->getResponse()->getContent();
        $responseData = json_decode($responseContent, true);
        $this->assertEquals('Show Me', $responseData['title']);
        $this->assertEquals($task->getId(), $responseData['id']);
    }

    public function testShowTaskNotFound(): void
    {
        $this->client->request('GET', '/api/tasks/99999');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testUpdateTaskPutSuccess(): void
    {
        $task = $this->createTaskInDb();
        $updateData = [
            'title' => 'Updated Title PUT',
            'description' => 'Updated Description PUT',
            'priority' => 'high',
            'status' => 'completed',
            'position' => 10,
            'dueAt' => '2025-01-01T12:00:00+00:00'
        ];

        $this->client->request(
            'PUT',
            '/api/tasks/' . $task->getId(),
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode($updateData)
        );

        $this->assertResponseIsSuccessful();
        // Refresh the entity from the EntityManager
        $this->entityManager->refresh($task);

        $this->assertEquals('Updated Title PUT', $task->getTitle());
        $this->assertEquals('Updated Description PUT', $task->getDescription());
        $this->assertEquals(TaskPriority::HIGH, $task->getPriority());
        $this->assertEquals(TaskStatus::COMPLETED, $task->getStatus());
        $this->assertEquals(10, $task->getPosition());
        $this->assertEquals('2025-01-01T12:00:00+00:00', $task->getDueAt()->format(\DateTimeInterface::RFC3339));
    }

    public function testUpdateTaskPutNotFound(): void
    {
        $updateData = ['title' => 'Update Non Existent'];
        $this->client->request(
            'PUT',
            '/api/tasks/99999',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode($updateData)
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testUpdateTaskPutInvalidData(): void
    {
        $task = $this->createTaskInDb();
        $updateData = ['title' => 'Invalid PUT', 'priority' => 'invalid'];

        $this->client->request(
            'PUT',
            '/api/tasks/' . $task->getId(),
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode($updateData)
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testUpdateTaskPatchSuccess(): void
    {
        $task = $this->createTaskInDb(['title' => 'Original Title', 'status' => TaskStatus::PENDING]);
        $updateData = ['status' => 'in_progress'];

        $this->client->request(
            'PATCH',
            '/api/tasks/' . $task->getId(),
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode($updateData)
        );

        $this->assertResponseIsSuccessful();
        // Refresh the entity from the EntityManager
        $this->entityManager->refresh($task);

        $this->assertEquals('Original Title', $task->getTitle());
        $this->assertEquals(TaskStatus::IN_PROGRESS, $task->getStatus());
    }

    public function testUpdateTaskPatchNotFound(): void
    {
        $updateData = ['title' => 'Patch Non Existent'];
        $this->client->request(
            'PATCH',
            '/api/tasks/99999',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode($updateData)
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testUpdateTaskPatchInvalidData(): void
    {
        $task = $this->createTaskInDb();
        $updateData = ['status' => 'invalid_status'];

        $this->client->request(
            'PATCH',
            '/api/tasks/' . $task->getId(),
            [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode($updateData)
        );
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testDeleteTaskSuccess(): void
    {
        $task = $this->createTaskInDb();
        $taskId = $task->getId();

        $this->client->request('DELETE', '/api/tasks/' . $taskId);

        $this->assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        // Verify in DB
        $taskRepository = static::getContainer()->get(TaskRepository::class);
        $deletedTask = $taskRepository->find($taskId);
        $this->assertNull($deletedTask);
    }

    public function testDeleteTaskNotFound(): void
    {
        $this->client->request('DELETE', '/api/tasks/99999');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->entityManager) {
            $this->entityManager->close();
            $this->entityManager = null;
        }
        $this->testUser = null;
    }
} 
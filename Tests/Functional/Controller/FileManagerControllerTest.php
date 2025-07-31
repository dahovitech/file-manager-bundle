<?php

namespace Dahovitech\FileManagerBundle\Tests\Functional\Controller;

use Dahovitech\FileManagerBundle\Entity\File;
use Dahovitech\FileManagerBundle\Entity\Folder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FileManagerControllerTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        
        // Clean database
        $this->cleanDatabase();
    }

    protected function tearDown(): void
    {
        $this->cleanDatabase();
        parent::tearDown();
    }

    public function testIndexPage(): void
    {
        $this->client->request('GET', '/file-manager/');
        
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Gestionnaire de fichiers');
    }

    public function testApiFilesList(): void
    {
        // Create test data
        $file = $this->createTestFile();
        
        $this->client->request('GET', '/file-manager/api/files');
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('pagination', $response);
    }

    public function testApiFileDetails(): void
    {
        $file = $this->createTestFile();
        
        $this->client->request('GET', '/file-manager/api/files/' . $file->getId());
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertTrue($response['success']);
        $this->assertEquals($file->getId(), $response['data']['id']);
        $this->assertEquals($file->getFilename(), $response['data']['filename']);
    }

    public function testFileUpload(): void
    {
        // Create a temporary file for upload
        $tempFile = tempnam(sys_get_temp_dir(), 'test_upload');
        file_put_contents($tempFile, 'test content');
        
        $uploadedFile = new UploadedFile(
            $tempFile,
            'test.txt',
            'text/plain',
            null,
            true
        );
        
        $this->client->request('POST', '/file-manager/upload', [], [
            'file' => $uploadedFile,
        ]);
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        
        // Clean up
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    public function testCreateFolder(): void
    {
        $this->client->request('POST', '/file-manager/folder/create', [
            'name' => 'Test Folder',
            'description' => 'Test Description',
        ]);
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        
        // Verify folder was created
        $folder = $this->entityManager->getRepository(Folder::class)->find($response['data']['id']);
        $this->assertNotNull($folder);
        $this->assertEquals('Test Folder', $folder->getName());
    }

    public function testDeleteFile(): void
    {
        $file = $this->createTestFile();
        
        $this->client->request('POST', '/file-manager/delete/' . $file->getId());
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertTrue($response['success']);
        
        // Verify file was soft deleted
        $this->entityManager->refresh($file);
        $this->assertTrue($file->isDeleted());
    }

    public function testFileServe(): void
    {
        $file = $this->createTestFile();
        
        $this->client->request('GET', '/file-manager/serve/' . $file->getId());
        
        $this->assertResponseIsSuccessful();
        $this->assertEquals($file->getMimeType(), $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testSelectFile(): void
    {
        $file = $this->createTestFile();
        
        $this->client->request('GET', '/file-manager/select/' . $file->getId());
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals($file->getId(), $response['data']['id']);
    }

    public function testApiStats(): void
    {
        $this->createTestFile();
        
        $this->client->request('GET', '/file-manager/api/stats');
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
    }

    public function testApiFoldersList(): void
    {
        $folder = $this->createTestFolder();
        
        $this->client->request('GET', '/file-manager/api/folders');
        
        $this->assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertTrue($response['success']);
        $this->assertArrayHasKey('data', $response);
    }

    public function testFileNotFound(): void
    {
        $this->client->request('GET', '/file-manager/api/files/999999');
        
        $this->assertResponseStatusCodeSame(404);
    }

    public function testUploadInvalidFileType(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_upload');
        file_put_contents($tempFile, 'malicious content');
        
        $uploadedFile = new UploadedFile(
            $tempFile,
            'malicious.exe',
            'application/x-executable',
            null,
            true
        );
        
        $this->client->request('POST', '/file-manager/upload', [], [
            'file' => $uploadedFile,
        ]);
        
        $this->assertResponseStatusCodeSame(400);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertFalse($response['success']);
        $this->assertStringContains('Type de fichier invalide', $response['error']);
        
        // Clean up
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    private function createTestFile(): File
    {
        $file = new File();
        $file->setFilename('test.txt')
            ->setPath('/uploads/test.txt')
            ->setMimeType('text/plain')
            ->setSize(100)
            ->setStorage('local.storage')
            ->setHash('test-hash');
            
        $this->entityManager->persist($file);
        $this->entityManager->flush();
        
        return $file;
    }

    private function createTestFolder(): Folder
    {
        $folder = new Folder();
        $folder->setName('Test Folder')
            ->setDescription('Test folder for testing');
            
        $this->entityManager->persist($folder);
        $this->entityManager->flush();
        
        return $folder;
    }

    private function cleanDatabase(): void
    {
        $connection = $this->entityManager->getConnection();
        
        // Disable foreign key checks
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        
        // Truncate tables
        $connection->executeStatement('TRUNCATE TABLE file');
        $connection->executeStatement('TRUNCATE TABLE folder');
        
        // Re-enable foreign key checks
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }
}

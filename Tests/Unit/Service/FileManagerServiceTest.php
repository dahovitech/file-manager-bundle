<?php

namespace Dahovitech\FileManagerBundle\Tests\Unit\Service;

use Dahovitech\FileManagerBundle\Entity\File;
use Dahovitech\FileManagerBundle\Entity\Folder;
use Dahovitech\FileManagerBundle\Exception\InvalidFileTypeException;
use Dahovitech\FileManagerBundle\Exception\FileTooLargeException;
use Dahovitech\FileManagerBundle\Exception\StorageNotFoundException;
use Dahovitech\FileManagerBundle\Service\FileManagerService;
use Dahovitech\FileManagerBundle\Service\ThumbnailService;
use Dahovitech\FileManagerBundle\Service\MetadataExtractorService;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class FileManagerServiceTest extends TestCase
{
    private FileManagerService $service;
    private MockObject|EntityManagerInterface $entityManager;
    private MockObject|EventDispatcherInterface $eventDispatcher;
    private MockObject|ValidatorInterface $validator;
    private MockObject|ThumbnailService $thumbnailService;
    private MockObject|MetadataExtractorService $metadataExtractor;
    private MockObject|LoggerInterface $logger;
    private MockObject|CacheItemPoolInterface $cache;
    private MockObject|FilesystemOperator $filesystem;
    private array $storages;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->thumbnailService = $this->createMock(ThumbnailService::class);
        $this->metadataExtractor = $this->createMock(MetadataExtractorService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->cache = $this->createMock(CacheItemPoolInterface::class);
        $this->filesystem = $this->createMock(FilesystemOperator::class);
        
        $this->storages = [
            'local.storage' => $this->filesystem,
        ];

        $this->service = new FileManagerService(
            $this->entityManager,
            $this->storages,
            $this->eventDispatcher,
            $this->validator,
            $this->thumbnailService,
            $this->metadataExtractor,
            $this->logger,
            $this->cache
        );
    }

    public function testUploadFileSuccess(): void
    {
        $uploadedFile = $this->createMockUploadedFile('test.jpg', 'image/jpeg', 1000, UPLOAD_ERR_OK);
        
        $this->validator->expects($this->once())
            ->method('validate')
            ->willReturn(new ConstraintViolationList());
            
        $this->filesystem->expects($this->once())
            ->method('writeStream');
            
        $this->metadataExtractor->expects($this->once())
            ->method('extractMetadata')
            ->willReturn(['type' => 'image']);
            
        $this->thumbnailService->expects($this->once())
            ->method('generateThumbnails');
            
        $this->entityManager->expects($this->once())
            ->method('persist');
            
        $this->entityManager->expects($this->once())
            ->method('flush');

        $result = $this->service->uploadFile($uploadedFile);
        
        $this->assertInstanceOf(File::class, $result);
        $this->assertEquals('image/jpeg', $result->getMimeType());
        $this->assertEquals(1000, $result->getSize());
    }

    public function testUploadFileInvalidType(): void
    {
        $uploadedFile = $this->createMockUploadedFile('test.exe', 'application/x-executable', 1000, UPLOAD_ERR_OK);
        
        $this->expectException(InvalidFileTypeException::class);
        
        $this->service->uploadFile($uploadedFile);
    }

    public function testUploadFileTooLarge(): void
    {
        $uploadedFile = $this->createMockUploadedFile('test.jpg', 'image/jpeg', 100 * 1024 * 1024, UPLOAD_ERR_OK); // 100MB
        
        $this->expectException(FileTooLargeException::class);
        
        $this->service->uploadFile($uploadedFile);
    }

    public function testUploadFileInvalidStorage(): void
    {
        $uploadedFile = $this->createMockUploadedFile('test.jpg', 'image/jpeg', 1000, UPLOAD_ERR_OK);
        
        $this->expectException(StorageNotFoundException::class);
        
        $this->service->uploadFile($uploadedFile, null, 'invalid.storage');
    }

    public function testDeleteFileSoft(): void
    {
        $file = new File();
        $file->setFilename('test.jpg')
            ->setPath('/test.jpg')
            ->setStorage('local.storage');
            
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->service->deleteFile($file, true);
        
        $this->assertTrue($file->isDeleted());
    }

    public function testDeleteFileHard(): void
    {
        $file = new File();
        $file->setFilename('test.jpg')
            ->setPath('/test.jpg')
            ->setStorage('local.storage')
            ->setMimeType('image/jpeg');
            
        $this->filesystem->expects($this->once())
            ->method('fileExists')
            ->willReturn(true);
            
        $this->filesystem->expects($this->once())
            ->method('delete');
            
        $this->thumbnailService->expects($this->once())
            ->method('deleteThumbnails');
            
        $this->entityManager->expects($this->once())
            ->method('remove');
            
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->service->deleteFile($file, false);
    }

    public function testCreateFolderSuccess(): void
    {
        $this->validator->expects($this->once())
            ->method('validate')
            ->willReturn(new ConstraintViolationList());
            
        $this->entityManager->expects($this->once())
            ->method('persist');
            
        $this->entityManager->expects($this->once())
            ->method('flush');

        $result = $this->service->createFolder('Test Folder');
        
        $this->assertInstanceOf(Folder::class, $result);
        $this->assertEquals('Test Folder', $result->getName());
    }

    public function testCreateFolderWithParent(): void
    {
        $parent = new Folder();
        $parent->setName('Parent');
        
        $this->validator->expects($this->once())
            ->method('validate')
            ->willReturn(new ConstraintViolationList());
            
        $this->entityManager->expects($this->once())
            ->method('persist');
            
        $this->entityManager->expects($this->once())
            ->method('flush');

        $result = $this->service->createFolder('Child', $parent);
        
        $this->assertEquals($parent, $result->getParent());
    }

    public function testRestoreFile(): void
    {
        $file = new File();
        $file->setFilename('test.jpg')
            ->markAsDeleted();
            
        $this->assertTrue($file->isDeleted());
        
        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->service->restoreFile($file);
        
        $this->assertFalse($file->isDeleted());
    }

    private function createMockUploadedFile(string $name, string $mimeType, int $size, int $error): UploadedFile
    {
        $file = $this->getMockBuilder(UploadedFile::class)
            ->setConstructorArgs([__FILE__, $name, $mimeType, $error, true])
            ->onlyMethods(['getClientOriginalName', 'getMimeType', 'getSize', 'guessExtension', 'getPathname'])
            ->getMock();
            
        $file->method('getClientOriginalName')->willReturn($name);
        $file->method('getMimeType')->willReturn($mimeType);
        $file->method('getSize')->willReturn($size);
        $file->method('guessExtension')->willReturn(pathinfo($name, PATHINFO_EXTENSION));
        $file->method('getPathname')->willReturn(__FILE__);
        
        return $file;
    }
}

<?php

namespace Dahovitech\FileManagerBundle\Tests\Unit\Service;

use Dahovitech\FileManagerBundle\Entity\File;
use Dahovitech\FileManagerBundle\Service\ThumbnailService;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ThumbnailServiceTest extends TestCase
{
    private ThumbnailService $service;
    private MockObject|LoggerInterface $logger;
    private MockObject|FilesystemOperator $filesystem;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->filesystem = $this->createMock(FilesystemOperator::class);
        
        $this->service = new ThumbnailService($this->logger);
    }

    public function testGenerateThumbnailsForNonImage(): void
    {
        $file = new File();
        $file->setMimeType('application/pdf');
        
        // Should not call filesystem for non-image files
        $this->filesystem->expects($this->never())
            ->method('read');
            
        $this->service->generateThumbnails($file, $this->filesystem);
    }

    public function testDeleteThumbnailsForNonImage(): void
    {
        $file = new File();
        $file->setMimeType('application/pdf');
        
        // Should not call filesystem for non-image files
        $this->filesystem->expects($this->never())
            ->method('fileExists');
            
        $this->service->deleteThumbnails($file, $this->filesystem);
    }

    public function testDeleteThumbnailsForImage(): void
    {
        $file = new File();
        $file->setMimeType('image/jpeg')
            ->setPath('/uploads/test.jpg')
            ->setFilename('test.jpg');
        
        // Mock that thumbnails exist and should be deleted
        $this->filesystem->expects($this->exactly(3)) // small, medium, large
            ->method('fileExists')
            ->willReturn(true);
            
        $this->filesystem->expects($this->exactly(3))
            ->method('delete');
            
        $this->service->deleteThumbnails($file, $this->filesystem);
    }
}

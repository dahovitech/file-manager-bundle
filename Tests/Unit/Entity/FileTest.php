<?php

namespace Dahovitech\FileManagerBundle\Tests\Unit\Entity;

use Dahovitech\FileManagerBundle\Entity\File;
use Dahovitech\FileManagerBundle\Entity\Folder;
use PHPUnit\Framework\TestCase;

class FileTest extends TestCase
{
    private File $file;

    protected function setUp(): void
    {
        $this->file = new File();
    }

    public function testGettersAndSetters(): void
    {
        $filename = 'test.jpg';
        $path = '/uploads/test.jpg';
        $mimeType = 'image/jpeg';
        $size = 1024;
        $storage = 'local.storage';
        $description = 'Test description';
        $tags = 'tag1, tag2';
        $hash = 'abc123';
        $uploadedAt = new \DateTimeImmutable();
        
        $this->file->setFilename($filename)
            ->setPath($path)
            ->setMimeType($mimeType)
            ->setSize($size)
            ->setStorage($storage)
            ->setDescription($description)
            ->setTags($tags)
            ->setHash($hash)
            ->setUploadedAt($uploadedAt);

        $this->assertEquals($filename, $this->file->getFilename());
        $this->assertEquals($path, $this->file->getPath());
        $this->assertEquals($mimeType, $this->file->getMimeType());
        $this->assertEquals($size, $this->file->getSize());
        $this->assertEquals($storage, $this->file->getStorage());
        $this->assertEquals($description, $this->file->getDescription());
        $this->assertEquals($tags, $this->file->getTags());
        $this->assertEquals($hash, $this->file->getHash());
        $this->assertEquals($uploadedAt, $this->file->getUploadedAt());
    }

    public function testFileTypeDetection(): void
    {
        // Test image
        $this->file->setMimeType('image/jpeg');
        $this->assertTrue($this->file->isImage());
        $this->assertFalse($this->file->isVideo());
        $this->assertFalse($this->file->isAudio());
        $this->assertFalse($this->file->isPdf());
        $this->assertFalse($this->file->isDocument());

        // Test PDF
        $this->file->setMimeType('application/pdf');
        $this->assertFalse($this->file->isImage());
        $this->assertTrue($this->file->isPdf());
        $this->assertTrue($this->file->isDocument());

        // Test video
        $this->file->setMimeType('video/mp4');
        $this->assertTrue($this->file->isVideo());
        $this->assertFalse($this->file->isImage());

        // Test audio
        $this->file->setMimeType('audio/mp3');
        $this->assertTrue($this->file->isAudio());
        $this->assertFalse($this->file->isImage());
    }

    public function testHumanReadableSize(): void
    {
        $this->file->setSize(0);
        $this->assertEquals('0 B', $this->file->getHumanReadableSize());

        $this->file->setSize(1024);
        $this->assertEquals('1 KB', $this->file->getHumanReadableSize());

        $this->file->setSize(1048576);
        $this->assertEquals('1 MB', $this->file->getHumanReadableSize());

        $this->file->setSize(1073741824);
        $this->assertEquals('1 GB', $this->file->getHumanReadableSize());
    }

    public function testFileExtraction(): void
    {
        $this->file->setFilename('test.jpg');
        $this->assertEquals('jpg', $this->file->getFileExtension());

        $this->file->setFilename('document.pdf');
        $this->assertEquals('pdf', $this->file->getFileExtension());

        $this->file->setFilename('file_without_extension');
        $this->assertEquals('', $this->file->getFileExtension());
    }

    public function testTagsHandling(): void
    {
        $this->file->setTags('tag1, tag2, tag3');
        $expectedTags = ['tag1', 'tag2', 'tag3'];
        $this->assertEquals($expectedTags, $this->file->getTagsArray());

        $this->file->setTagsFromArray(['new1', 'new2']);
        $this->assertEquals('new1, new2', $this->file->getTags());

        $this->file->setTags('');
        $this->assertEquals([], $this->file->getTagsArray());
    }

    public function testSoftDelete(): void
    {
        $this->assertFalse($this->file->isDeleted());
        $this->assertNull($this->file->getDeletedAt());

        $this->file->markAsDeleted();
        $this->assertTrue($this->file->isDeleted());
        $this->assertInstanceOf(\DateTimeImmutable::class, $this->file->getDeletedAt());

        $this->file->restore();
        $this->assertFalse($this->file->isDeleted());
        $this->assertNull($this->file->getDeletedAt());
    }

    public function testFolderRelation(): void
    {
        $folder = new Folder();
        $folder->setName('test-folder');

        $this->file->setFolder($folder);
        $this->assertEquals($folder, $this->file->getFolder());
    }

    public function testHasThumbnail(): void
    {
        // Image without thumbnail path
        $this->file->setMimeType('image/jpeg');
        $this->assertFalse($this->file->hasThumbnail());

        // Image with thumbnail path
        $this->file->setThumbnailPath('/thumbnails/thumb.jpg');
        $this->assertTrue($this->file->hasThumbnail());

        // Non-image with thumbnail path
        $this->file->setMimeType('application/pdf');
        $this->assertFalse($this->file->hasThumbnail());
    }

    public function testLifecycleCallbacks(): void
    {
        $this->assertNull($this->file->getUploadedAt());
        $this->file->setUploadedAtValue();
        $this->assertInstanceOf(\DateTimeImmutable::class, $this->file->getUploadedAt());

        $this->assertNull($this->file->getUpdatedAt());
        $this->file->setUpdatedAtValue();
        $this->assertInstanceOf(\DateTimeImmutable::class, $this->file->getUpdatedAt());
    }
}

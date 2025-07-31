<?php

namespace Dahovitech\FileManagerBundle\Tests\Unit\Entity;

use Dahovitech\FileManagerBundle\Entity\File;
use Dahovitech\FileManagerBundle\Entity\Folder;
use PHPUnit\Framework\TestCase;

class FolderTest extends TestCase
{
    private Folder $folder;

    protected function setUp(): void
    {
        $this->folder = new Folder();
    }

    public function testGettersAndSetters(): void
    {
        $name = 'Test Folder';
        $description = 'Test description';
        $tags = 'tag1, tag2';
        $createdAt = new \DateTimeImmutable();
        
        $this->folder->setName($name)
            ->setDescription($description)
            ->setTags($tags)
            ->setCreatedAt($createdAt);

        $this->assertEquals($name, $this->folder->getName());
        $this->assertEquals($description, $this->folder->getDescription());
        $this->assertEquals($tags, $this->folder->getTags());
        $this->assertEquals($createdAt, $this->folder->getCreatedAt());
    }

    public function testParentChildRelationship(): void
    {
        $parent = new Folder();
        $parent->setName('Parent');
        
        $child = new Folder();
        $child->setName('Child');
        
        $child->setParent($parent);
        $parent->addChild($child);
        
        $this->assertEquals($parent, $child->getParent());
        $this->assertTrue($parent->getChildren()->contains($child));
        $this->assertTrue($parent->hasChildren());
        $this->assertFalse($child->hasChildren());
    }

    public function testFileRelationship(): void
    {
        $file = new File();
        $file->setFilename('test.jpg');
        
        $this->folder->addFile($file);
        
        $this->assertTrue($this->folder->getFiles()->contains($file));
        $this->assertEquals($this->folder, $file->getFolder());
        $this->assertTrue($this->folder->hasFiles());
    }

    public function testRemoveFile(): void
    {
        $file = new File();
        $file->setFilename('test.jpg');
        
        $this->folder->addFile($file);
        $this->assertTrue($this->folder->hasFiles());
        
        $this->folder->removeFile($file);
        $this->assertFalse($this->folder->hasFiles());
        $this->assertNull($file->getFolder());
    }

    public function testRemoveChild(): void
    {
        $child = new Folder();
        $child->setName('Child');
        
        $this->folder->addChild($child);
        $this->assertTrue($this->folder->hasChildren());
        
        $this->folder->removeChild($child);
        $this->assertFalse($this->folder->hasChildren());
        $this->assertNull($child->getParent());
    }

    public function testFullPath(): void
    {
        $root = new Folder();
        $root->setName('root');
        
        $sub1 = new Folder();
        $sub1->setName('sub1')->setParent($root);
        
        $sub2 = new Folder();
        $sub2->setName('sub2')->setParent($sub1);
        
        $this->assertEquals('root', $root->getFullPath());
        $this->assertEquals('root/sub1', $sub1->getFullPath());
        $this->assertEquals('root/sub1/sub2', $sub2->getFullPath());
    }

    public function testDepth(): void
    {
        $root = new Folder();
        $root->setName('root');
        
        $level1 = new Folder();
        $level1->setName('level1')->setParent($root);
        
        $level2 = new Folder();
        $level2->setName('level2')->setParent($level1);
        
        $this->assertEquals(0, $root->getDepth());
        $this->assertEquals(1, $level1->getDepth());
        $this->assertEquals(2, $level2->getDepth());
    }

    public function testIsRoot(): void
    {
        $root = new Folder();
        $root->setName('root');
        
        $child = new Folder();
        $child->setName('child')->setParent($root);
        
        $this->assertTrue($root->isRoot());
        $this->assertFalse($child->isRoot());
    }

    public function testTotalFilesCount(): void
    {
        $root = new Folder();
        $root->setName('root');
        
        $sub = new Folder();
        $sub->setName('sub')->setParent($root);
        
        // Add files to root
        $file1 = new File();
        $file1->setFilename('file1.jpg');
        $root->addFile($file1);
        
        $file2 = new File();
        $file2->setFilename('file2.jpg');
        $root->addFile($file2);
        
        // Add file to subfolder
        $file3 = new File();
        $file3->setFilename('file3.jpg');
        $sub->addFile($file3);
        
        $this->assertEquals(3, $root->getTotalFilesCount());
        $this->assertEquals(1, $sub->getTotalFilesCount());
    }

    public function testTotalSize(): void
    {
        $file1 = new File();
        $file1->setFilename('file1.jpg')->setSize(1000);
        
        $file2 = new File();
        $file2->setFilename('file2.jpg')->setSize(2000);
        
        $this->folder->addFile($file1);
        $this->folder->addFile($file2);
        
        $this->assertEquals(3000, $this->folder->getTotalSize());
    }

    public function testIsEmpty(): void
    {
        $this->assertTrue($this->folder->isEmpty());
        
        $file = new File();
        $file->setFilename('test.jpg');
        $this->folder->addFile($file);
        
        $this->assertFalse($this->folder->isEmpty());
    }

    public function testTagsHandling(): void
    {
        $this->folder->setTags('tag1, tag2, tag3');
        $expectedTags = ['tag1', 'tag2', 'tag3'];
        $this->assertEquals($expectedTags, $this->folder->getTagsArray());
        
        $this->folder->setTagsFromArray(['new1', 'new2']);
        $this->assertEquals('new1, new2', $this->folder->getTags());
    }

    public function testSoftDelete(): void
    {
        $this->assertFalse($this->folder->isDeleted());
        $this->assertNull($this->folder->getDeletedAt());
        
        $this->folder->markAsDeleted();
        $this->assertTrue($this->folder->isDeleted());
        $this->assertInstanceOf(\DateTimeImmutable::class, $this->folder->getDeletedAt());
        
        $this->folder->restore();
        $this->assertFalse($this->folder->isDeleted());
        $this->assertNull($this->folder->getDeletedAt());
    }

    public function testAncestorDescendantRelationships(): void
    {
        $grandparent = new Folder();
        $grandparent->setName('grandparent');
        
        $parent = new Folder();
        $parent->setName('parent')->setParent($grandparent);
        
        $child = new Folder();
        $child->setName('child')->setParent($parent);
        
        $this->assertTrue($grandparent->isAncestorOf($child));
        $this->assertTrue($parent->isAncestorOf($child));
        $this->assertFalse($child->isAncestorOf($parent));
        
        $this->assertTrue($child->isDescendantOf($grandparent));
        $this->assertTrue($child->isDescendantOf($parent));
        $this->assertFalse($parent->isDescendantOf($child));
    }

    public function testGetAncestors(): void
    {
        $root = new Folder();
        $root->setName('root');
        
        $level1 = new Folder();
        $level1->setName('level1')->setParent($root);
        
        $level2 = new Folder();
        $level2->setName('level2')->setParent($level1);
        
        $ancestors = $level2->getAncestors();
        
        $this->assertCount(2, $ancestors);
        $this->assertEquals($root, $ancestors[0]);
        $this->assertEquals($level1, $ancestors[1]);
    }

    public function testToString(): void
    {
        $this->folder->setName('Test Folder');
        $this->assertEquals('Test Folder', (string) $this->folder);
        
        $emptyFolder = new Folder();
        $this->assertEquals('', (string) $emptyFolder);
    }
}

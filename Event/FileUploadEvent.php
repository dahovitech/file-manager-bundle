<?php

namespace Dahovitech\FileManagerBundle\Event;

use Dahovitech\FileManagerBundle\Entity\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\EventDispatcher\Event;

class FileUploadEvent extends Event
{
    public const PRE_UPLOAD = 'file_manager.file.pre_upload';
    public const POST_UPLOAD = 'file_manager.file.post_upload';

    public function __construct(
        private File $file,
        private UploadedFile $uploadedFile,
        private string $storageKey
    ) {
    }

    public function getFile(): File
    {
        return $this->file;
    }

    public function getUploadedFile(): UploadedFile
    {
        return $this->uploadedFile;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }
}

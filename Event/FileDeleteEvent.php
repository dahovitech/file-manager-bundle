<?php

namespace Dahovitech\FileManagerBundle\Event;

use Dahovitech\FileManagerBundle\Entity\File;
use Symfony\Contracts\EventDispatcher\Event;

class FileDeleteEvent extends Event
{
    public const PRE_DELETE = 'file_manager.file.pre_delete';
    public const POST_DELETE = 'file_manager.file.post_delete';

    public function __construct(
        private File $file
    ) {
    }

    public function getFile(): File
    {
        return $this->file;
    }
}

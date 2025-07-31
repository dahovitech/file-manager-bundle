<?php

namespace Dahovitech\FileManagerBundle\Exception;

class FileTooLargeException extends FileManagerException
{
    public function __construct(int $fileSize, int $maxSize)
    {
        $message = sprintf(
            'Fichier trop volumineux (%d bytes). Taille maximale autorisée: %d bytes',
            $fileSize,
            $maxSize
        );
        
        parent::__construct($message);
    }
}

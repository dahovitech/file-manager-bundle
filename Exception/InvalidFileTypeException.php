<?php

namespace Dahovitech\FileManagerBundle\Exception;

class InvalidFileTypeException extends FileManagerException
{
    public function __construct(string $mimeType, array $allowedTypes = [])
    {
        $message = sprintf(
            'Type de fichier invalide "%s". Types autorisés: %s',
            $mimeType,
            implode(', ', $allowedTypes)
        );
        
        parent::__construct($message);
    }
}

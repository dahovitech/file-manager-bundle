<?php

namespace Dahovitech\FileManagerBundle\Exception;

class StorageNotFoundException extends FileManagerException
{
    public function __construct(string $storageKey)
    {
        $message = sprintf('Stockage introuvable: "%s"', $storageKey);
        parent::__construct($message);
    }
}

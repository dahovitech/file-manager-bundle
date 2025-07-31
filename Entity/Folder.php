<?php

namespace Dahovitech\FileManagerBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: \Dahovitech\FileManagerBundle\Repository\FolderRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(columns: ['name'])]
#[ORM\Index(columns: ['created_at'])]
class Folder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    #[Assert\NotBlank(message: 'Le nom du dossier ne peut pas être vide')]
    #[Assert\Length(
        min: 1,
        max: 255,
        minMessage: 'Le nom du dossier doit contenir au moins {{ limit }} caractère',
        maxMessage: 'Le nom du dossier ne peut pas dépasser {{ limit }} caractères'
    )]
    #[Assert\Regex(
        pattern: '/^[a-zA-Z0-9\s._-]+$/',
        message: 'Le nom du dossier ne peut contenir que des lettres, chiffres, espaces, points, tirets et underscores'
    )]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Folder $parent = null;

    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $children;

    #[ORM\OneToMany(mappedBy: 'folder', targetEntity: File::class, cascade: ['persist'])]
    private Collection $files;

    #[ORM\Column(type: 'datetime_immutable')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: 'string', length: 500, nullable: true)]
    #[Assert\Length(max: 500, maxMessage: 'La description ne peut pas dépasser {{ limit }} caractères')]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isPublic = false;

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    #[Assert\Length(max: 100, maxMessage: 'Les tags ne peuvent pas dépasser {{ limit }} caractères')]
    private ?string $tags = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isDeleted = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct()
    {
        $this->children = new ArrayCollection();
        $this->files = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getParent(): ?self
    {
        return $this->parent;
    }

    public function setParent(?self $parent): self
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * @return Collection<int, Folder>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(Folder $child): self
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParent($this);
        }

        return $this;
    }

    public function removeChild(Folder $child): self
    {
        if ($this->children->removeElement($child)) {
            // set the owning side to null (unless already changed)
            if ($child->getParent() === $this) {
                $child->setParent(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, File>
     */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function addFile(File $file): self
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
            $file->setFolder($this);
        }

        return $this;
    }

    public function removeFile(File $file): self
    {
        if ($this->files->removeElement($file)) {
            // set the owning side to null (unless already changed)
            if ($file->getFolder() === $this) {
                $file->setFolder(null);
            }
        }

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setIsPublic(bool $isPublic): self
    {
        $this->isPublic = $isPublic;
        return $this;
    }

    public function getTags(): ?string
    {
        return $this->tags;
    }

    public function setTags(?string $tags): self
    {
        $this->tags = $tags;
        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->isDeleted;
    }

    public function setIsDeleted(bool $isDeleted): self
    {
        $this->isDeleted = $isDeleted;
        if ($isDeleted && !$this->deletedAt) {
            $this->deletedAt = new \DateTimeImmutable();
        } elseif (!$isDeleted) {
            $this->deletedAt = null;
        }
        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): self
    {
        $this->deletedAt = $deletedAt;
        return $this;
    }

    // Méthodes utilitaires

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        if (!$this->createdAt) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getFullPath(): string
    {
        $path = [];
        $current = $this;
        
        while ($current !== null) {
            array_unshift($path, $current->getName());
            $current = $current->getParent();
        }
        
        return implode('/', $path);
    }

    public function getDepth(): int
    {
        $depth = 0;
        $current = $this->parent;
        
        while ($current !== null) {
            $depth++;
            $current = $current->getParent();
        }
        
        return $depth;
    }

    public function isRoot(): bool
    {
        return $this->parent === null;
    }

    public function hasChildren(): bool
    {
        return !$this->children->isEmpty();
    }

    public function hasFiles(): bool
    {
        return !$this->files->isEmpty();
    }

    public function getTotalFilesCount(): int
    {
        $count = $this->files->count();
        
        foreach ($this->children as $child) {
            $count += $child->getTotalFilesCount();
        }
        
        return $count;
    }

    public function getTotalSize(): int
    {
        $size = 0;
        
        foreach ($this->files as $file) {
            $size += $file->getSize() ?? 0;
        }
        
        foreach ($this->children as $child) {
            $size += $child->getTotalSize();
        }
        
        return $size;
    }

    public function getHumanReadableTotalSize(): string
    {
        $size = $this->getTotalSize();
        
        if ($size === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }

    public function getTagsArray(): array
    {
        if (!$this->tags) {
            return [];
        }
        
        return array_filter(array_map('trim', explode(',', $this->tags)));
    }

    public function setTagsFromArray(array $tags): self
    {
        $this->tags = implode(', ', array_filter($tags));
        return $this;
    }

    public function markAsDeleted(): self
    {
        $this->setIsDeleted(true);
        return $this;
    }

    public function restore(): self
    {
        $this->setIsDeleted(false);
        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->files->isEmpty() && $this->children->isEmpty();
    }

    public function getAncestors(): array
    {
        $ancestors = [];
        $current = $this->parent;
        
        while ($current !== null) {
            $ancestors[] = $current;
            $current = $current->getParent();
        }
        
        return array_reverse($ancestors);
    }

    public function isAncestorOf(Folder $folder): bool
    {
        $current = $folder->getParent();
        
        while ($current !== null) {
            if ($current === $this) {
                return true;
            }
            $current = $current->getParent();
        }
        
        return false;
    }

    public function isDescendantOf(Folder $folder): bool
    {
        return $folder->isAncestorOf($this);
    }

    public function __toString(): string
    {
        return $this->name ?? '';
    }
}
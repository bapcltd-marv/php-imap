<?php

declare(strict_types=1);

namespace PhpImap;

use finfo;
use UnexpectedValueException;

/**
 * @see https://github.com/barbushin/php-imap
 *
 * @author Barbushin Sergey http://linkedin.com/in/barbushin
 *
 * @property string $filePath lazy attachment data file
 */
class IncomingMailAttachment
{
    /** @var string|null */
    public ?string $id = null;

    /** @var string|null */
    public ?string $contentId = null;

    /** @var string|null */
    public ?string $name = null;

    /** @var string|null */
    public ?string $disposition = null;

    /** @var string|null */
    public ?string $charset = null;

    /** @var bool|null */
    public ?bool $emlOrigin = null;

    /** @var string|null */
    private ?string $file_path = null;

    /** @var DataPartInfo|null */
    private ?DataPartInfo $dataInfo = null;

    /**
     * @var string|null
     */
    private ?string $mimeType = null;

    /** @var string|null */
    private ?string $filePath = null;

    public function __get(string $name)
    {
        if ('filePath' !== $name) {
            trigger_error("Undefined property: IncomingMailAttachment::$name");
        }

        if (!isset($this->file_path)) {
            return false;
        }

        $this->filePath = $this->file_path;

        if (@file_exists($this->file_path)) {
            return $this->filePath;
        }

        return $this->filePath;
    }

    /**
     * Sets the file path.
     *
     * @param string $filePath File path incl. file name and optional extension
     */
    public function setFilePath(string $filePath): void
    {
        $this->file_path = $filePath;
    }

    /**
     * Sets the data part info.
     *
     * @param DataPartInfo $dataInfo Date info (file content)
     */
    public function addDataPartInfo(DataPartInfo $dataInfo): void
    {
        $this->dataInfo = $dataInfo;
    }

    /**
     * Gets the MIME type.
     */
    public function getMimeType(): string
    {
        if (!$this->mimeType) {
            $finfo = new finfo(FILEINFO_MIME);

            $this->mimeType = $finfo->buffer($this->getContents());
        }

        return $this->mimeType;
    }

    /**
     * Gets the file content.
     */
    public function getContents(): string
    {
        if (null === $this->dataInfo) {
            throw new UnexpectedValueException(static::class.'::$dataInfo has not been set by calling '.self::class.'::addDataPartInfo()');
        }

        return $this->dataInfo->fetch();
    }

    /**
     * Saves the attachment object on the disk.
     *
     * @return bool True, if it could save the attachment on the disk
     */
    public function saveToDisk(): bool
    {
        if (null === $this->dataInfo) {
            return false;
        }

        if (false === file_put_contents($this->filePath, $this->dataInfo->fetch())) {
            unset($this->filePath);
            unset($this->file_path);

            return false;
        }

        return true;
    }
}

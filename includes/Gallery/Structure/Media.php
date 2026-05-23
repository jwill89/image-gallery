<?php

namespace Gallery\Structure;

/**
 * Media class
 * Unified structure representing any media item (image or video) in the gallery.
 */
class Media extends AbstractStructure
{
    private int $media_id = 0;
    private string $media_type = 'image'; // 'image' or 'video'
    private string $file_name = '';
    private int $file_time = 0;
    private string $hash = '';
    private string $bits_fingerprint = '';

    public function getMediaId(): int
    {
        return $this->media_id;
    }

    public function getMediaType(): string
    {
        return $this->media_type;
    }

    public function getFileName(): string
    {
        return $this->file_name;
    }

    public function getFileTime(): int
    {
        return $this->file_time;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getBitsFingerprint(): string
    {
        return $this->bits_fingerprint;
    }

    public function setMediaId(int $media_id): self
    {
        $this->media_id = $media_id;
        return $this;
    }

    public function setMediaType(string $media_type): self
    {
        $this->media_type = $media_type;
        return $this;
    }

    public function setFileName(string $file_name): self
    {
        $this->file_name = $file_name;
        return $this;
    }

    public function setFileTime(int $file_time): self
    {
        $this->file_time = $file_time;
        return $this;
    }

    public function setHash(string $hash): self
    {
        $this->hash = $hash;
        return $this;
    }

    public function setBitsFingerprint(string $bits_fingerprint): self
    {
        $this->bits_fingerprint = $bits_fingerprint;
        return $this;
    }

    /**
     * Helper: is this an image?
     */
    public function isImage(): bool
    {
        return $this->media_type === 'image';
    }

    /**
     * Helper: is this a video?
     */
    public function isVideo(): bool
    {
        return $this->media_type === 'video';
    }
}

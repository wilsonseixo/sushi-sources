<?php

namespace SushiSources\Sources;

class JsonFileSource extends Source
{
    protected ?array $rowsCache = null;

    /**
     * @inheritDoc
     */
    public function read(): array
    {
        return $this->getFileRows();
    }

    /**
     * @inheritDoc
     */
    public function write(?array $rows = []): void
    {
        $this->setFileRows($rows);
    }

    /**
     * @inheritDoc
     */
    public function persist(): void
    {
        if( !is_null($this->getFileRows()) )
            $this->putFileRows($this->getFileRows());
    }

    /**
     * @inheritDoc
     */
    public function lock(callable $callable)
    {
        // TODO: Implement lock() method.
        return $callable();
    }

    /**
     * Retrieves the source filename.
     *
     * @return string|null
     */
    public function filename(): ?string
    {
        $filename = $this->context('filename');
        return is_string($filename) ? $filename : null;
    }

    /**
     * Retrieves rows from the target JSON file.
     *
     * @param string|null $filename
     * @return array
     */
    public function getFileRows(?string $filename = null): array
    {
        $filename ??= $this->filename();
        if( empty($filename) || !file_exists($filename) )
            return [];

        if( !is_null($this->rowsCache) )
            return $this->rowsCache;

        $rows = [];
        $fileContents = file_get_contents($filename);
        if( $fileContents !== false ){
            $decodedFile = json_decode($fileContents, true, $this->context('json_depth', 512), $this->context('json_decode_flags', 0));
            $rows = is_array($decodedFile) ? $decodedFile : $rows;
        }

        return $this->setFileRows($rows);
    }

    /**
     * Sets the rows to be put to the target JSON file.
     *
     * @param array|null $rows
     * @return array
     */
    public function setFileRows(?array $rows): array
    {
        return $this->rowsCache = $rows ?? [];
    }

    /**
     * Puts the rows to the target JSON file.
     *
     * @param array $rows
     * @return false|int
     */
    public function putFileRows(array $rows): false|int
    {
        $filename = $this->filename();
        if( empty($filename) )
            return false;

        return file_put_contents($filename, json_encode($rows, $this->context('json_encode_flags', 0)), $this->context('json_depth', 0));
    }
}

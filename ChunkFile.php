<?php

class ChunkFile
{
    /**
     * 文件分片结果
     * @var array $chunks
     */
    private $chunks = [];

    /**
     * 被分片的文件
     * @var string $file
     */
    private $file;

    /**
     * 单个文件分片大小
     * @var int $singleChunkSize
     */
    private $singleChunkSize = 0;

    /**
     * @var string $hashId
     */
    private $hashId = '';

    /**
     * @var int $runStatus
     */
    private $runStatus = 0;

    /**
     * @param string $file
     * @param int $singleChunkSize
     */
    public function load(string $file, int $singleChunkSize = 2 * 1024 * 1024)
    {
        $this->file = $file;
        $this->hashId = md5_file($file);

        if (is_numeric($singleChunkSize) && intval($singleChunkSize) < 1) {
            throw new \InvalidArgumentException(
                sprintf("Invalid single file block size: %s Bytes", $singleChunkSize)
            );
        }

        $this->singleChunkSize = $singleChunkSize;
    }

    public function setSingleChunkSize(int $singleChunkSize)
    {
        if (is_numeric($singleChunkSize) && intval($singleChunkSize) < 1) {
            throw new \InvalidArgumentException(
                sprintf("Invalid single file block size: %s Bytes", $singleChunkSize)
            );
        }

        $this->singleChunkSize = $singleChunkSize;
    }

    private function runChunks()
    {
        $fileSize = filesize($this->file);
        // 文件总共分块多少个
        $count = ceil($fileSize / $this->singleChunkSize);

        $i = 0;

        // 保存分块信息
        $file_chunk_info = [];

        while ($fileSize > 2 * 1024 * 1024 && $i < $count) {

            $start = $this->singleChunkSize * $i;

            $end = ($start + $this->singleChunkSize) > $fileSize ? $fileSize : $start + $this->singleChunkSize;

            $file_chunk_info[$this->hashId][$start] = $end;

            ++$i;
        }

        $this->runStatus = 1;

        return $file_chunk_info[$this->hashId];
    }

    public function chunkFileList()
    {
        return $this->runChunks();
    }

    final public function chunksCount()
    {
        if ($this->runStatus === 1) {
            return count($this->chunks[$this->hashId]);
        }
        throw new \LogicException('Invalid method call status.');
    }
}


class UploadFile
{
    /**
     * @var ChunkFile $chunkFile
     */
    protected $chunkFile;
    /**
     * @var string $file
     */
    protected $file = '';
    /**
     * @var string $saveDir
     */
    protected $saveDir = '';

    /**
     * @var int $chunkSize
     */
    private $chunkSize = 0;

    /**
     * @var $saveFile
     */
    protected $saveFile;

    protected const MIME_TYPE = [
        'application/x-dosexec' => '.exe'
    ];

    public function __construct(string $file, string $saveDir)
    {
        if (!file_exists($file)) {
            throw new \RuntimeException(
                sprintf('Upload file does not exist: %s.', $file)
            );
        }

        $this->file = $file;

        if (!file_exists($saveDir)) {
            throw new \RuntimeException(
                sprintf('Save dir does not exist: %s.', $saveDir)
            );
        }
        $this->saveDir = $saveDir;

        $this->chunkFile = new ChunkFile();

        $this->saveDir = $saveDir . '/' . md5_file($file);
    }

    public function setSingleChunkSize(int $size)
    {
        if (is_numeric($size) && intval($size) < 1) {
            throw new \InvalidArgumentException(
                sprintf("Invalid single file block size: %s Bytes", $size)
            );
        }

        $this->chunkSize = $size * 1024 * 1024;
    }

    /**
     * 重命名名称
     * @param $saveFile string
     */
    public function setSaveFileName($saveFile)
    {
        $this->saveFile = $saveFile;
    }

    private static function getMimeType($file)
    {
        $mime = mime_content_type($file);
        if (isset(static::MIME_TYPE[$mime])) {
            return $value = self::MIME_TYPE[$mime];
        }
        throw new \RuntimeException('Unrecognized file suffix.');
    }

    protected function getSaveFileName()
    {
        $mimeType = self::getMimeType($this->file);

        if (empty($this->saveFile)) {
            return $this->saveDir . '/' . \uniqid() . '.' . $mimeType;
        }

        return $this->saveDir . '/' . $this->saveFile;
    }

    private function getChunkSize()
    {
        return $this->chunkSize;
    }

    protected function chunkFiles()
    {
        if (empty($this->chunkFile)) {
            throw new \Exception('Null pointer exception.');
        }

        $this->chunkFile->load($this->file);

        if (($singleChunkSize = $this->getChunkSize()) > 0) {
            $this->chunkFile->setSingleChunkSize($singleChunkSize);
        }

        return $this->chunkFile->chunkFileList();
    }

    public function saveFile()
    {
        $this->writeFile(false);
    }

    private function writeFile($newFile, bool $overWrite = false)
    {
        $saveFile = $this->getSaveFileName();
        $saveDir = $this->saveDir;

        if (!is_dir($saveDir) && !mkdir($saveDir, 0777)) {
            throw new \RuntimeException('mkdir dir fail.');
        }

        $fp = fopen($this->file, 'rw+');
        if ($fp === false) {
            throw new \RuntimeException('fail to open the file.');
        }

        $new_fp = fopen($saveFile, 'w+');
        if ($new_fp === false) {
            throw new \RuntimeException('fail to open the file.');
        }

        foreach ($chunkFiles = $this->chunkFiles() as $chunk_start => $chunk_end) {
            var_dump('start =' . $chunk_start . ',end=' . $chunk_end);

            // 移动文件指针
            fseek($fp, $chunk_start);
            fseek($new_fp, $chunk_start);

            $data = fread($fp, $chunk_end - $chunk_start);

            $chunk_filename = $saveDir . '/' . $chunk_start . '_' . $chunk_end;

            if (file_exists($chunk_filename)) {
                if (!$overWrite) {
                    continue;
                }
                // 覆盖直接删除原文件
                unlink($chunk_filename);
            };

            file_put_contents($chunk_filename, $data);
            fwrite($new_fp, $data);
        }

        fclose($new_fp);
        fclose($fp);
    }
}

$file = dirname(__DIR__) . '/QQMusicSetup.exe';

$new_file = __DIR__.'/../';

$uploadFile = new UploadFile($file, $new_file);
$uploadFile->setSingleChunkSize(5); // 5 MB
$uploadFile->saveFile();
<?php
use wapmorgan\BinaryStream\BinaryStream;

class CabArchive {
    const COMPRESSION_NONE = 0x0;
    const COMPRESSION_MSZIP = 0x1;

    const ATTRIB_READONLY = 0x1;
    const ATTRIB_HIDDEN = 0x2;
    const ATTRIB_SYSTEM = 0x4;
    const ATTRIB_EXEC = 0x40;

    protected $filename;
    protected $stream;
    protected $header;
    protected $folders = array();
    protected $files = array();
    protected $blocks = array();
    public $blocksRaw = array();
    protected $foldersRaw = array();

    public $filesCount = -1;
    public $foldersCount = -1;
    public $blocksCount = -1;

    public function __construct($filename) {
        $this->filename = $filename;
        $this->stream = new BinaryStream($filename);
        $this->stream->setEndian(BinaryStream::LITTLE);
        $this->open();
    }

    protected function open() {
        if (!$this->stream->compare(4, 'MSCF'))
            throw new Exception('This is not a cab-file');
        $this->header = $this->stream->readGroup(array(
            's:signature' => 4,
            'i:reserved1' => 32,
            'i:size' => 32,
            'i:reserved2' => 32,
            'i:filesOffset' => 32,
            'i:reserved3' => 32,
            'c:minorVersion' => 1,
            'c:majorVersion' => 1,
            'i:foldersCount' => 16,
            'i:filesCount' => 16,
            'i:flags' => 16,
            'i:setId' => 16,
            'i:inSetNumber' => 16,
        ));
        if ($this->header['flags'] & 0x4) {
            $header_reserve = $this->stream->readGroup(array(
                'i:abHeaderSize' => 16,
                'c:abFolderSize' => 1,
                'c:abDataSize' => 1,
            ));
            $header_reserve += $this->stream->readGroup(array(
                's:abReserve' => $header_reserve['abHeaderSize']
            ));
            if ($this->header['flags'] & 0x1) {
                $this->header['cab_previous'] = $this->readNullTerminatedString();
                $this->header['cab_previous_disk'] = $this->readNullTerminatedString();
            }
            if ($this->header['flags'] & 0x2) {
                $this->header['cab_next'] = $this->readNullTerminatedString();
                $this->header['cab_next_disk'] = $this->readNullTerminatedString();
            }
            // var_dump($header_reserve);
        }
        // var_dump($this->header);
        $this->filesCount = $this->header['filesCount'];
        $this->foldersCount = $this->header['foldersCount'];
        // read folders
        for ($i = 0; $i < $this->header['foldersCount']; $i++) {
            $folder = $this->stream->readGroup(array(
                'i:dataOffset' => 32,
                'i:blocksCount' => 16,
                'i:compression' => 16
            ));
            if ($this->header['flags'] & 0x4 && $header_reserve['abFolderSize'] > 0) {
                $this->stream->readString($header_reserve['abFolderSize']);
            }
            $this->folders[] = $folder;
        }
        // var_dump($this->folders);
        // read files
        for ($i = 0; $i < $this->header['filesCount']; $i++) {
            $file = $this->stream->readGroup(array(
                'i:size' => 32,
                'i:offsetInFolder' => 32,
                'i:folder' => 16,
                'i:date' => 16,
                'i:time' => 16,
                'i:attributes' => 16,
            ));

            $year = ($file['date'] >> 9) + 1980;
            $month = ($file['date'] >> 5) & 0b1111;
            $day = $file['date'] & 0b11111;
            $hours = $file['time'] >> 11;
            $minutes = ($file['time'] >> 5) & 0b111111;
            $seconds = ($file['time'] >> 5) * 2;
            $file['unixtime'] = mktime($hours, $minutes, $seconds, $month, $day, $year);

            $file['name'] = $this->readNullTerminatedString();
            $this->files[] = $file;
        }
        // var_dump($this->files);
        // read data
        foreach ($this->folders as $folder_id => $folder) {
            $last_uncomp_offset = 0;
            $this->stream->go($folder['dataOffset']);
            for ($i = 0; $i < $folder['blocksCount']; $i++) {
                $block = $this->stream->readGroup(array(
                    'i:checksum' => 32,
                    'i:compSize' => 16,
                    'i:uncompSize' => 16
                ));
                $block['uncompOffset'] = $last_uncomp_offset;
                if ($this->header['flags'] & 0x4 && $header_reserve['abDataSize'] > 0)
                    $this->stream->readString($header_reserve['abDataSize']);
                $this->stream->mark('block_'.$folder_id.'_'.$i);
                $last_uncomp_offset += $block['uncompSize'];
                $this->stream->skip($block['compSize']);
                $this->blocks[$folder_id][] = $block;
            }
        }
        // var_dump($this->blocks);
    }

    public function getCabHeader() {
        return $this->header;
    }

    public function hasPreviousCab() {
        return $this->header['flags'] & 0x1;
    }

    public function getPreviousCab() {
        return $this->header['cab_previous'];
    }

    public function hasNextCab() {
        return $this->header['flags'] & 0x2;
    }

    public function getNextCab() {
        return $this->header['cab_next'];
    }

    public function getSetId() {
        return $this->header['setId'];
    }

    public function getInSetNumber() {
        return $this->header['inSetNumber'];
    }

    public function getBlocks() {
        return $this->blocks;
    }

    public function getFileNames() {
        $files = array();
        foreach ($this->files as $file) {
            $files[] = $file['name'];
        }
        return $files;
    }

    public function getFileData($filename) {
        foreach ($this->files as $file) {
            if ($file['name'] == $filename) {
                if ($this->folders[$file['folder']]['compression'] == self::COMPRESSION_NONE)
                    $packedSize = $file['size'];
                else {
                    $packedSize = 0;
                    foreach ($this->detectBlocksOfFile($file['folder'], $file['offsetInFolder'], $file['size']) as $block_id) {
                        $block = $this->blocks[$file['folder']][$block_id];
                        // block intersection
                        $block_intersection = $this->calculateRangesIntersection(array($file['offsetInFolder'], $file['offsetInFolder'] + $file['size']), array($block['uncompOffset'], $block['uncompOffset'] + $block['uncompSize']));
                        $packedSize += ceil($block_intersection / 100 * $block['compSize']);
                    }
                }
                return (object)array('unixtime' => $file['unixtime'], 'size' => $file['size'], 'packedSize' => $packedSize, 'isCompressed' => $this->folders[$file['folder']]['compression'] != self::COMPRESSION_NONE);
            }
        }
        return false;
    }

    public function getFileAttributes($filename) {
        foreach ($this->files as $file) {
            if ($file['name'] == $filename) {
                $attribs = array();
                foreach (array(self::ATTRIB_READONLY, self::ATTRIB_HIDDEN, self::ATTRIB_SYSTEM, self::ATTRIB_EXEC) as $attrib)
                    if ($file['attributes'] & $attrib)
                        $attribs[] = $attrib;
                return $attribs;
            }
        }
        return false;
    }

    public function getFileContent($filename) {
        foreach ($this->files as $file) {
            if ($file['name'] == $filename) {
                $file_size = $file['size'];
                $file_offset = $file['offsetInFolder'];
                $folder_id = $file['folder'];
                break;
            }
        }
        if (!isset($folder_id))
            return false;
        $file_end = $file_offset + $file_size;

        if ($this->folders[$folder_id]['compression'] == self::COMPRESSION_MSZIP) {
            $this->decompressFolder($folder_id);
        } else {
            $this->readFolder($folder_id);
        }
        $content = substr($this->foldersRaw[$folder_id], $file_offset, $file_size);
        return $content;
    }

    /**
     * For internal usage only.
     */
    protected function detectBlocksOfFile($folderId, $fileOffset, $fileSize) {
        $fileEnd = $fileOffset + $fileSize;


        $folder = $this->folders[$folderId];
        // traverse all blocks to determine list to uncompress
        $blocks = array();
        foreach ($this->blocks[$folderId] as $block_id => $block) {
            // echo 'Block #'.$block_id.': '.$block['uncompOffset'].'...'.($block['uncompOffset'] + $block['uncompSize']).PHP_EOL;
            if ($fileOffset > ($block['uncompOffset'] + $block['uncompSize']) || $fileEnd < $block['uncompOffset'])
                continue;
            $blocks[] = $block_id;
        }
        return $blocks;
    }

    public function decompressFolder($folderId) {
        if (isset($this->foldersRaw[$folderId]))
            return true;

        $this->foldersRaw[$folderId] = null;
        foreach ($this->blocks[$folderId] as $block_id => $block) {
            $this->stream->go('block_'.$folderId.'_'.$block_id);
            if ($this->stream->readString(2) != 'CK')
                throw new Exception('Can\'t read block '.$folderId.':'.$block_id.', wrong MSZIP signature');
            $block_raw = $this->stream->readString($block['compSize'] - 2);
            $context = inflate_init(ZLIB_ENCODING_RAW, $block_id > 0 ? array('dictionary' => $this->blocksRaw[$folderId][$block_id - 1]) : array());
            $decoded = inflate_add($context, $block_raw);
            if ($decoded === false) echo 'failed'.PHP_EOL;
            else {
                echo strlen($decoded).' bytes'.PHP_EOL;
                $this->blocksRaw[$folderId][$block_id] = $decoded;
                $this->foldersRaw[$folderId] .= $decoded;
            }

        }
    }

    protected function readNullTerminatedString() {
        $string = null;
        do {
            $c = $this->stream->readChar();
            if ($c != "\00") $string .= $c;
        } while ($c != "\00");
        return $string;
    }

    /**
     * Calculates how B intersect with A
     */
    protected function calculateRangesIntersection(array $rangeA, array $rangeB) {
        $intersection = 100;
        // shift to zero-based calculations
        $offset = min($rangeA[0], $rangeB[0]);
        if ($offset > 0) {
            $rangeA = array($rangeA[0] - $offset, $rangeA[1] - $offset);
            $rangeB = array($rangeB[0] - $offset, $rangeB[1] - $offset);
        }

        $percent = ($rangeB[1] - $rangeB[0]) / 100;

        // if A start offset larger than B start offset
        if ($rangeA[0] > $rangeB[0]) {
            $intersection -= ($rangeA[0] - $rangeB[0]) / $percent;
        }

        if ($rangeA[1] < $rangeB[1]) {
            $intersection -= ($rangeB[1] - $rangeA[1]) / $percent;
        }

        return $intersection;
    }
}

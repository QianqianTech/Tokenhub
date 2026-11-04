<?php

declare(strict_types=1);

namespace TokenHub\Support;

/**
 * Read-only reader for OLE2 / Compound File Binary containers ([MS-CFB]), the
 * storage format of legacy Office files (.doc/.wps, .ppt/.dps, .xls/.et).
 *
 * Built for untrusted uploads: every sector index, chain and size is bounds
 * checked, chains that loop or run past the file end are cut off, and a stream
 * is never read beyond the file's own size.
 */
final class OleCompoundFile
{
    private const SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    private const END_OF_CHAIN = 0xFFFFFFFE;
    private const FREE = 0xFFFFFFFF;

    private string $data;
    private int $sectorSize;
    private int $miniSectorSize;
    private int $miniCutoff;
    private int $sectorCount;
    /** @var list<int> */
    private array $fat = [];
    /** @var list<int> */
    private array $miniFat = [];
    private string $miniStream = '';
    /** @var array<string, array{start: int, size: int}> lower-cased name => stream entry */
    private array $streams = [];

    private function __construct(string $data)
    {
        $this->data = $data;
    }

    public static function isCompoundFile(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = (string) fread($handle, 8);
        fclose($handle);

        return $head === self::SIGNATURE;
    }

    /** Returns null when the file is not a readable compound file. */
    public static function open(string $path): ?self
    {
        $data = @file_get_contents($path);
        if (!is_string($data) || strlen($data) < 512 || !str_starts_with($data, self::SIGNATURE)) {
            return null;
        }
        $file = new self($data);

        return $file->load() ? $file : null;
    }

    public function hasStream(string $name): bool
    {
        return isset($this->streams[strtolower($name)]);
    }

    public function stream(string $name): ?string
    {
        $entry = $this->streams[strtolower($name)] ?? null;
        if ($entry === null) {
            return null;
        }
        if ($entry['size'] < $this->miniCutoff) {
            return $this->readMini($entry['start'], $entry['size']);
        }

        return $this->readChain($entry['start'], $entry['size']);
    }

    private function load(): bool
    {
        $major = $this->u16(0x1A);
        $sectorShift = $this->u16(0x1E);
        $miniShift = $this->u16(0x20);
        if (!in_array($major, [3, 4], true) || !in_array($sectorShift, [9, 12], true) || $miniShift !== 6) {
            return false;
        }
        $this->sectorSize = 1 << $sectorShift;
        $this->miniSectorSize = 1 << $miniShift;
        $this->miniCutoff = $this->u32(0x38) ?: 4096;
        $this->sectorCount = max(0, intdiv(strlen($this->data) - $this->sectorSize, $this->sectorSize) + 1);
        $fatSectors = $this->u32(0x2C);
        $dirStart = $this->u32(0x30);
        $miniFatStart = $this->u32(0x3C);
        $difatStart = $this->u32(0x44);
        $difatCount = $this->u32(0x48);

        // DIFAT: 109 entries in the header, the rest in a chain of DIFAT sectors.
        $fatSectorList = [];
        for ($i = 0; $i < 109 && count($fatSectorList) < $fatSectors; $i++) {
            $sector = $this->u32(0x4C + $i * 4);
            if ($sector !== self::FREE) {
                $fatSectorList[] = $sector;
            }
        }
        $perDifat = intdiv($this->sectorSize, 4) - 1;
        $sector = $difatStart;
        $seen = [];
        for ($n = 0; $n < $difatCount && $sector < $this->sectorCount && !isset($seen[$sector]); $n++) {
            $seen[$sector] = true;
            $offset = $this->sectorOffset($sector);
            for ($i = 0; $i < $perDifat && count($fatSectorList) < $fatSectors; $i++) {
                $entry = $this->u32($offset + $i * 4);
                if ($entry !== self::FREE) {
                    $fatSectorList[] = $entry;
                }
            }
            $sector = $this->u32($offset + $perDifat * 4);
        }
        foreach ($fatSectorList as $fatSector) {
            if ($fatSector >= $this->sectorCount) {
                return false;
            }
            $chunk = substr($this->data, $this->sectorOffset($fatSector), $this->sectorSize);
            foreach (array_values(unpack('V*', $chunk) ?: []) as $value) {
                $this->fat[] = (int) $value;
            }
        }
        if ($this->fat === []) {
            return false;
        }

        $directory = $this->readChain($dirStart, null);
        if ($directory === null || $directory === '') {
            return false;
        }
        $entries = [];
        for ($offset = 0, $id = 0; $offset + 128 <= strlen($directory); $offset += 128, $id++) {
            $nameLength = $this->u16At($directory, $offset + 0x40);
            $type = ord($directory[$offset + 0x42]);
            if ($nameLength < 2 || $nameLength > 64 || !in_array($type, [1, 2, 5], true)) {
                continue;
            }
            $size = $this->u32At($directory, $offset + 0x78);
            $entries[$id] = [
                'name' => (string) mb_convert_encoding(substr($directory, $offset, $nameLength - 2), 'UTF-8', 'UTF-16LE'),
                'type' => $type,
                'left' => $this->u32At($directory, $offset + 0x44),
                'right' => $this->u32At($directory, $offset + 0x48),
                'child' => $this->u32At($directory, $offset + 0x4C),
                'start' => $this->u32At($directory, $offset + 0x74),
                'size' => min($major === 3 ? $size & 0x7FFFFFFF : $size, strlen($this->data)),
            ];
        }
        $root = $entries[0] ?? null;
        if ($root === null || $root['type'] !== 5) {
            return false;
        }
        $rootStart = $root['start'];
        $rootSize = $root['size'];
        // Only streams directly under the root storage: embedded objects (ObjectPool,
        // OLE packages) carry their own "WordDocument" / "1Table" streams.
        $pending = [$root['child']];
        $seen = [];
        while ($pending !== []) {
            $id = array_pop($pending);
            if (!isset($entries[$id]) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $entry = $entries[$id];
            $pending[] = $entry['left'];
            $pending[] = $entry['right'];
            if ($entry['type'] === 2) {
                $this->streams[strtolower($entry['name'])] = ['start' => $entry['start'], 'size' => $entry['size']];
            }
        }
        if ($rootStart !== null && $rootSize > 0) {
            $this->miniStream = (string) $this->readChain($rootStart, min($rootSize, strlen($this->data)));
            $miniFatBytes = (string) $this->readChain($miniFatStart, null);
            $this->miniFat = array_map('intval', array_values(unpack('V*', $miniFatBytes) ?: []));
        }

        return true;
    }

    private function readChain(int $start, ?int $size): ?string
    {
        if ($start === self::END_OF_CHAIN || $start === self::FREE) {
            return '';
        }
        $parts = [];
        $length = 0;
        $sector = $start;
        $seen = [];
        while ($sector !== self::END_OF_CHAIN && $sector < $this->sectorCount && !isset($seen[$sector])) {
            $seen[$sector] = true;
            $parts[] = substr($this->data, $this->sectorOffset($sector), $this->sectorSize);
            $length += $this->sectorSize;
            if ($size !== null && $length >= $size) {
                break;
            }
            $sector = $this->fat[$sector] ?? self::END_OF_CHAIN;
        }
        $bytes = implode('', $parts);

        return $size === null ? $bytes : substr($bytes, 0, $size);
    }

    private function readMini(int $start, int $size): string
    {
        $parts = [];
        $length = 0;
        $sector = $start;
        $seen = [];
        $maxSector = intdiv(strlen($this->miniStream), $this->miniSectorSize);
        while ($sector !== self::END_OF_CHAIN && $sector < $maxSector && !isset($seen[$sector]) && $length < $size) {
            $seen[$sector] = true;
            $parts[] = substr($this->miniStream, $sector * $this->miniSectorSize, $this->miniSectorSize);
            $length += $this->miniSectorSize;
            $sector = $this->miniFat[$sector] ?? self::END_OF_CHAIN;
        }

        return substr(implode('', $parts), 0, $size);
    }

    private function sectorOffset(int $sector): int
    {
        return ($sector + 1) * $this->sectorSize;
    }

    private function u16(int $offset): int
    {
        return $this->u16At($this->data, $offset);
    }

    private function u32(int $offset): int
    {
        return $this->u32At($this->data, $offset);
    }

    private function u16At(string $bytes, int $offset): int
    {
        return $offset + 2 <= strlen($bytes) ? (int) unpack('v', $bytes, $offset)[1] : 0;
    }

    private function u32At(string $bytes, int $offset): int
    {
        return $offset + 4 <= strlen($bytes) ? (int) unpack('V', $bytes, $offset)[1] : self::END_OF_CHAIN;
    }
}

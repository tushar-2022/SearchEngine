<?php

namespace Tzart\SearchEngine\Helpers;

use FFI;

class MmapFile
{
    private ?FFI $ffi = null;
    private $addr = null;
    private int $size = 0;
    private bool $usingMmap = false;
    private int $fd = -1;

    public function __construct(string $file)
    {
        if (!function_exists('FFI') || PHP_OS_FAMILY !== 'Linux') {
            return;
        }

        try {
            $this->ffi = FFI::cdef("
                void* mmap(void* addr, size_t length, int prot, int flags, int fd, size_t offset);
                int munmap(void* addr, size_t length);
                int open(const char* pathname, int flags);
                int close(int fd);

                #define PROT_READ 1
                #define MAP_PRIVATE 2
                #define MAP_FILE 0
            ", "libc.so.6");

            $this->size = filesize($file);
            $this->fd   = $this->ffi->open($file, 0); // O_RDONLY

            if ($this->fd < 0) return;

            $this->addr = $this->ffi->mmap(
                null,
                $this->size,
                $this->ffi->PROT_READ,
                $this->ffi->MAP_PRIVATE | $this->ffi->MAP_FILE,
                $this->fd,
                0
            );

            if ($this->addr != FFI::addr(FFI::new("char"))) {
                $this->usingMmap = true;
            }
        } catch (\Throwable $e) {
            // No fallback here by design
        }
    }

    public function isMmap(): bool
    {
        return $this->usingMmap;
    }

    public function readLineAt(int $offset): string
    {
        if (!$this->usingMmap) return "";

        $line = '';

        for ($i = $offset; $i < $this->size; $i++) {
            $c = FFI::string(FFI::addr($this->addr[$i]), 1);
            if ($c === "\n") break;
            $line .= $c;
        }

        return $line;
    }

    public function __destruct()
    {
        if ($this->usingMmap) {
            $this->ffi->munmap($this->addr, $this->size);
            $this->ffi->close($this->fd);
        }
    }
}
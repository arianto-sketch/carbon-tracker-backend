<?php

namespace App\Support;

class Markdown
{
    /**
     * Teks bebas (nama, alasan) di isi email markdown di-escape supaya tidak bisa menyisipkan
     * tautan atau format, mis. "[klik](https://...)".
     */
    public static function escape(string $text): string
    {
        return preg_replace('/([\\\\`*_\[\]()#<>])/', '\\\\$1', $text);
    }
}

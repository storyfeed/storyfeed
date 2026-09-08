<?php

namespace Workbench\Console;

/** Deliberately small graphics experiment, not a general SIXEL implementation. */
class RasterProbe
{
    public static function encode(string $png, string $mode, int $cols): string
    {
        if (! extension_loaded('gd')) {
            throw new \RuntimeException('SIXEL and block conversion require ext-gd');
        }
        $source = imagecreatefromstring($png);
        $width = $mode === 'sixel' ? min(256, $cols * 8) : $cols;
        $height = max(2, (int) round(imagesy($source) * $width / imagesx($source)));
        $raster = imagescale($source, $width, $height);
        if ($mode === 'blocks') {
            $out = '';
            for ($y = 0; $y < $height; $y += 2) {
                for ($x = 0; $x < $width; $x++) {
                    $top = imagecolorat($raster, $x, $y);
                    $bottom = imagecolorat($raster, $x, min($height - 1, $y + 1));
                    $out .= sprintf("\033[38;2;%d;%d;%d;48;2;%d;%d;%dm▀", ($top >> 16) & 255, ($top >> 8) & 255, $top & 255, ($bottom >> 16) & 255, ($bottom >> 8) & 255, $bottom & 255);
                }
                $out .= "\033[0m\n";
            }

            return $out;
        }
        // Uniform 4x4x4 RGB palette: 64 colors, no dithering, six vertical pixels per byte.
        $out = "\033P0;1;0q\"1;1;{$width};{$height}";
        for ($color = 0; $color < 64; $color++) {
            $out .= sprintf('#%d;2;%d;%d;%d', $color, (int) round((($color >> 4) & 3) * 100 / 3), (int) round((($color >> 2) & 3) * 100 / 3), (int) round(($color & 3) * 100 / 3));
        }
        for ($y = 0; $y < $height; $y += 6) {
            $planes = [];
            for ($x = 0; $x < $width; $x++) {
                for ($bit = 0; $bit < 6 && $y + $bit < $height; $bit++) {
                    $pixel = imagecolorat($raster, $x, $y + $bit);
                    $r = (int) round((($pixel >> 16) & 255) / 85);
                    $g = (int) round((($pixel >> 8) & 255) / 85);
                    $b = (int) round(($pixel & 255) / 85);
                    $index = ($r << 4) | ($g << 2) | $b;
                    $planes[$index] ??= array_fill(0, $width, 0);
                    $planes[$index][$x] |= 1 << $bit;
                }
            }
            foreach ($planes as $index => $pixels) {
                $out .= '#'.$index.implode('', array_map(fn ($bits) => chr(63 + $bits), $pixels)).'$';
            }
            $out .= '-';
        }

        return $out."\033\\\n";
    }
}

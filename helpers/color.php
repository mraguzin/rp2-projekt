<?php
namespace Helpers;

class Color
{
    private int $r;
    private int $g;
    private int $b;

    public static function makeRed()
    {
        return new Color(255, 0, 0);
    }

    public static function makeGreen()
    {
        return new Color(0, 255, 0);
    }

    public static function makeBlue()
    {
        return new Color(0, 0, 255);
    }

    public static function lerp(Color $color1, Color $color2, float $t)
    {
        $r = $color1->getRed() * $t + $color2->getRed() * (1.0 - $t);
        $g = $color1->getGreen() * $t + $color2->getGreen() * (1.0 - $t);
        $b = $color1->getBlue() * $t + $color2->getBlue() * (1.0 - $t);

        return new Color(round($r), round($g), round($b));
    }

    public function getRed()
    {
        return $this->r;
    }

    public function getGreen()
    {
        return $this->g;
    }

    public function getBlue()
    {
        return $this->b;
    }

    public function __construct(int $r, int $g, int $b)
    {
        $this->r = $r > 255 ? 255 : $r;
        $this->g = $g > 255 ? 255 : $g;
        $this->b = $b > 255 ? 255 : $b;
    }

    public function getHexString()
    {
        $result = '#';

        $result .= static::byteToHexWithPadding($this->r);
        $result .= static::byteToHexWithPadding($this->g);
        $result .= static::byteToHexWithPadding($this->b);

        return $result;
    }

    public function __toString()
    {
        return $this->getHexString();
    }

    public static function makeFromHex(string $hexColor)
    {
        $pieces = str_split($hexColor, 2);

        $r = hexdec($pieces[0]);
        $g = hexdec($pieces[1]);
        $b = hexdec($pieces[2]);

        return new Color($r, $g, $b);
    }

    private static function byteToHexWithPadding(int $number)
    {
        if ($number < 16) {
            return '0' . dechex($number);
        } else {
            return dechex($number);
        }
    }
}
?>
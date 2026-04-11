<?php

declare(strict_types=1);

namespace App\Support;

use setasign\Fpdi\Fpdi;

class FormPdfFpdi extends Fpdi
{
    public function drawFilledCircle(float $centerX, float $centerY, float $radius): void
    {
        if ($radius <= 0) {
            return;
        }

        $kappa = 0.552284749831;
        $control = $radius * $kappa;
        $k = $this->k;
        $h = $this->h;

        $this->_out(sprintf(
            '%.4F %.4F m '.
            '%.4F %.4F %.4F %.4F %.4F %.4F c '.
            '%.4F %.4F %.4F %.4F %.4F %.4F c '.
            '%.4F %.4F %.4F %.4F %.4F %.4F c '.
            '%.4F %.4F %.4F %.4F %.4F %.4F c f',
            ($centerX + $radius) * $k,
            ($h - $centerY) * $k,
            ($centerX + $radius) * $k,
            ($h - ($centerY + $control)) * $k,
            ($centerX + $control) * $k,
            ($h - ($centerY + $radius)) * $k,
            $centerX * $k,
            ($h - ($centerY + $radius)) * $k,
            ($centerX - $control) * $k,
            ($h - ($centerY + $radius)) * $k,
            ($centerX - $radius) * $k,
            ($h - ($centerY + $control)) * $k,
            ($centerX - $radius) * $k,
            ($h - $centerY) * $k,
            ($centerX - $radius) * $k,
            ($h - ($centerY - $control)) * $k,
            ($centerX - $control) * $k,
            ($h - ($centerY - $radius)) * $k,
            $centerX * $k,
            ($h - ($centerY - $radius)) * $k,
            ($centerX + $control) * $k,
            ($h - ($centerY - $radius)) * $k,
            ($centerX + $radius) * $k,
            ($h - ($centerY - $control)) * $k,
            ($centerX + $radius) * $k,
            ($h - $centerY) * $k,
        ));
    }
}

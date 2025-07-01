<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ParseUtilityService
{
    public function parsePoints($line)
    {
        $line = trim($line);
        if (preg_match('/^(\d+)(?:\/\d+)?$/', $line, $matches)) {
            return (int)$matches[1];
        } elseif (preg_match('/^(N\/A|Not Applicable|n\/a|N\\A)$/i', $line)) {
            return null;
        } elseif ($line === '0' || $line === '0.0') {
            return 0;
        } elseif (preg_match('/^(\d+\.\d+)$/', $line, $matches)) {
            return (float)$matches[1];
        }
        return null;
    }

    public function parsePercent($line)
    {
        $line = trim($line);
        if (preg_match('/^([\d.]+)%?$/', $line, $matches)) {
            return (float)$matches[1];
        } elseif (preg_match('/^(N\/A|Not Applicable|n\/a|N\\A)$/i', $line)) {
            return null;
        }
        return null;
    }

    public function parseAnswer($line)
    {
        $line = trim($line);
        if (preg_match('/^(Yes|No)$/i', $line, $matches)) {
            return ucfirst(strtolower($matches[1]));
        } elseif (preg_match('/^(N\/A|Not Applicable|n\/a|N\\A)$/i', $line)) {
            return null;
        }
        return false;
    }

    public function cleanQuestionText($text)
    {
        $text = str_replace(['Comments', 'Current to Total'], '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}

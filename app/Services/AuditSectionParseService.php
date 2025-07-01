<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class AuditSectionParseService
{
    protected $sectionHeaders = [
        'Restaurant Front', 'Lobby & Front Counter', 'Landing Stations',
        'Pizza Dress', 'Pressouts', 'Product Prep/Walk-In',
        'Administrative', 'Equipment', 'Cleanliness', 'Miscellaneous',
        'Imminent Health Risk - refer to training'
    ];

    protected $reportCategories = [
        'Image', 'Food Safety & Sanitation', 'Customer Service', 'Cleanliness', 'HOT-N-READY',
        'Equipment', 'Management', 'Product Preparation'
    ];

    protected $parseUtilityService;

    public function __construct(ParseUtilityService $parseUtilityService)
    {
        $this->parseUtilityService = $parseUtilityService;
    }

    public function parse(array $lines): array
    {
        $sections = [];
        $currentSection = null;
        $currentSectionIndex = -1;
        $expectingData = null;

        for ($i = 0; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) {
                Log::debug("Skipping empty line $i");
                continue;
            }

            // Clean leading non-printable characters
            $originalLine = $line;
            if (preg_match('/^[\x00-\x1F\x7F]+(.+)/', $line, $matches)) {
                $line = $matches[1];
                Log::debug("Normalized line $i: Removed leading control characters from '$originalLine' to '$line'");
            }

            // Section header detection
            foreach ($this->sectionHeaders as $header) {
                if (preg_match('/^' . preg_quote($header, '/') . '$/', $line)) {
                    $currentSection = $header;
                    $expectingData = 'points';
                    $sectionExists = false;
                    foreach ($sections as $section) {
                        if ($section['category'] === $currentSection) {
                            $sectionExists = true;
                            break;
                        }
                    }
                    if (!$sectionExists) {
                        $sections[] = [
                            'section_type' => 'audit_category',
                            'category' => $currentSection,
                            'points' => 0,
                            'total_points' => 0,
                            'score' => 0,
                        ];
                        $currentSectionIndex = count($sections) - 1;
                        Log::debug("Section header: $currentSection, initializing totals parsing");
                    }
                    continue 2;
                }
            }

            // Section totals
            if ($currentSection && in_array($expectingData, ['points', 'total_points', 'score'])) {
                // Skip report category lines during totals parsing
                if (in_array($line, $this->reportCategories)) {
                    Log::debug("Skipping report category during totals parsing: $line");
                    continue;
                }

                if ($expectingData === 'points') {
                    $points = $this->parseUtilityService->parsePoints($line);
                    if ($points !== null || $this->isNADescription($line)) {
                        $sections[$currentSectionIndex]['points'] = $points;
                        $expectingData = 'total_points';
                        Log::debug("Set points for {$currentSection}: " . ($points ?? 'N/A'));
                    } else {
                        Log::debug("Invalid points value skipped: $line");
                    }
                } elseif ($expectingData === 'total_points') {
                    $total_points = $this->parseUtilityService->parsePoints($line);
                    if ($total_points !== null || $this->isNADescription($line)) {
                        $sections[$currentSectionIndex]['total_points'] = $total_points;
                        $expectingData = 'score';
                        Log::debug("Set total_points for {$currentSection}: " . ($total_points ?? 'N/A'));
                    } else {
                        Log::debug("Invalid total_points value skipped: $line");
                    }
                } elseif ($expectingData === 'score') {
                    $score = $this->parseUtilityService->parsePercent($line);
                    if ($score !== null || $this->isNADescription($line)) {
                        $sections[$currentSectionIndex]['score'] = $score;
                        $expectingData = null;
                        $currentSection = null;
                        Log::debug("Set score for {$currentSection}: " . ($score ?? 'N/A'));
                    } else {
                        Log::debug("Invalid score value skipped: $line");
                    }
                }
                continue;
            }

            // End of section
            if (preg_match('/^Totals\s*(N\/A|\s*)$/i', $line)) {
                $currentSection = null;
                $expectingData = null;
                Log::debug("End of section detected");
            }
        }

        Log::debug('Parsed sections: ' . json_encode($sections));
        return $sections;
    }

    protected function isNADescription($line)
    {
        return preg_match('/^(N\/A|Not Applicable|n\/a|N\\A)$/i', trim($line));
    }
}

<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AuditPdfParserService
{
    /**
     * Parse raw PDF text into structured JSON format.
     *
     * @param string $text The raw text from Spatie's pdf-to-text
     * @return array Structured data for AuditReport, AuditSection, and AuditQuestion models
     */
    public function parse($text)
    {
        dd($text);
        // Preprocess text to normalize spaces and line breaks
        $text = preg_replace('/\s+/', ' ', $text); // Collapse multiple spaces
        $text = preg_replace('/\n+/', "\n", $text); // Collapse multiple newlines
        $lines = explode("\n", $text);
        $parsedData = [
            'audit_report' => [],
            'audit_sections' => [],
            'audit_questions' => []
        ];
        $currentCategory = '';
        $inTableSection = false;
        $currentQuestion = null;
        $comments = [];
        $inCategorySummary = false;
        $sectionType = null;

        // Log raw text for debugging
        Log::debug('Raw PDF text length: ' . strlen($text));
        Log::debug('First 500 chars of text: ' . substr($text, 0, 500));

        // Extract header metadata with improved regex
        $parsedData['audit_report'] = [
            'restaurant_id'   => $this->matchText('/Restaurant ID:\s*(\$?\d+-\d+\$?)/', $text),
            'restaurant_name' => $this->matchText('/Restaurant:\s*([^\n]+)/', $text),
            'address'         => $this->matchText('/Address:\s*([^\n]+)/', $text),
            'city_state_zip'  => $this->matchText('/City\/State\/Zip:\s*([^\n]+)/', $text),
            'phone'           => $this->matchText('/Phone:\s*(\$?\d{3}-\d{3}-\d{4}\$?)/', $text),
            'contact_name'    => $this->matchText('/Contact Name:\s*([^\n]+)/', $text),
            'contact_email'   => $this->matchText('/Contact Email:\s*([^\n]+)/', $text),
            'form_name'       => $this->matchText('/Form Name:\s*([^\n]+)/', $text),
            'form_type'       => $this->matchText('/Type:\s*([^\n]+)/', $text),
            'start_date'      => $this->matchText('/Start Date:\s*([^\n]+)/', $text),
            'end_date'        => $this->matchText('/End Date:\s*([^\n]+)/', $text),
            'upload_date'     => $this->matchText('/Upload Date:\s*([^\n]+)/', $text),
            'auditor'         => $this->matchText('/Auditor:\s*([^\n]+)/', $text),
            'overall_score'   => $this->matchText('/Overall Score\s*([\d.]+%)/', $text, 'float'),
        ];

        // Convert dates to ISO 8601
        foreach (['start_date', 'end_date', 'upload_date'] as $dateField) {
            if ($parsedData['audit_report'][$dateField]) {
                try {
                    $parsedData['audit_report'][$dateField] = Carbon::parse($parsedData['audit_report'][$dateField])->toIso8601String();
                } catch (\Exception $e) {
                    Log::warning('Failed to parse date for ' . $dateField . ': ' . $parsedData['audit_report'][$dateField]);
                    $parsedData['audit_report'][$dateField] = null;
                }
            }
        }

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Log line for debugging
            Log::debug("Processing line $index: $line");

            // Detect Category Summary sections
            if (preg_match('/^(Category Summary|Report Category Summary):/', $line)) {
                $inCategorySummary = true;
                $sectionType = $line === 'Category Summary:' ? 'summary' : 'report_summary';
                Log::debug("Entered $sectionType section");
                continue;
            }

            // Parse category summary rows
            if ($inCategorySummary && preg_match('/^([A-Za-z\s&\/\-]+(?: - refer to training)?)\s+(N\/A|\d+)\s+(N\/A|\d+)\s+(N\/A|[\d.]+%)/', $line, $matches)) {
                $parsedData['audit_sections'][] = [
                    'section_type' => $sectionType,
                    'category' => trim($matches[1]),
                    'points' => $matches[2] === 'N/A' ? null : intval($matches[2]),
                    'total_points' => $matches[3] === 'N/A' ? null : intval($matches[3]),
                    'score' => $matches[4] === 'N/A' ? null : $matches[4],
                ];
                Log::debug("Parsed section: " . json_encode(end($parsedData['audit_sections'])));
            }

            // Stop category summary parsing
            if ($inCategorySummary && preg_match('/^(Restaurant Front|Imminent Health Risk|Lobby & Front Counter)/', $line)) {
                $inCategorySummary = false;
                Log::debug("Exited category summary section");
            }

            // Detect section headers
            if (preg_match('/^([A-Za-z][A-Za-z\s&\/\-]+)$/', $line) &&
                !preg_match('/^(Image|Yes|No|N\/A|\d|Totals)/', $line)) {
                $currentCategory = $line;
                $inTableSection = true;
                Log::debug("Entered table section: $currentCategory");
                continue;
            }

            // Skip table headers
            if (preg_match('/Report\s+Category|Question|Answer|Points|Percent/', $line)) {
                Log::debug("Skipped table header: $line");
                continue;
            }

            // Parse table rows
            if ($inTableSection && preg_match('/^([A-Za-z][A-Za-z\s&\/\-]+)\s+(.+?)\s+(Yes|No|N\/A)\s+(N\/A|\d+)\s+(N\/A|\d+)\s+(N\/A|[\d.]+%)/', $line, $matches)) {
                // Save previous question if exists
                if ($currentQuestion) {
                    $parsedData['audit_questions'][] = [
                        'report_category' => $currentQuestion['report_category'],
                        'question' => $currentQuestion['question'],
                        'answer' => $currentQuestion['answer'],
                        'points_current' => $currentQuestion['points_current'] === 'N/A' ? null : intval($currentQuestion['points_current']),
                        'points_total' => $currentQuestion['points_total'] === 'N/A' ? null : intval($currentQuestion['points_total']),
                        'percent' => $currentQuestion['percent'] === 'N/A' ? null : $currentQuestion['percent'],
                        'comments' => implode("\n", $comments) ?: null,
                    ];
                    Log::debug("Saved question: " . json_encode(end($parsedData['audit_questions'])));
                    $comments = [];
                }

                // Start new question
                $reportCategory = trim($matches[1]);
                if (in_array($reportCategory, ['Image', 'Customer', 'HOT-N-READY', 'Equipment', 'Product', 'Food', 'Management', 'Cleanliness', 'Food Safety & Sanitation'])) {
                    $reportCategory = $currentCategory;
                }

                $currentQuestion = [
                    'report_category' => $reportCategory,
                    'question' => trim($matches[2]),
                    'answer' => trim($matches[3]),
                    'points_current' => $matches[4],
                    'points_total' => $matches[5],
                    'percent' => $matches[6],
                ];
                Log::debug("Started new question: " . json_encode($currentQuestion));
            }

            // Handle comments
            if ($inTableSection && preg_match('/^\s*•\s*(.+)/', $line, $commentMatch)) {
                $comments[] = trim($commentMatch[1]);
                Log::debug("Added comment: " . $commentMatch[1]);
            }

            // Detect end of section
            if ($inTableSection && preg_match('/^Totals\s+(N\/A|\d+)\s+(N\/A|\d+)\s+(N\/A|[\d.]+%)/', $line)) {
                if ($currentQuestion) {
                    $parsedData['audit_questions'][] = [
                        'report_category' => $currentQuestion['report_category'],
                        'question' => $currentQuestion['question'],
                        'answer' => $currentQuestion['answer'],
                        'points_current' => $currentQuestion['points_current'] === 'N/A' ? null : intval($currentQuestion['points_current']),
                        'points_total' => $currentQuestion['points_total'] === 'N/A' ? null : intval($currentQuestion['points_total']),
                        'percent' => $currentQuestion['percent'] === 'N/A' ? null : $currentQuestion['percent'],
                        'comments' => implode("\n", $comments) ?: null,
                    ];
                    Log::debug("Saved question at section end: " . json_encode(end($parsedData['audit_questions'])));
                    $currentQuestion = null;
                    $comments = [];
                }
                $inTableSection = false;
                Log::debug("Exited table section");
            }
        }

        // Save the last question if exists
        if ($currentQuestion) {
            $parsedData['audit_questions'][] = [
                'report_category' => $currentQuestion['report_category'],
                'question' => $currentQuestion['question'],
                'answer' => $currentQuestion['answer'],
                'points_current' => $currentQuestion['points_current'] === 'N/A' ? null : intval($currentQuestion['points_current']),
                'points_total' => $currentQuestion['points_total'] === 'N/A' ? null : intval($currentQuestion['points_total']),
                'percent' => $currentQuestion['percent'] === 'N/A' ? null : $currentQuestion['percent'],
                'comments' => implode("\n", $comments) ?: null,
            ];
            Log::debug("Saved final question: " . json_encode(end($parsedData['audit_questions'])));
        }

        Log::debug('Parsed data: ' . json_encode($parsedData, JSON_PRETTY_PRINT));
        return $parsedData;
    }

    /**
     * Helper method to safely extract regex match.
     *
     * @param string $pattern Regex pattern to match
     * @param string $text Text to search
     * @param string $cast Cast type (string or float)
     * @return mixed|null Extracted value or null if not found
     */
    private function matchText(string $pattern, string $text, string $cast = 'string')
    {
        preg_match($pattern, $text, $matches);
        if (!isset($matches[1])) {
            Log::warning('No match found for pattern: ' . $pattern);
            return null;
        }

        $value = trim($matches[1]);
        return $cast === 'float' ? floatval(str_replace('%', '', $value)) : $value;
    }
}

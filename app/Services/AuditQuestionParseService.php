<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class AuditQuestionParseService
{
    protected $reportCategories = [
        'Image', 'Food Safety & Sanitation', 'Customer Service', 'Cleanliness', 'HOT-N-READY',
        'Equipment', 'Management', 'Product Preparation'
    ];

    protected $parseUtilityService;

    public function __construct(ParseUtilityService $parseUtilityService)
    {
        $this->parseUtilityService = $parseUtilityService;
    }

    public function parse(array $lines, array $sections): array
    {
        $questions = [];
        $currentSection = null;
        $currentReportCategory = null;
        $questionOrder = 1;
        $inQuestionTable = false;
        $currentQuestion = null;
        $expectingData = null;
        $sectionHeaders = array_column($sections, 'category');

        for ($i = 0; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) {
                continue;
            }

            // Clean leading non-printable characters
            $originalLine = $line;
            if (preg_match('/^[\x00-\x1F\x7F]+(.+)/', $line, $matches)) {
                $line = $matches[1];
                Log::debug("Normalized line $i: Removed leading control characters from '$originalLine' to '$line'");
            }

            // Handle multi-line categories
            $isPartialCategory = false;
            foreach ($this->reportCategories as $category) {
                if (strpos($category, $line) === 0 && $line !== $category) {
                    $isPartialCategory = true;
                    break;
                }
            }
            if ($isPartialCategory && isset($lines[$i + 1])) {
                $combinedLine = $line . ' ' . trim($lines[$i + 1]);
                foreach ($this->reportCategories as $category) {
                    if (trim($combinedLine) === $category) {
                        $line = $combinedLine;
                        $i++;
                        Log::debug("Combined multi-line category: " . $line);
                        break;
                    }
                }
            }

            // Section header detection
            foreach ($sectionHeaders as $header) {
                if (preg_match('/^' . preg_quote($header, '/') . '$/', $line)) {
                    if ($currentQuestion) {
                        $this->finalizeQuestion($currentQuestion, $questions, $questionOrder);
                        $currentQuestion = null;
                    }
                    $currentSection = $header;
                    $inQuestionTable = true;
                    $expectingData = 'report_category';
                    Log::debug("Section header: $currentSection");
                    continue;
                }
            }

            // Skip table headers
            if (preg_match('/^(Report\s*Category|Question|Answer|Points|Percent)/i', $line)) {
                continue;
            }

            if ($inQuestionTable) {
                // Report category detection
                foreach ($this->reportCategories as $category) {
                    if (trim($line) === $category) {
                        if ($currentQuestion) {
                            $this->finalizeQuestion($currentQuestion, $questions, $questionOrder);
                            $currentQuestion = null;
                        }
                        $currentReportCategory = $category;
                        $expectingData = 'question';
                        Log::debug("Set report category: $currentReportCategory");
                        continue 2;
                    }
                }

                switch ($expectingData) {
                    case 'report_category':
                        if (in_array(trim($line), $this->reportCategories)) {
                            $currentReportCategory = $line;
                            $expectingData = 'question';
                            Log::debug("Set report category: $currentReportCategory");
                        }
                        break;

                    case 'question':
                        if (!in_array(trim($line), $this->reportCategories) &&
                            !preg_match('/^(Totals|Comments)/i', $line) &&
                            !preg_match('/^[\d.]+%$/', $line)) {
                            if (strlen($line) >= 5 && !preg_match('/^\d+$/', $line) && !preg_match('/^[\d.]+%$/', $line)) {
                                if ($currentQuestion) {
                                    $this->finalizeQuestion($currentQuestion, $questions, $questionOrder);
                                }
                                $currentQuestion = [
                                    'section_category' => $currentSection,
                                    'report_category' => $currentReportCategory,
                                    'question' => $line,
                                    'answer' => null,
                                    'points_current' => 0,
                                    'points_total' => 0,
                                    'percent' => "0.00%",
                                    'comments' => null,
                                    'order' => $questionOrder,
                                ];
                                $expectingData = 'answer';
                                Log::debug("New question: " . $line);
                            } else {
                                Log::debug("Skipping invalid question text: " . $line);
                            }
                        }
                        break;

                    case 'answer':
                        $answer = $this->parseUtilityService->parseAnswer($line);
                        if ($answer !== false) {
                            $currentQuestion['answer'] = $answer;
                            $expectingData = 'points_current';
                            Log::debug("Set answer: " . ($currentQuestion['answer'] ?? 'null'));
                        } else {
                            $currentQuestion['question'] .= ' ' . $line;
                            Log::debug("Appended to question (answer): " . $currentQuestion['question']);
                        }
                        break;

                    case 'points_current':
                        $points = $this->parseUtilityService->parsePoints($line);
                        if ($points !== null) {
                            $currentQuestion['points_current'] = $points;
                            $expectingData = 'points_total';
                            Log::debug("Set points_current: " . $points);
                        } else {
                            $percent = $this->parseUtilityService->parsePercent($line);
                            if ($percent !== null) {
                                $currentQuestion['percent'] = $percent;
                                $this->finalizeQuestion($currentQuestion, $questions, $questionOrder);
                                $currentQuestion = null;
                                $expectingData = 'report_category';
                                Log::debug("Set percent (early): $percent");
                            } else {
                                $currentQuestion['question'] .= ' ' . $line;
                                Log::debug("Appended to question (points_current): " . $currentQuestion['question']);
                            }
                        }
                        break;

                    case 'points_total':
                        $points = $this->parseUtilityService->parsePoints($line);
                        if ($points !== null) {
                            $currentQuestion['points_total'] = $points;
                            $expectingData = 'percent';
                            Log::debug("Set points_total: " . $points);
                        } else {
                            $percent = $this->parseUtilityService->parsePercent($line);
                            if ($percent !== null) {
                                $currentQuestion['percent'] = $percent;
                                $this->finalizeQuestion($currentQuestion, $questions, $questionOrder);
                                $currentQuestion = null;
                                $expectingData = 'report_category';
                                Log::debug("Set percent (early): $percent");
                            } else {
                                $currentQuestion['question'] .= ' ' . $line;
                                Log::debug("Appended to question (points_total): " . $currentQuestion['question']);
                            }
                        }
                        break;

                    case 'percent':
                        $percent = $this->parseUtilityService->parsePercent($line);
                        if ($percent !== null) {
                            $currentQuestion['percent'] = $percent;
                            $this->finalizeQuestion($currentQuestion, $questions, $questionOrder);
                            $currentQuestion = null;
                            $expectingData = 'report_category';
                            Log::debug("Set percent: $percent");
                        } else {
                            $currentQuestion['question'] .= ' ' . $line;
                            Log::debug("Appended to question (percent): " . $currentQuestion['question']);
                        }
                        break;
                }

                // Handle comments (bullet points)
                if (preg_match('/^•\s*(.+)/', $line, $matches)) {
                    $commentText = trim($matches[1]);
                    if (!empty($questions)) {
                        $lastIndex = count($questions) - 1;
                        $questions[$lastIndex]['comments'] =
                            ($questions[$lastIndex]['comments'] ?? '') . ' ' . $commentText;
                    } elseif ($currentQuestion) {
                        $currentQuestion['comments'] =
                            ($currentQuestion['comments'] ?? '') . ' ' . $commentText;
                    }
                    Log::debug("Added comment: " . $commentText);
                }
            }

            // Handle end of section
            if (preg_match('/^Totals\s*(N\/A|\s*)$/i', $line)) {
                if ($currentQuestion) {
                    $this->finalizeQuestion($currentQuestion, $questions, $questionOrder);
                    $currentQuestion = null;
                }
                $inQuestionTable = false;
                $currentSection = null;
                $currentReportCategory = null;
                $expectingData = null;
                Log::debug("End of section detected");
            }
        }

        if ($currentQuestion) {
            $this->finalizeQuestion($currentQuestion, $questions, $questionOrder);
        }

        Log::debug('Parsed questions: ' . json_encode($questions));
        return $questions;
    }

    protected function finalizeQuestion(&$question, &$questions, &$order)
    {
        $question['question'] = $this->parseUtilityService->cleanQuestionText($question['question']);
        if (strlen($question['question']) < 5 ||
            preg_match('/^\d+$/', $question['question']) ||
            preg_match('/^[\d.]+%$/', $question['question'])) {
            Log::warning("Discarded invalid question: " . json_encode($question));
        } else {
            $questions[] = $question;
            $order++;
            Log::debug("Saved question: " . json_encode($question));
        }
    }
}

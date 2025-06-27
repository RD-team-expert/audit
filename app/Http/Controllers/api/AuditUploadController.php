<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\AuditReport;
use App\Models\AuditSection;
use App\Models\AuditQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Spatie\PdfToText\Pdf;
use Carbon\Carbon;

class AuditUploadController extends Controller
{
    public function upload(Request $request)
    {
        try {
            $request->validate([
                'file' => 'required|file|mimes:pdf|max:10000',
            ]);

            $file = $request->file('file');
            $path = $file->store('audits');
            $fullPath = Storage::path($path);

            Log::info('PDF uploaded: ' . $fullPath);

            $pdfToolPath = config('pdf.pdftotext_path', 'C:\poppler\poppler-24.08.0\Library\bin\pdftotext.exe');
            $rawText = Pdf::getText($fullPath, $pdfToolPath);

            if (empty($rawText)) {
                throw new \Exception('Failed to extract text from PDF');
            }

            Log::info('PDF text extracted, length: ' . strlen($rawText));
            Log::debug('Raw PDF text sample: ' . substr($rawText, 0, 2000));

            $parsedData = $this->parsePdfText($rawText);

            Log::info('Parsed data: ' . json_encode($parsedData, JSON_PRETTY_PRINT));

            if (empty($parsedData['restaurant']['restaurant_id'])) {
                Log::error('Missing restaurant_id in parsed data', ['restaurant' => $parsedData['restaurant']]);
                throw new \Exception('Missing required field: restaurant_id');
            }

            $report = DB::transaction(function () use ($parsedData) {
                $restaurant = Restaurant::firstOrCreate(
                    ['restaurant_id' => $parsedData['restaurant']['restaurant_id']],
                    [
                        'name' => $parsedData['restaurant']['name'] ?? 'Unknown Restaurant',
                        'address' => $parsedData['restaurant']['address'] ?? null,
                        'city' => $parsedData['restaurant']['city'] ?? null,
                        'state' => $parsedData['restaurant']['state'] ?? null,
                        'zip' => $parsedData['restaurant']['zip'] ?? null,
                        'phone' => $parsedData['restaurant']['phone'] ?? null,
                        'contact_name' => $parsedData['restaurant']['contact_name'] ?? null,
                        'contact_email' => $parsedData['restaurant']['contact_email'] ?? null,
                    ]
                );

                $report = AuditReport::create([
                    'restaurant_id' => $restaurant->id,
                    'form_name' => $parsedData['form_name'] ?? 'Unknown Form',
                    'form_type' => $parsedData['form_type'] ?? 'Unknown Type',
                    'start_date' => $parsedData['start_date'] ? Carbon::parse($parsedData['start_date']) : now(),
                    'end_date' => $parsedData['end_date'] ? Carbon::parse($parsedData['end_date']) : now(),
                    'upload_date' => $parsedData['upload_date'] ? Carbon::parse($parsedData['upload_date']) : now(),
                    'auditor' => $parsedData['auditor'] ?? 'Unknown Auditor',
                    'overall_score' => $parsedData['overall_score'] ?? 0.0,
                ]);

                $sectionIdMap = [];
                foreach ($parsedData['sections'] as $section) {
                    try {
                        $sectionRecord = AuditSection::firstOrCreate(
                            [
                                'audit_report_id' => $report->id,
                                'category' => $section['category']
                            ],
                            [
                                'section_type' => $section['section_type'],
                                'points' => $section['points'],
                                'total_points' => $section['total_points'],
                                'score' => $section['score'],
                            ]
                        );
                        $sectionIdMap[$section['category']] = $sectionRecord->id;
                        Log::info("Created section: {$section['category']}, ID: {$sectionRecord->id}, Points: {$section['points']}, Total Points: {$section['total_points']}, Score: {$section['score']}");
                    } catch (\Exception $e) {
                        Log::error("Failed to create section: " . $e->getMessage(), [
                            'section' => $section,
                            'report_id' => $report->id
                        ]);
                        throw $e;
                    }
                }

                foreach ($parsedData['questions'] as $question) {
                    try {
                        $sectionId = $sectionIdMap[$question['section_category']] ?? null;
                        if (!$sectionId) {
                            Log::error("No section ID found for category: {$question['section_category']}", [
                                'question' => $question
                            ]);
                            continue;
                        }
                        if (strlen($question['question']) < 5 || preg_match('/^\d+$/', $question['question']) || preg_match('/^[\d.]+%$/', $question['question'])) {
                            Log::warning("Skipping invalid question: {$question['question']}");
                            continue;
                        }
                        AuditQuestion::create([
                            'audit_section_id' => $sectionId,
                            'report_category' => $question['report_category'],
                            'question' => $this->cleanQuestionText($question['question']),
                            'answer' => $question['answer'],
                            'points_current' => $question['points_current'],
                            'points_total' => $question['points_total'],
                            'percent' => $question['percent'],
                            'comments' => $question['comments'],
                            'order' => $question['order'],
                        ]);
                        Log::info("Inserted question: {$question['question']}, Section ID: {$sectionId}");
                    } catch (\Exception $e) {
                        Log::error("Failed to insert question: {$e->getMessage()}", [
                            'question' => $question,
                            'section_id' => $sectionId ?? 'null'
                        ]);
                        throw $e;
                    }
                }

                return $report;
            });

            if (!config('audit.retain_pdfs', false)) {
                Storage::delete($path);
                Log::info('PDF deleted: ' . $path);
            }

            return response()->json([
                'message' => '✅ File parsed and data saved successfully.',
                'report_id' => $report->id,
            ]);

        } catch (\Throwable $e) {
            Log::error('Upload failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'error' => 'Upload failed',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    protected function parsePdfText($rawText)
    {
        $lines = array_map('trim', explode("\n", $rawText));
        $parsedData = [
            'restaurant' => [],
            'form_name' => null,
            'form_type' => null,
            'start_date' => null,
            'end_date' => null,
            'upload_date' => null,
            'auditor' => null,
            'overall_score' => null,
            'sections' => [],
            'questions' => [],
        ];

        // State tracking variables
        $currentSection = null;
        $currentReportCategory = null;
        $questionOrder = 1;
        $inQuestionTable = false;
        $currentQuestion = null;
        $expectingData = null;
        $inMetadataSection = false;
        $currentSectionIndex = -1;
        $currentComments = []; // Array to collect comments

        // Known headers
        $sectionHeaders = [
            'Restaurant Front', 'Lobby & Front Counter', 'Landing Stations',
            'Pizza Dress', 'Pressouts', 'Product Prep/Walk-In',
            'Administrative', 'Equipment', 'Cleanliness', 'Miscellaneous',
            'Imminent Health Risk - refer to training'
        ];

        $reportCategories = [
            'Image', 'Food Safety & Sanitation', 'Customer Service', 'Cleanliness', 'HOT-N-READY',
            'Equipment', 'Management', 'Product Preparation'
        ];

        // Log all lines with hex for debugging non-printable characters
        Log::debug('PDF Lines: ' . json_encode(array_map(function($line, $index) { return "Line $index: " . bin2hex($line); }, $lines, array_keys($lines))));

        for ($i = 0; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) {
                continue;
            }

            // Clean only leading non-printable characters for section headers
            $originalLine = $line;
            if (preg_match('/^[\x00-\x1F\x7F]+(.+)/', $line, $matches)) {
                $line = $matches[1]; // Keep the readable part after non-printable characters
                Log::debug("Normalized line $i: Removed leading control characters from '$originalLine' to '$line'");
            }

            Log::debug("Processing line $i: [" . addslashes($line) . "], State: $expectingData");

            // Handle multi-line categories
            $isPartialCategory = false;
            foreach ($reportCategories as $category) {
                if (strpos($category, $line) === 0 && $line !== $category) {
                    $isPartialCategory = true;
                    break;
                }
            }

            if ($isPartialCategory && isset($lines[$i + 1])) {
                $combinedLine = $line . ' ' . trim($lines[$i + 1]);
                foreach ($reportCategories as $category) {
                    if (trim($combinedLine) === $category) {
                        $line = $combinedLine;
                        $i++;
                        Log::debug("Combined multi-line category: " . $line);
                        break;
                    }
                }
            }

            // Metadata parsing
            if (preg_match('/Restaurant Information/', $line)) {
                $inMetadataSection = true;
                continue;
            }

            if ($inMetadataSection) {
                if (preg_match('/Restaurant ID\s*:\s*(\S+)/i', $line, $matches)) {
                    $parsedData['restaurant']['restaurant_id'] = $matches[1];
                    continue;
                }
                if (preg_match('/Restaurant\s*:\s*(.+)/i', $line, $matches)) {
                    $parsedData['restaurant']['name'] = trim($matches[1]);
                    continue;
                }
                if (preg_match('/Address\s*:\s*(.+)/i', $line, $matches)) {
                    $parsedData['restaurant']['address'] = trim($matches[1]);
                    continue;
                }
                if (preg_match('/City\/State\/Zip\s*:\s*([^,]+),\s*([A-Z]{2})\s*(\d{5})/i', $line, $matches)) {
                    $parsedData['restaurant']['city'] = trim($matches[1]);
                    $parsedData['restaurant']['state'] = $matches[2];
                    $parsedData['restaurant']['zip'] = $matches[3];
                    continue;
                }
                if (preg_match('/Phone\s*:\s*(\S+)/i', $line, $matches)) {
                    $parsedData['restaurant']['phone'] = $matches[1];
                    continue;
                }
                if (preg_match('/Contact Name\s*:\s*(.+)/i', $line, $matches)) {
                    $parsedData['restaurant']['contact_name'] = trim($matches[1]);
                    continue;
                }
                if (preg_match('/Contact Email\s*:\s*(.+)/i', $line, $matches)) {
                    $parsedData['restaurant']['contact_email'] = trim($matches[1]);
                    continue;
                }
                if (preg_match('/Form Name\s*:\s*(.+)/i', $line, $matches)) {
                    $parsedData['form_name'] = trim($matches[1]);
                    continue;
                }
                if (preg_match('/Type\s*:\s*(.+)/i', $line, $matches)) {
                    $parsedData['form_type'] = trim($matches[1]);
                    continue;
                }
                if (preg_match('/Start Date\s*:\s*(.+)/i', $line, $matches)) {
                    $parsedData['start_date'] = trim($matches[1]);
                    continue;
                }
                if (preg_match('/End Date\s*:\s*(.+)/i', $line, $matches)) {
                    $parsedData['end_date'] = trim($matches[1]);
                    continue;
                }
                if (preg_match('/Upload Date\s*:\s*(.+)/i', $line, $matches)) {
                    $parsedData['upload_date'] = trim($matches[1]);
                    continue;
                }
                if (preg_match('/Auditor\s*:\s*(.+)/i', $line, $matches)) {
                    $parsedData['auditor'] = trim($matches[1]);
                    continue;
                }
                if (preg_match('/Overall Score\s*:\s*([\d.]+)/i', $line, $matches)) {
                    $parsedData['overall_score'] = (float)$matches[1];
                    $inMetadataSection = false;
                    continue;
                }
            }

            // Section parsing
            foreach ($sectionHeaders as $header) {
                // Match section header alone
                if (preg_match('/^' . preg_quote($header, '/') . '$/', $line, $matches)) {
                    if ($currentQuestion) {
                        $this->finalizeQuestion($currentQuestion, $parsedData, $questionOrder);
                        $currentQuestion = null;
                    }

                    $currentSection = $header;
                    $inQuestionTable = true;
                    $expectingData = 'points';

                    $sectionExists = false;
                    foreach ($parsedData['sections'] as $section) {
                        if ($section['category'] === $currentSection) {
                            $sectionExists = true;
                            break;
                        }
                    }

                    if (!$sectionExists) {
                        $sectionData = [
                            'section_type' => 'audit_category',
                            'category' => $currentSection,
                            'points' => null,
                            'total_points' => null,
                            'score' => null,
                        ];
                        $parsedData['sections'][] = $sectionData;
                        $currentSectionIndex = count($parsedData['sections']) - 1;
                        Log::debug("Section header: $currentSection");
                    }
                    continue;
                }
            }

            // Handle section totals
            if ($currentSection && in_array($expectingData, ['points', 'total_points', 'score'])) {
                $line = trim($line);
                if (empty($line)) continue;

                if ($expectingData === 'points') {
                    $points = $this->parsePoints($line);
                    if ($points !== null || $line === 'N/A' || $line === 'n/a' || $line === 'Not Applicable') {
                        $parsedData['sections'][$currentSectionIndex]['points'] = $points;
                        $expectingData = 'total_points';
                        Log::debug("Set points for {$currentSection}: $points");
                    }
                } elseif ($expectingData === 'total_points') {
                    $total_points = $this->parsePoints($line);
                    if ($total_points !== null || $line === 'N/A' || $line === 'n/a' || $line === 'Not Applicable') {
                        $parsedData['sections'][$currentSectionIndex]['total_points'] = $total_points;
                        $expectingData = 'score';
                        Log::debug("Set total_points for {$currentSection}: $total_points");
                    }
                } elseif ($expectingData === 'score') {
                    $score = $this->parsePercent($line);
                    if ($score !== null || $line === 'N/A' || $line === 'n/a' || $line === 'Not Applicable') {
                        $parsedData['sections'][$currentSectionIndex]['score'] = $score;
                        $expectingData = 'report_category';
                        Log::debug("Set score for {$currentSection}: $score");
                    }
                }
            }


            // Skip table headers
            if (preg_match('/^(Report\s*Category|Question|Answer|Points|Percent)/i', $line)) {
                continue;
            }

            // Question parsing
            if ($inQuestionTable) {
                // Detect report categories
                foreach ($reportCategories as $category) {
                    if (trim($line) === $category) {
                        if ($currentQuestion) {
                            $currentQuestion['comments'] = implode(' ', $currentComments);
                            $this->finalizeQuestion($currentQuestion, $parsedData, $questionOrder);
                            $currentQuestion = null;
                            $currentComments = [];
                        }
                        $currentReportCategory = $category;
                        $expectingData = 'question';
                        Log::debug("Set report category: $currentReportCategory");
                        continue 2; // Break both loops
                    }
                }

                // Handle current question state
                switch ($expectingData) {
                    case 'report_category':
                        if (in_array(trim($line), $reportCategories)) {
                            $currentReportCategory = $line;
                            $expectingData = 'question';
                            Log::debug("Set report category: $currentReportCategory");
                        }
                        break;

                    case 'question':
                        if (!in_array(trim($line), $reportCategories) &&
                            !preg_match('/^(Totals|Comments)/i', $line) &&
                            !preg_match('/^[\d.]+%$/', $line)) {
                            if (strlen($line) >= 5 && !preg_match('/^\d+$/', $line) && !preg_match('/^[\d.]+%$/', $line)) {
                                if ($currentQuestion) {
                                    $currentQuestion['comments'] = implode(' ', $currentComments);
                                    $this->finalizeQuestion($currentQuestion, $parsedData, $questionOrder);
                                    $currentComments = [];
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
                        $answer = $this->parseAnswer($line);
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
                        $points = $this->parsePoints($line);
                        if ($points !== null) {
                            $currentQuestion['points_current'] = $points;
                            $expectingData = 'points_total';
                            Log::debug("Set points_current: " . $points);
                        } else {
                            Log::debug("Skipping invalid points_current: " . $line);
                        }
                        break;

                    case 'points_total':
                        $points = $this->parsePoints($line);
                        if ($points !== null) {
                            $currentQuestion['points_total'] = $points;
                            $expectingData = 'percent';
                            Log::debug("Set points_total: " . $points);
                        } else {
                            Log::debug("Skipping invalid points_total: " . $line);
                        }
                        break;

                    case 'percent':
                        $percent = $this->parsePercent($line);
                        if ($percent !== null) {
                            $currentQuestion['percent'] = $percent;
                            $expectingData = 'comments';
                            Log::debug("Set percent: " . $percent);
                        } else {
                            Log::debug("Skipping invalid percent: " . $line);
                        }
                        break;

                    case 'comments':
                        if (preg_match('/^•\s*(.+)/', $line, $matches)) {
                            $currentComments[] = trim($matches[1]);
                            Log::debug("Added comment: " . $matches[1]);
                        } elseif (in_array(trim($line), $reportCategories) || preg_match('/^Question$/i', $line)) {
                            if ($currentQuestion) {
                                $currentQuestion['comments'] = implode(' ', $currentComments);
                                $this->finalizeQuestion($currentQuestion, $parsedData, $questionOrder);
                                $currentQuestion = null;
                                $currentComments = [];
                            }
                            if (in_array(trim($line), $reportCategories)) {
                                $currentReportCategory = $line;
                                $expectingData = 'question';
                                Log::debug("Set report category: $currentReportCategory");
                            }
                        }
                        break;
                }
            }

            // Handle end of section
            if (preg_match('/^Totals\s*(N\/A|\s*)$/i', $line)) {
                if ($currentQuestion) {
                    $currentQuestion['comments'] = implode(' ', $currentComments);
                    $this->finalizeQuestion($currentQuestion, $parsedData, $questionOrder);
                    $currentQuestion = null;
                    $currentComments = [];
                }
                $inQuestionTable = false;
                $currentSection = null;
                $currentReportCategory = null;
                $expectingData = null;
                Log::debug("End of section detected");
                continue;
            }
        }

        // Save any remaining incomplete question
        if ($currentQuestion) {
            $currentQuestion['comments'] = implode(' ', $currentComments);
            $this->finalizeQuestion($currentQuestion, $parsedData, $questionOrder);
        }

        // Validate restaurant ID
        if (empty($parsedData['restaurant']['restaurant_id'])) {
            throw new \Exception('Missing required field: restaurant_id');
        }

        return $parsedData;
    }

    protected function finalizeQuestion(&$question, &$parsedData, &$order)
    {
        // Clean and validate the question before saving
        $question['question'] = $this->cleanQuestionText($question['question']);

        if (strlen($question['question']) < 5) {
            Log::warning("Question too short after cleaning: '{$question['question']}'", ['question' => $question]);
        } elseif (preg_match('/^\d+$/', $question['question'])) {
            Log::warning("Question is only numbers: '{$question['question']}'", ['question' => $question]);
        } elseif (preg_match('/^[\d.]+%$/', $question['question'])) {
            Log::warning("Question is a percentage: '{$question['question']}'", ['question' => $question]);
        } else {
            $parsedData['questions'][] = $question;
            $order++;
            Log::debug("Saved question: " . json_encode($question));
            return;
        }

        Log::warning("Discarded invalid question: " . json_encode($question));
    }

    protected function parsePoints($line)
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

    protected function parsePercent($line)
    {
        $line = trim($line);
        if (preg_match('/^([\d.]+)%?$/', $line, $matches)) {
            return (float)$matches[1];
        } elseif (preg_match('/^(N\/A|Not Applicable|n\/a|N\\A)$/i', $line)) {
            return null;
        }
        return null;
    }

    protected function parseAnswer($line)
    {
        $line = trim($line);
        if (preg_match('/^(Yes|No)$/i', $line, $matches)) {
            return ucfirst(strtolower($matches[1]));
        } elseif (preg_match('/^(N\/A|Not Applicable|n\/a|N\\A)$/i', $line)) {
            return null;
        }
        return false;
    }

    protected function cleanQuestionText($text)
    {
        $text = str_replace(['Comments', 'Current to Total'], '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}

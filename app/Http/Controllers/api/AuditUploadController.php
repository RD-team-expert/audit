<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\AuditQuestion;
use App\Models\AuditSection;
use App\Models\Restaurant;
use App\Models\AuditReport;
use App\Services\RestaurantParseService;
use App\Services\AuditReportParseService;
use App\Services\AuditSectionParseService;
use App\Services\AuditQuestionParseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Spatie\PdfToText\Pdf;
use Carbon\Carbon;

class AuditUploadController extends Controller
{
    protected $restaurantParseService;
    protected $auditReportParseService;
    protected $auditSectionParseService;
    protected $auditQuestionParseService;

    public function __construct(
        RestaurantParseService $restaurantParseService,
        AuditReportParseService $auditReportParseService,
        AuditSectionParseService $auditSectionParseService,
        AuditQuestionParseService $auditQuestionParseService
    ) {
        $this->restaurantParseService = $restaurantParseService;
        $this->auditReportParseService = $auditReportParseService;
        $this->auditSectionParseService = $auditSectionParseService;
        $this->auditQuestionParseService = $auditQuestionParseService;
    }

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

            $lines = array_map('trim', explode("\n", $rawText));
            $parsedData = $this->parseData($lines);

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
                            'question' => $question['question'],
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

    protected function parseData(array $lines): array
    {
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

        $restaurantData = $this->restaurantParseService->parse($lines);
        $parsedData['restaurant'] = $restaurantData;

        $reportData = $this->auditReportParseService->parse($lines);
        $parsedData['form_name'] = $reportData['form_name'];
        $parsedData['form_type'] = $reportData['form_type'];
        $parsedData['start_date'] = $reportData['start_date'];
        $parsedData['end_date'] = $reportData['end_date'];
        $parsedData['upload_date'] = $reportData['upload_date'];
        $parsedData['auditor'] = $reportData['auditor'];
        $parsedData['overall_score'] = $reportData['overall_score'];

        $parsedData['sections'] = $this->auditSectionParseService->parse($lines);
        $parsedData['questions'] = $this->auditQuestionParseService->parse($lines, $parsedData['sections']);

        return $parsedData;
    }
}

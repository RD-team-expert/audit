<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AuditReportParseService
{
    protected $parseUtilityService;

    public function __construct(ParseUtilityService $parseUtilityService)
    {
        $this->parseUtilityService = $parseUtilityService;
    }

    public function parse(array $lines): array
    {
        $reportData = [
            'form_name' => 'Unknown Form',
            'form_type' => 'Unknown Type',
            'start_date' => now(),
            'end_date' => now(),
            'upload_date' => now(),
            'auditor' => 'Unknown Auditor',
            'overall_score' => 0.0,
        ];

        // Join lines into a single text block for robust parsing
        $text = implode("\n", array_map('trim', $lines));

        // Extract metadata using regex
        if (preg_match('/Form Name:\s*([^\n]+)/i', $text, $matches)) {
            $reportData['form_name'] = trim($matches[1]);
        }
        if (preg_match('/Type:\s*([^\n]+)/i', $text, $matches)) {
            $reportData['form_type'] = trim($matches[1]);
        }
        if (preg_match('/Start Date:\s*([^\n]+)/i', $text, $matches)) {
            try {
                $reportData['start_date'] = Carbon::parse(trim($matches[1]));
            } catch (\Exception $e) {
                Log::warning("Failed to parse start_date: {$matches[1]}", ['error' => $e->getMessage()]);
                $reportData['start_date'] = now();
            }
        }
        if (preg_match('/End Date:\s*([^\n]+)/i', $text, $matches)) {
            try {
                $reportData['end_date'] = Carbon::parse(trim($matches[1]));
            } catch (\Exception $e) {
                Log::warning("Failed to parse end_date: {$matches[1]}", ['error' => $e->getMessage()]);
                $reportData['end_date'] = now();
            }
        }
        if (preg_match('/Upload Date:\s*([^\n]+)/i', $text, $matches)) {
            try {
                $reportData['upload_date'] = Carbon::parse(trim($matches[1]));
            } catch (\Exception $e) {
                Log::warning("Failed to parse upload_date: {$matches[1]}", ['error' => $e->getMessage()]);
                $reportData['upload_date'] = now();
            }
        }
        if (preg_match('/Auditor:\s*([^\n]+)/i', $text, $matches)) {
            $reportData['auditor'] = trim($matches[1]);
        }
        if (preg_match('/Overall Score\s*([\d.]+%?)/i', $text, $matches)) {
            $reportData['overall_score'] = $this->parseUtilityService->parsePercent(trim($matches[1]));
        }

        Log::debug('Parsed audit report data: ' . json_encode($reportData));
        return $reportData;
    }
}

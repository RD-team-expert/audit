<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Models\AuditReport;
use App\Models\AuditSection;
use App\Models\AuditQuestion;
use Spatie\PdfToText\Pdf;
use Carbon\Carbon;

class AuditUploadAIController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:pdf|max:10000',
        ]);

        $pdfFile = $request->file('file');
        $path = $pdfFile->store('pdfs');
        $pdfText = Pdf::getText(Storage::path($path));

        // Step 1: Send to ChatGPT API
        $json = $this->extractStructuredDataWithAI($pdfText);
        if (!$json || !isset($json['report'])) {
            return response()->json(['error' => 'AI did not return valid structure'], 500);
        }

        // Step 2: Insert to DB
        $report = AuditReport::create($json['report']);

        foreach ($json['sections'] ?? [] as $section) {
            $section['audit_report_id'] = $report->id;
            AuditSection::create($section);
        }

        foreach ($json['questions'] ?? [] as $question) {
            $question['audit_report_id'] = $report->id;
            AuditQuestion::create($question);
        }

        Storage::delete($path);

        return response()->json(['message' => '✅ File processed and saved via AI']);
    }

    private function extractStructuredDataWithAI($text)
    {
        $prompt = <<<EOT
You are a smart audit parser. Analyze the following restaurant audit report text and extract 3 structured arrays:

1. **report** (basic info): {
  restaurant_name, address, city, state, zip, phone, contact_name, contact_email,
  form_name, form_type, start_date, end_date, upload_date (today), auditor, overall_score
}

2. **sections** (category summaries): array of {
  section_type, category, points, total_points, score
}

3. **questions** (each question): array of {
  report_category, question, answer, points_current, points_total, percent, comments
}

Respond ONLY with valid JSON:
{
  "report": { ... },
  "sections": [ ... ],
  "questions": [ ... ]
}

TEXT:
$text
EOT;

        $response = Http::timeout(120)->withHeaders([
            'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => 'gpt-4',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful AI that extracts structured audit data.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.2,
        ]);

        if ($response->failed()) {
            return null;
        }

        $content = $response->json()['choices'][0]['message']['content'] ?? null;
        if (!$content) return null;

        return json_decode($content, true);
    }
}

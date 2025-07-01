<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\Models\AuditReport;
use Illuminate\Http\Request;

class AuditDataController extends Controller
{
    public function index()
    {
        $audits = AuditReport::with('restaurant', 'sections.questions')
            ->orderBy('created_at', 'desc')
            ->get();

        return view('audit_test', [
            'audits' => $audits
        ]);
    }

    public function show($id)
    {
        $audit = AuditReport::with('restaurant', 'sections.questions')
            ->findOrFail($id);

        $failedQuestions = $audit->sections->flatMap(function ($section) {
            return $section->questions->filter(function ($question) {
                return is_null($question->percent) || $question->percent < 100;
            });
        });

        return response()->json([
            'audit' => $audit,
            'sections' => $audit->sections,
            'failed_questions' => $failedQuestions,
        ]);
    }
}

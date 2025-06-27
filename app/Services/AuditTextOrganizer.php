<?php

namespace App\Services;

class AuditTextOrganizer
{
    public function organize(string $rawText): string
    {
        try {
            // Step 1: Normalize whitespace and newlines
            $text = preg_replace('/\r\n|\r|\n/', "\n", $rawText);
            $text = preg_replace('/\n{2,}/', "\n", $text); // Collapse empty lines
            $text = preg_replace('/[ \t]+/', ' ', $text);  // Collapse spaces/tabs

            // Step 2: Fix broken metadata lines
            $fieldsToFix = [
                'Restaurant ID', 'Form Name', 'Restaurant', 'Type',
                'Address', 'Start Date', 'City/State/Zip',
                'End Date', 'Phone', 'Upload Date',
                'Auditor', 'Contact Name', 'Contact Email'
            ];

            foreach ($fieldsToFix as $field) {
                // Escape special characters in the field name for regex
                $escapedField = preg_quote($field, '/');
                $text = preg_replace_callback(
                    "/$escapedField:\s*\n\s*(.+?)(?:\n|$)/",
                    fn($m) => "$field: {$m[1]}\n",
                    $text
                );
            }

            // Step 3: Remove page breaks / strange characters
            $text = preg_replace('/[\x0C]/', '', $text); // Form feed
            $text = preg_replace('/\x{00a0}/u', ' ', $text); // Non-breaking space

            return trim($text);
        } catch (\Exception $e) {
            \Log::error('Error organizing audit text: ' . $e->getMessage());
            return trim($rawText); // Fallback to trimmed raw text
        }
    }
}

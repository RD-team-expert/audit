<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class RestaurantParseService
{
    public function parse(array $lines): array
    {
        $restaurantData = [
            'restaurant_id' => null,
            'name' => 'Unknown Restaurant',
            'address' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
            'phone' => null,
            'contact_name' => null,
            'contact_email' => null,
        ];

        // Join lines into a single text block for robust parsing
        $text = implode("\n", array_map('trim', $lines));

        // Extract metadata using regex
        if (preg_match('/Restaurant ID:\s*([^\n]+)/i', $text, $matches)) {
            $restaurantData['restaurant_id'] = trim($matches[1]);
        }
        if (preg_match('/Restaurant:\s*([^\n]+)/i', $text, $matches)) {
            $restaurantData['name'] = trim($matches[1]);
        }
        if (preg_match('/Address:\s*([^\n]+)/i', $text, $matches)) {
            $restaurantData['address'] = trim($matches[1]);
        }
        if (preg_match('/City\/State\/Zip:\s*([^,]+),\s*([A-Z]{2})\s*(\d{5})/i', $text, $matches)) {
            $restaurantData['city'] = trim($matches[1]);
            $restaurantData['state'] = $matches[2];
            $restaurantData['zip'] = $matches[3];
        }
        if (preg_match('/Phone:\s*([^\n]+)/i', $text, $matches)) {
            $restaurantData['phone'] = trim($matches[1]);
        }
        if (preg_match('/Contact Name:\s*([^\n]+)/i', $text, $matches)) {
            $restaurantData['contact_name'] = trim($matches[1]);
        }
        if (preg_match('/Contact Email:\s*([^\n]+)/i', $text, $matches)) {
            $restaurantData['contact_email'] = trim($matches[1]);
        }

        // Validate required field
        if (empty($restaurantData['restaurant_id'])) {
            Log::error('Missing restaurant_id in parsed data', ['restaurant' => $restaurantData]);
            throw new \Exception('Parsing failed: Missing required field: restaurant_id');
        }

        Log::debug('Parsed restaurant data: ' . json_encode($restaurantData));
        return $restaurantData;
    }
}

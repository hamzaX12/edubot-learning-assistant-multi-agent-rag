<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * PDF text extraction using LlamaParse API.
 *
 * Provides the function local_edubot_extract_text_from_pdf() used by the
 * RAG engine to convert course PDF files into Markdown text.
 *
 * LlamaParse API Key is read from Moodle admin settings:
 *   - local_edubot/llamaparse_api_key
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// ============================================================================
// LLAMAPARSE EXTRACTION CLASS
// ============================================================================

class LlamaParseExtractor {
    private string $apiKey;

    public function __construct(string $apiKey) {
        $this->apiKey = $apiKey;
    }

    public function extract(string $pdfPath): string {
        if (!file_exists($pdfPath)) {
            return '';
        }

        echo "[LlamaParse] Step 1: Uploading PDF to LlamaParse...\n";

        $ch = curl_init('https://api.cloud.llamaindex.ai/api/parsing/upload');
        
        $cfile = new CURLFile($pdfPath, 'application/pdf', basename($pdfPath));
        $data = ['file' => $cfile];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json'
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        echo "[LlamaParse] Step 1 result: HTTP $httpCode" . ($curlErr ? " cURL error: $curlErr" : "") . "\n";

        if ($httpCode !== 200) {
            echo "[LlamaParse] FAILED: Upload. Response: " . substr($response, 0, 300) . "\n";
            return '';
        }

        $result = json_decode($response, true);
        $jobId = $result['id'] ?? null;

        if (!$jobId) {
            echo "[LlamaParse] FAILED: No job ID returned.\n";
            return '';
        }

        echo "[LlamaParse] Got Job ID: $jobId\n";
        echo "[LlamaParse] Step 2: Polling for results...\n";

        $maxRetries = 40;
        $retryCount = 0;

        while ($retryCount < $maxRetries) {
            $ch = curl_init("https://api.cloud.llamaindex.ai/api/parsing/job/{$jobId}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->apiKey,
                    'Accept: application/json'
                ],
            ]);

            $statusResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                echo "[LlamaParse] FAILED: Poll HTTP $httpCode\n";
                return '';
            }

            $statusData = json_decode($statusResponse, true);
            $jobStatus = $statusData['status'] ?? null;

            echo "[LlamaParse] Poll $retryCount: status=$jobStatus\n";

            if ($jobStatus === 'SUCCESS') {
                echo "[LlamaParse] Step 3: Downloading JSON result...\n";
                
                $ch = curl_init("https://api.cloud.llamaindex.ai/api/parsing/job/{$jobId}/result/json");
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 60,
                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer ' . $this->apiKey,
                        'Accept: application/json'
                    ],
                ]);

                $jsonResponse = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200) {
                    echo "[LlamaParse] FAILED: Download HTTP $httpCode\n";
                    return '';
                }

                $pagesData = json_decode($jsonResponse, true);
                
                if (!is_array($pagesData)) {
                    echo "[LlamaParse] FAILED: Invalid JSON structure returned.\n";
                    return '';
                }

                $markdownText = '';
                // LlamaParse /result/json returns an array of page objects.
                // Let's recursively find pages if it's nested.
                if (isset($pagesData[0]['pages'])) {
                    $pagesData = $pagesData[0]['pages']; // Sometimes it's nested in a list of documents.
                } elseif (isset($pagesData['pages'])) {
                    $pagesData = $pagesData['pages']; // Direct object.
                }

                $counter = 1;
                foreach ($pagesData as $key => $pageObj) {
                    if (!is_array($pageObj)) {
                        continue; // Skip if it's not a valid page object.
                    }
                    $pageNum = $pageObj['page'] ?? $counter;
                    $pageMd = $pageObj['md'] ?? $pageObj['text'] ?? '';
                    if (!empty(trim($pageMd))) {
                        $markdownText .= "\n\n[PAGE:{$pageNum}]\n\n" . $pageMd;
                    }
                    $counter++;
                }

                echo "[LlamaParse] Downloaded " . strlen($markdownText) . " characters with page markers.\n";
                
                if (empty(trim($markdownText))) {
                    echo "[LlamaParse] DEBUG JSON Keys: " . json_encode(array_keys($pagesData)) . "\n";
                    if (isset($pagesData[0])) {
                        echo "[LlamaParse] DEBUG Item 0 Keys: " . json_encode(array_keys($pagesData[0])) . "\n";
                    }
                    return '';
                }
                return $markdownText;

            } elseif ($jobStatus === 'ERROR' || $jobStatus === 'FAILED') {
                echo "[LlamaParse] FAILED: Job returned error status.\n";
                return '';
            }

            $retryCount++;
            sleep(3);
        }

        echo "[LlamaParse] FAILED: Timeout after $maxRetries polls.\n";
        return '';
    }
}

// ============================================================================
// CALLABLE WRAPPER FUNCTION
// ============================================================================

/**
 * Extract plain text from a PDF file using LlamaParse API.
 *
 * This is the main entry point called by the RAG engine.
 * Reads credentials from Moodle admin settings.
 *
 * @param string $filepath Absolute server path to the PDF file.
 * @return string Extracted plain text.
 */
function local_edubot_extract_text_from_pdf(string $filepath): string {
    $apiKey = get_config('local_edubot', 'llamaparse_api_key');

    if (empty($apiKey)) {
        debugging('EduBot: LlamaParse API credentials not configured.', DEBUG_DEVELOPER);
        return '';
    }

    // Try extraction — capture output for debugging.
    ob_start();
    try {
        $extractor = new LlamaParseExtractor($apiKey);
        $result = $extractor->extract($filepath);
    } catch (\Exception $e) {
        $result = '';
        debugging('EduBot LlamaParse extraction exception: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
    $output = ob_get_clean();

    // Ensure the output is printed directly so that force_index.php streams it to the browser.
    if (!empty($output)) {
        echo $output;
        debugging('EduBot LlamaParse output: ' . substr($output, 0, 500), DEBUG_DEVELOPER);
    }

    if (!empty(trim($result))) {
        debugging('EduBot: LlamaParse extraction succeeded (' . strlen($result) . ' chars).', DEBUG_DEVELOPER);
        return $result;
    }

    debugging('EduBot: LlamaParse extraction returned empty.', DEBUG_DEVELOPER);
    return '';
}

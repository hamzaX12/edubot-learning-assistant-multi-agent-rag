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
 * RAG engine for EduBot — semantic retrieval using Gemini embeddings.
 *
 * Implements a complete Retrieval-Augmented Generation pipeline:
 * 1. Extract text from course PDFs (via Adobe API)
 * 2. Split into overlapping chunks
 * 3. Generate vector embeddings via Gemini text-embedding-004
 * 4. Store chunks + embeddings in MySQL
 * 5. Retrieve relevant chunks via cosine similarity
 * 6. Format context for LLM prompt injection
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edubot;

defined('MOODLE_INTERNAL') || die();

/**
 * Semantic RAG engine using Gemini embeddings with keyword fallback.
 */
class rag_engine {

    /** @var string Gemini Embedding API endpoint (gemini-embedding-001 replaced deprecated text-embedding-004). */
    private const EMBEDDING_API = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent';

    /** @var int Target chunk size in characters. */
    private const CHUNK_SIZE = 500;

    /** @var int Overlap between consecutive chunks in characters. */
    private const CHUNK_OVERLAP = 100;

    /** @var int Minimum chunk size — smaller chunks are merged with previous. */
    private const MIN_CHUNK_SIZE = 100;

    /** @var float Minimum cosine similarity score to consider a chunk relevant. */
    private const SIMILARITY_THRESHOLD = 0.5;

    /** @var array Stop words filtered out during keyword fallback search. */
    private const STOP_WORDS = [
        'the', 'a', 'an', 'is', 'are', 'was', 'were', 'in', 'on', 'at',
        'to', 'for', 'of', 'and', 'or', 'but', 'with', 'this', 'that', 'it',
    ];

    /** @var string|null Cached API key. */
    private $apikey = null;

    /**
     * Get the Gemini API key from Moodle config.
     *
     * @return string The API key.
     * @throws \moodle_exception If no API key is configured.
     */
    private function get_api_key(): string {
        if ($this->apikey === null) {
            $this->apikey = get_config('local_edubot', 'gemini_api_key');
            if (empty($this->apikey)) {
                throw new \moodle_exception('error_no_api_key', 'local_edubot');
            }
        }
        return $this->apikey;
    }

    // =========================================================================
    // METHOD 1 — index_course_pdfs
    // =========================================================================

    /**
     * Index all PDF files in a Moodle course for RAG retrieval.
     *
     * Finds PDFs → extracts text → chunks → embeds → stores in DB.
     * Writes progress to Moodle cache for real-time frontend polling.
     *
     * @param int $courseid The Moodle course ID.
     * @return array Summary: files_processed, chunks_created, errors.
     */
    public function index_course_pdfs(int $courseid, int $cmid = null): array {
        global $DB, $CFG;

        set_time_limit(300);

        $errors = [];
        $totalchunks = 0;
        $filesprocessed = 0;

        // Step 1 — Find PDF files in the course.
        $sql = "SELECT f.id, f.filename, f.component, f.filearea, f.contextid,
                       f.contenthash, f.pathnamehash, cm.id AS cmid
                  FROM {files} f
                  JOIN {context} ctx ON ctx.id = f.contextid
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = 70
                 WHERE cm.course = :courseid
                   AND f.component = 'mod_resource'
                   AND f.mimetype = 'application/pdf'
                   AND f.filename <> '.'";

        $params = ['courseid' => $courseid];
        if ($cmid !== null) {
            $sql .= " AND cm.id = :cmid";
            $params['cmid'] = $cmid;
        }

        $pdffiles = $DB->get_records_sql($sql, $params);

        if (empty($pdffiles)) {
            $this->update_progress($courseid, 0, 0, 0, 'No PDF files found in this course.');
            return ['files_processed' => 0, 'chunks_created' => 0, 'errors' => ['No PDF files found.']];
        }

        $totalfiles = count($pdffiles);
        $this->update_progress($courseid, 0, $totalfiles, 0, 'Starting indexing...');

        // Step 6 — Deletion is now handled by the IndexingAgent before calling this function.

        // Load the PDF extraction function.
        require_once($CFG->dirroot . '/local/edubot/extract_txt_pdf.php');

        $fs = get_file_storage();
        $allchunks = [];
        $fileindex = 0;

        foreach ($pdffiles as $pdfrecord) {
            // Reset the PHP timeout so it doesn't die on large courses.
            \core_php_time_limit::raise(300);

            $fileindex++;
            $filename = $pdfrecord->filename;

            $this->update_progress($courseid, $fileindex, $totalfiles, $totalchunks,
                "Processing: {$filename}");

            try {
                // Get the stored_file object.
                $file = $fs->get_file_by_hash($pdfrecord->pathnamehash);
                if (!$file) {
                    $errors[] = "Could not retrieve file: {$filename}";
                    continue;
                }

                // Step 2 — Extract text: copy to temp file for Adobe API.
                $tmppath = $CFG->tempdir . '/edubot_' . $pdfrecord->contenthash . '.pdf';
                $file->copy_content_to($tmppath);

                $rawtext = local_edubot_extract_text_from_pdf($tmppath);

                // Clean up temp file.
                if (file_exists($tmppath)) {
                    unlink($tmppath);
                }

                if (empty($rawtext) || strlen($rawtext) < 50) {
                    $errors[] = "Skipped {$filename}: extracted text too short (" . strlen($rawtext) . " chars).";
                    debugging("EduBot RAG: Skipped {$filename} — text too short.", DEBUG_DEVELOPER);
                    continue;
                }

                // Step 3 — Clean the extracted text.
                $cleantext = $this->clean_text($rawtext);

                // Step 4 — Split into chunks (returns [{text, page}, ...]).
                $chunks = $this->split_into_chunks($cleantext);

                if (empty($chunks)) {
                    $errors[] = "No valid chunks from {$filename}.";
                    continue;
                }

                // Step 5 — Generate embeddings for each chunk.
                foreach ($chunks as $idx => $chunkdata) {
                    $chunktext = $chunkdata['text'];
                    $pagenum   = $chunkdata['page'] ?? 1;

                    $embedding = $this->generate_embedding($chunktext, 'RETRIEVAL_DOCUMENT');

                    if ($embedding === null) {
                        $errors[] = "Embedding failed for {$filename} chunk {$idx}.";
                        continue;
                    }

                    $allchunks[] = (object) [
                        'courseid'      => $courseid,
                        'resource_id'   => $pdfrecord->cmid,
                        'resource_name' => $filename,
                        'chunk_text'    => $chunktext,
                        'chunk_index'   => $idx,
                        'page_number'   => $pagenum,
                        'embedding'     => json_encode($embedding),
                        'created_at'    => time(),
                    ];

                    $totalchunks++;
                    $this->update_progress($courseid, $fileindex, $totalfiles, $totalchunks,
                        "Embedding: {$filename} (chunk " . ($idx + 1) . "/" . count($chunks) . ")");

                    // Rate limiting: 0.5s between API calls.
                    usleep(500000);
                }

                $filesprocessed++;

            } catch (\Exception $e) {
                $errors[] = "Error processing {$filename}: " . $e->getMessage();
                debugging("EduBot RAG error on {$filename}: " . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Step 7 — Bulk insert inside a transaction.
        if (!empty($allchunks)) {
            $transaction = $DB->start_delegated_transaction();
            try {
                foreach ($allchunks as $record) {
                    $DB->insert_record('local_edubot_rag_chunks', $record);
                }
                $transaction->allow_commit();
            } catch (\Exception $e) {
                $transaction->rollback($e);
                $errors[] = 'Database insert failed: ' . $e->getMessage();
                $totalchunks = 0;
            }
        }

        // Step 8 — Final progress + return summary.
        $status = empty($errors) ? 'Complete' : 'Completed with errors';
        $this->update_progress($courseid, $totalfiles, $totalfiles, $totalchunks, $status, true);

        return [
            'files_processed' => $filesprocessed,
            'chunks_created'  => $totalchunks,
            'errors'          => $errors,
        ];
    }

    // =========================================================================
    // METHOD 2 — get_query_embedding
    // =========================================================================

    /**
     * Convert a student question into a vector embedding.
     *
     * @param string $querytext The student's question.
     * @return array|null Array of 768 floats, or null on failure.
     */
    public function get_query_embedding(string $querytext): ?array {
        return $this->generate_embedding($querytext, 'RETRIEVAL_QUERY');
    }

    // =========================================================================
    // METHOD 3 — cosine_similarity
    // =========================================================================

    /**
     * Calculate cosine similarity between two vectors.
     *
     * @param array $vectora First vector (array of floats).
     * @param array $vectorb Second vector (array of floats).
     * @return float Similarity score between -1 and 1.
     */
    public function cosine_similarity(array $vectora, array $vectorb): float {
        if (empty($vectora) || empty($vectorb)) {
            return 0.0;
        }
        if (count($vectora) !== count($vectorb)) {
            return 0.0;
        }

        $dot = 0.0;
        $magna = 0.0;
        $magnb = 0.0;

        for ($i = 0, $len = count($vectora); $i < $len; $i++) {
            $a = (float) $vectora[$i];
            $b = (float) $vectorb[$i];
            $dot   += $a * $b;
            $magna += $a * $a;
            $magnb += $b * $b;
        }

        $magna = sqrt($magna);
        $magnb = sqrt($magnb);

        if ($magna == 0.0 || $magnb == 0.0) {
            return 0.0;
        }

        return $dot / ($magna * $magnb);
    }

    // =========================================================================
    // METHOD 4 — retrieve_relevant_chunks
    // =========================================================================

    /**
     * Find the most relevant chunks for a student question using semantic search.
     *
     * Falls back to keyword search if embedding API fails or no chunks score
     * above the similarity threshold.
     *
     * @param string $querytext The student's question.
     * @param int $courseid The Moodle course ID.
     * @param int $maxchunks Maximum number of chunks to return.
     * @return array Array of chunk results, or ['no_index' => true] if not indexed.
     */
    public function retrieve_relevant_chunks(string $querytext, int $courseid, int $maxchunks = 3): array {
        global $DB;

        // Step 1 — Check if chunks exist.
        $count = $DB->count_records('local_edubot_rag_chunks', ['courseid' => $courseid]);
        if ($count == 0) {
            return ['no_index' => true];
        }

        // Step 2 — Get query embedding.
        $queryembedding = $this->get_query_embedding($querytext);

        // Step 3 — Load all chunks.
        $chunks = $DB->get_records('local_edubot_rag_chunks', ['courseid' => $courseid],
            '', 'id, chunk_text, resource_name, chunk_index, page_number, embedding');

        // Step 4 — Semantic search (if embedding succeeded).
        if ($queryembedding !== null) {
            $scored = [];
            foreach ($chunks as $chunk) {
                $chunkemb = json_decode($chunk->embedding, true);
                if (empty($chunkemb)) {
                    continue;
                }
                $score = $this->cosine_similarity($queryembedding, $chunkemb);
                if ($score >= self::SIMILARITY_THRESHOLD) {
                    $scored[] = [
                        'text'             => $chunk->chunk_text,
                        'source'           => $chunk->resource_name,
                        'chunk_index'      => (int) $chunk->chunk_index,
                        'page_number'      => (int) ($chunk->page_number ?? 1),
                        'similarity_score' => round($score, 4),
                        'search_method'    => 'semantic',
                    ];
                }
            }

            if (!empty($scored)) {
                usort($scored, fn($a, $b) => $b['similarity_score'] <=> $a['similarity_score']);
                return array_slice($scored, 0, $maxchunks);
            }
        }

        // Step 5 — Fallback: keyword search.
        return $this->keyword_search($querytext, $chunks, $maxchunks);
    }

    // =========================================================================
    // METHOD 5 — format_rag_context
    // =========================================================================

    /**
     * Format retrieved chunks into a string for the Gemini system prompt.
     *
     * @param array $chunks Retrieved chunks from retrieve_relevant_chunks().
     * @return string Formatted context string for prompt injection.
     */
    public function format_rag_context(array $chunks): string {
        if (empty($chunks) || isset($chunks['no_index'])) {
            return "No course materials have been indexed for this course yet. "
                 . "Answer from your general Computer Science knowledge and clearly tell the student: "
                 . "This answer is based on general knowledge, not your specific course materials.";
        }

        $output = "The following excerpts are from the official course materials. "
                . "Base your answer on this content. Do not contradict it. "
                . "Cite the source document when you reference it directly.\n\n";

        $scores = [];
        foreach ($chunks as $chunk) {
            $source = $chunk['source'] ?? 'unknown';
            $page   = $chunk['page_number'] ?? 1;
            $text   = $chunk['text'] ?? '';
            $output .= "[Source: {$source} — page {$page}]\n{$text}\n\n";
            $scores[] = $chunk['similarity_score'] ?? 0;
        }

        $method = $chunks[0]['search_method'] ?? 'unknown';
        $scorestr = implode(', ', $scores);
        $output .= "Search method used: {$method} (score: {$scorestr})";

        return $output;
    }

    // =========================================================================
    // METHOD 6 — get_indexing_status
    // =========================================================================

    /**
     * Get the current RAG indexing status for a course.
     *
     * @param int $courseid The Moodle course ID.
     * @return array Status info: total_chunks, total_files, file_names, last_indexed, is_indexed.
     */
    public function get_indexing_status(int $courseid): array {
        global $DB;

        $totalchunks = $DB->count_records('local_edubot_rag_chunks', ['courseid' => $courseid]);

        if ($totalchunks == 0) {
            return [
                'total_chunks' => 0,
                'total_files'  => 0,
                'file_names'   => [],
                'last_indexed' => null,
                'is_indexed'   => false,
            ];
        }

        $sql = "SELECT DISTINCT resource_name FROM {local_edubot_rag_chunks} WHERE courseid = :courseid";
        $files = $DB->get_records_sql($sql, ['courseid' => $courseid]);
        $filenames = array_column($files, 'resource_name');

        $sql = "SELECT MAX(created_at) AS last_ts FROM {local_edubot_rag_chunks} WHERE courseid = :courseid";
        $row = $DB->get_record_sql($sql, ['courseid' => $courseid]);

        return [
            'total_chunks' => (int) $totalchunks,
            'total_files'  => count($filenames),
            'file_names'   => $filenames,
            'last_indexed' => $row ? (int) $row->last_ts : null,
            'is_indexed'   => true,
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Call the Gemini Embedding API to generate a vector for text.
     *
     * Retries once after 2 seconds on failure.
     *
     * @param string $text The text to embed.
     * @param string $tasktype RETRIEVAL_DOCUMENT or RETRIEVAL_QUERY.
     * @return array|null Array of 768 floats, or null on failure.
     */
    private function generate_embedding(string $text, string $tasktype): ?array {
        global $CFG;

        require_once($CFG->libdir . '/filelib.php');

        $apikey = $this->get_api_key();
        $url = self::EMBEDDING_API . '?key=' . $apikey;

        $body = json_encode([
            'model'               => 'models/gemini-embedding-001',
            'content'             => ['parts' => [['text' => $text]]],
            'taskType'            => $tasktype,
            'outputDimensionality' => 768,
        ]);

        // Attempt with 1 retry.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($attempt > 0) {
                sleep(2);
            }

            $curl = new \curl();
            $curl->setHeader(['Content-Type: application/json']);

            $response = $curl->post($url, $body, [
                'CURLOPT_RETURNTRANSFER' => true,
                'CURLOPT_TIMEOUT'        => 30,
            ]);

            $info = $curl->get_info();
            $httpcode = $info['http_code'] ?? 0;
            $curlerr  = $curl->error;

            if (!empty($curlerr)) {
                debugging("EduBot embedding cURL error (attempt $attempt): $curlerr", DEBUG_DEVELOPER);
                continue;
            }

            if ($httpcode !== 200) {
                debugging("EduBot embedding API HTTP $httpcode (attempt $attempt): "
                    . substr($response, 0, 300), DEBUG_DEVELOPER);
                continue;
            }

            $decoded = json_decode($response, true);
            $values = $decoded['embedding']['values'] ?? null;

            if (is_array($values) && count($values) > 0) {
                return $values;
            }

            debugging("EduBot embedding: no values in response (attempt $attempt).", DEBUG_DEVELOPER);
        }

        return null;
    }

    /**
     * Clean extracted PDF text.
     *
     * @param string $text Raw extracted text.
     * @return string Cleaned text.
     */
    private function clean_text(string $text): string {
        // Remove non-printable characters except newlines and tabs.
        $text = preg_replace('/[^\x20-\x7E\n\t]/', '', $text);

        // Remove JSON objects that leaked from PDF structure.
        $text = preg_replace('/\{[^{}]*"[^{}]*"[^{}]*\}/', '', $text);
        // Remove JSON key-value pairs: "key": "value".
        $text = preg_replace('/"[a-zA-Z_]+"\s*:\s*("[^"]*"|true|false|null|\d+(\.\d+)?)/', '', $text);
        // Remove JSON arrays.
        $text = preg_replace('/\[\s*\{.*?\}\s*\]/s', '', $text);

        // Remove PDF internal references.
        $text = preg_replace('/\d+\s+\d+\s+(R|obj)\b/', '', $text);
        $text = preg_replace('/<[0-9A-Fa-f]{4,}>/', '', $text);
        $text = preg_replace('/\/(F\d+|Font|BaseFont|Encoding|Type|Subtype)\b/', '', $text);

        // Filter out lines that are mostly non-alphabetic (PDF structure noise).
        // Preserve [PAGE:N] markers inserted during extraction.
        $lines = explode("\n", $text);
        $clean = [];
        foreach ($lines as $line) {
            $line = trim($line);
            // Always keep page markers.
            if (preg_match('/^\[PAGE:\d+\]$/', $line)) {
                $clean[] = $line;
                continue;
            }
            if (strlen($line) < 3) {
                continue;
            }
            $alpha = preg_match_all('/[a-zA-Z]/', $line);
            if (strlen($line) > 0 && ($alpha / strlen($line)) < 0.35) {
                continue;
            }
            $clean[] = $line;
        }
        $text = implode("\n", $clean);

        // Collapse multiple spaces and excessive newlines.
        $text = preg_replace('/ {2,}/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * Split text into overlapping chunks respecting word boundaries.
     * Tracks page numbers from [PAGE:N] markers.
     *
     * @param string $text Cleaned text with [PAGE:N] markers.
     * @return array Array of ['text' => string, 'page' => int].
     */
    private function split_into_chunks(string $text): array {
        // Extract page markers and build a map of character positions to page numbers.
        $currentpage = 1;
        $pagemap = []; // [position => page_number]

        // Find all [PAGE:N] markers and record their positions.
        preg_match_all('/\[PAGE:(\d+)\]/', $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $i => $match) {
            $pos = $match[1];
            $pagenum = (int) $matches[1][$i][0];
            $pagemap[$pos] = $pagenum;
        }

        // Remove page markers from text (they shouldn't appear in chunks).
        $cleantext = preg_replace('/\[PAGE:\d+\]\s*/', '', $text);
        $cleantext = trim($cleantext);

        // Build a mapping: for each character position in clean text, what page is it on?
        // We do this by tracking offset shifts from removed markers.
        $pagebreaks = []; // [clean_position => page_number]
        $offset = 0;
        foreach ($matches[0] as $i => $match) {
            $origpos = $match[1];
            $markerlen = strlen($match[0]);
            // Account for whitespace after marker.
            $aftermarker = substr($text, $origpos + $markerlen);
            $trimmed = ltrim($aftermarker);
            $wslen = strlen($aftermarker) - strlen($trimmed);
            $cleanpos = $origpos - $offset;
            $pagebreaks[$cleanpos] = (int) $matches[1][$i][0];
            $offset += $markerlen + $wslen;
        }

        // Now split the clean text into chunks.
        $chunks = [];
        $len = strlen($cleantext);
        $start = 0;

        while ($start < $len) {
            $end = $start + self::CHUNK_SIZE;

            if ($end >= $len) {
                $chunk = substr($cleantext, $start);
            } else {
                $spacepos = strrpos(substr($cleantext, $start, self::CHUNK_SIZE), ' ');
                if ($spacepos !== false) {
                    $end = $start + $spacepos;
                }
                $chunk = substr($cleantext, $start, $end - $start);
            }

            $chunk = trim($chunk);

            // Determine the page number for this chunk's start position.
            $chunkpage = 1;
            foreach ($pagebreaks as $pos => $pn) {
                if ($pos <= $start) {
                    $chunkpage = $pn;
                } else {
                    break;
                }
            }

            if (!empty($chunk)) {
                if (strlen($chunk) < self::MIN_CHUNK_SIZE && !empty($chunks)) {
                    $chunks[count($chunks) - 1]['text'] .= ' ' . $chunk;
                } else {
                    $chunks[] = ['text' => $chunk, 'page' => $chunkpage];
                }
            }

            $step = ($end - $start) - self::CHUNK_OVERLAP;
            if ($step < 1) {
                $step = 1;
            }
            $start += $step;

            if ($end >= $len) {
                break;
            }
        }

        return $chunks;
    }

    /**
     * Keyword-based fallback search.
     *
     * @param string $query The student's question.
     * @param array $chunks DB records with chunk_text.
     * @param int $maxchunks Max results.
     * @return array Formatted chunk results.
     */
    private function keyword_search(string $query, array $chunks, int $maxchunks): array {
        $words = preg_split('/\W+/', strtolower($query));
        $keywords = [];
        foreach ($words as $w) {
            $w = trim($w);
            if (strlen($w) > 2 && !in_array($w, self::STOP_WORDS)) {
                $keywords[] = $w;
            }
        }

        if (empty($keywords)) {
            return [];
        }

        $scored = [];
        foreach ($chunks as $chunk) {
            $lower = strtolower($chunk->chunk_text);
            $score = 0;
            foreach ($keywords as $kw) {
                $score += substr_count($lower, $kw);
            }
            if ($score > 0) {
                $scored[] = [
                    'text'             => $chunk->chunk_text,
                    'source'           => $chunk->resource_name,
                    'chunk_index'      => (int) $chunk->chunk_index,
                    'page_number'      => (int) ($chunk->page_number ?? 1),
                    'similarity_score' => round($score / 100, 4),
                    'search_method'    => 'keyword',
                ];
            }
        }

        usort($scored, fn($a, $b) => $b['similarity_score'] <=> $a['similarity_score']);
        return array_slice($scored, 0, $maxchunks);
    }

    /**
     * Write indexing progress to Moodle cache for frontend polling.
     *
     * @param int $courseid Course being indexed.
     * @param int $currentfile Current file number.
     * @param int $totalfiles Total files to process.
     * @param int $chunkcount Chunks created so far.
     * @param string $status Human-readable status message.
     * @param bool $done Whether indexing is complete.
     */
    private function update_progress(int $courseid, int $currentfile, int $totalfiles,
                                     int $chunkcount, string $status, bool $done = false): void {
        $cache = \cache::make('local_edubot', 'rag_progress');
        $cache->set('course_' . $courseid, [
            'current_file' => $currentfile,
            'total_files'  => $totalfiles,
            'chunks'       => $chunkcount,
            'status'       => $status,
            'done'         => $done,
            'timestamp'    => time(),
        ]);
    }
}

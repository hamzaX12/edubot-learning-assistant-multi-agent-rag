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
 * AJAX endpoint: re-index course PDFs for RAG.
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

require_login();
require_sesskey();
require_capability('moodle/site:config', context_system::instance());

header('Content-Type: application/json');

try {
    $courseid = required_param('courseid', PARAM_INT);

    if ($courseid <= 1) {
        echo json_encode(['success' => false, 'error' => 'Invalid course ID.']);
        die();
    }

    // Verify course exists.
    $course = $DB->get_record('course', ['id' => $courseid]);
    if (!$course) {
        echo json_encode(['success' => false, 'error' => 'Course not found.']);
        die();
    }

    $rag = new \local_edubot\rag_engine();
    $result = $rag->index_course_pdfs($courseid);

    echo json_encode([
        'success'         => true,
        'files_processed' => $result['files_processed'],
        'chunks_created'  => $result['chunks_created'],
        'errors'          => $result['errors'],
    ]);

} catch (\Exception $e) {
    debugging('EduBot reindex error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    echo json_encode([
        'success' => false,
        'error'   => 'Indexing failed: ' . $e->getMessage(),
    ]);
}

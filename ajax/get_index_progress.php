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
 * AJAX endpoint: poll RAG indexing progress.
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

header('Content-Type: application/json');

$courseid = required_param('courseid', PARAM_INT);

$cache = cache::make('local_edubot', 'rag_progress');
$progress = $cache->get('course_' . $courseid);

if ($progress === false) {
    echo json_encode([
        'in_progress'  => false,
        'current_file' => 0,
        'total_files'  => 0,
        'chunks'       => 0,
        'status'       => 'No indexing in progress.',
        'done'         => false,
    ]);
} else {
    echo json_encode([
        'in_progress'  => !$progress['done'],
        'current_file' => $progress['current_file'],
        'total_files'  => $progress['total_files'],
        'chunks'       => $progress['chunks'],
        'status'       => $progress['status'],
        'done'         => $progress['done'],
    ]);
}

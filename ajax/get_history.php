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
 * AJAX endpoint: get conversation history.
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

require_login();
require_sesskey();

$conversationid = optional_param('conversation_id', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

// If loading a specific conversation.
if ($conversationid > 0) {
    // Verify ownership.
    $conversation = $DB->get_record('local_edubot_conversations', [
        'id' => $conversationid,
        'userid' => $USER->id,
    ]);

    if (!$conversation) {
        echo json_encode(['success' => false, 'error' => 'Conversation not found.']);
        die();
    }

    $messages = $DB->get_records('local_edubot_messages', [
        'conversation_id' => $conversationid,
    ], 'created_at ASC');

    $msglist = [];
    foreach ($messages as $msg) {
        $msglist[] = [
            'id' => (int) $msg->id,
            'role' => $msg->role,
            'content' => clean_text($msg->content),
            'strategy_used' => $msg->strategy_used,
            'emotion_detected' => $msg->emotion_detected,
            'created_at' => (int) $msg->created_at,
        ];
    }

    echo json_encode([
        'success' => true,
        'conversation' => [
            'id' => (int) $conversation->id,
            'title' => $conversation->title,
            'topic' => $conversation->topic,
            'courseid' => (int) $conversation->courseid,
            'created_at' => (int) $conversation->created_at,
        ],
        'messages' => $msglist,
    ]);
    die();
}

// Load all conversations for sidebar (optionally filtered by course).
$conditions = ['userid' => $USER->id];
if ($courseid > 0) {
    $conditions['courseid'] = $courseid;
}

$conversations = $DB->get_records(
    'local_edubot_conversations',
    $conditions,
    'updated_at DESC'
);

$convlist = [];
foreach ($conversations as $conv) {
    $course = $DB->get_record('course', ['id' => $conv->courseid], 'id, fullname');
    $convlist[] = [
        'id' => (int) $conv->id,
        'title' => $conv->title ?: 'New conversation',
        'topic' => $conv->topic,
        'courseid' => (int) $conv->courseid,
        'course_name' => $course ? $course->fullname : '',
        'conversation_type' => $conv->conversation_type ?? 'chat',
        'created_at' => (int) $conv->created_at,
        'updated_at' => (int) $conv->updated_at,
    ];
}

echo json_encode([
    'success' => true,
    'conversations' => $convlist,
]);

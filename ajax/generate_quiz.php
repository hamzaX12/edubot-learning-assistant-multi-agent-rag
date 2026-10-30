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
 * AJAX endpoint: generate a quiz on the current topic.
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

require_login();
require_sesskey();

$conversationid = required_param('conversation_id', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$topic = optional_param('topic', '', PARAM_TEXT);

// Verify ownership.
$conversation = $DB->get_record('local_edubot_conversations', [
    'id' => $conversationid,
    'userid' => $USER->id,
]);

if (!$conversation) {
    echo json_encode(['success' => false, 'error' => 'Invalid conversation.']);
    die();
}

$coursecontext = context_course::instance($courseid, IGNORE_MISSING);
if (!$coursecontext || !is_enrolled($coursecontext, $USER)) {
    echo json_encode(['success' => false, 'error' => get_string('error_not_enrolled', 'local_edubot')]);
    die();
}

// Use the conversation topic or provided topic.
$quiztopic = !empty($topic) ? $topic : ($conversation->topic ?: 'current topic');

// Build a quiz generation prompt.
$quizprompt = "Generate a short practice quiz (3-5 multiple choice questions) on the topic: {$quiztopic}. " .
    "Format each question as:\n" .
    "Q1: [question]\n" .
    "A) [option]\nB) [option]\nC) [option]\nD) [option]\n" .
    "Correct: [letter]\n\n" .
    "Make questions progressively harder. Include brief explanations for correct answers.";

// Load RAG context for the topic.
$ragengine = new \local_edubot\rag_engine();
$maxchunks = (int) get_config('local_edubot', 'max_rag_chunks') ?: 3;
$chunks = $ragengine->retrieve_relevant_chunks($quiztopic, $courseid, $maxchunks);
$ragcontext = $ragengine->format_rag_context($chunks);

// Build minimal system prompt for quiz generation.
$systemprompt = "You are EduBot, a pedagogical tutor. Generate a practice quiz based on course materials. ";
if (!empty($ragcontext)) {
    $systemprompt .= "Use these course materials as source:\n{$ragcontext}\n";
} else {
    $systemprompt .= "No course materials available. Use general CS knowledge.\n";
}

// Send to Gemini.
$gemini = new \local_edubot\gemini_client();
$response = $gemini->send_message([], $systemprompt, $quizprompt);

$now = time();

// Save as messages.
$usermsg = new stdClass();
$usermsg->conversation_id = $conversationid;
$usermsg->role = 'user';
$usermsg->content = "Generate a quiz on: {$quiztopic}";
$usermsg->strategy_used = 'METACOGNITIVE_MONITORING';
$usermsg->emotion_detected = 'NEUTRAL';
$usermsg->created_at = $now;
$DB->insert_record('local_edubot_messages', $usermsg);

$botmsg = new stdClass();
$botmsg->conversation_id = $conversationid;
$botmsg->role = 'assistant';
$botmsg->content = $response;
$botmsg->strategy_used = 'METACOGNITIVE_MONITORING';
$botmsg->emotion_detected = 'NEUTRAL';
$botmsg->created_at = $now + 1;
$DB->insert_record('local_edubot_messages', $botmsg);

echo json_encode([
    'success' => true,
    'message' => $response,
    'strategy' => 'METACOGNITIVE_MONITORING',
    'emotion' => 'NEUTRAL',
]);

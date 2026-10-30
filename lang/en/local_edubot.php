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
 * Language strings for local_edubot.
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'EduBot';
$string['edubot:use'] = 'Use EduBot chatbot';
$string['edubot:manage'] = 'Manage EduBot settings';
$string['edubot:viewallconversations'] = 'View all EduBot conversations';

// Settings strings.
$string['settings_heading'] = 'EduBot Configuration';
$string['settings_heading_desc'] = 'Configure the EduBot intelligent pedagogical tutor chatbot.';
$string['gemini_api_key'] = 'Gemini API Key';
$string['gemini_api_key_desc'] = 'Enter your Google Gemini API key. This is required for the chatbot to function.';
$string['gemini_model'] = 'Gemini Model';
$string['gemini_model_desc'] = 'Select the Gemini model to use for generating responses.';
$string['constraint_mode'] = 'Constraint Mode';
$string['constraint_mode_desc'] = 'GUIDED: hints and Socratic questions only. STRICT: only point to resources. OPEN: full explanations allowed.';
$string['max_rag_chunks'] = 'Max RAG Chunks';
$string['max_rag_chunks_desc'] = 'Maximum number of RAG chunks to retrieve for context (1-10).';
$string['enable_emotion_detection'] = 'Enable Emotion Detection';
$string['enable_emotion_detection_desc'] = 'Enable keyword-based emotion detection on student messages.';
$string['plugin_enabled'] = 'Enable EduBot';
$string['plugin_enabled_desc'] = 'Enable or disable the EduBot chatbot globally.';

// UI strings.
$string['conversations'] = 'Conversations';
$string['new_conversation'] = '+ New conversation';
$string['no_conversations'] = 'No conversations yet. Start a new one!';
$string['type_message'] = 'Type your message...';
$string['send'] = 'Send';
$string['close'] = 'Close';
$string['edubot_title'] = 'EduBot';
$string['welcome_message'] = 'Hello! I\'m EduBot, your intelligent tutor. How can I help you today? Ask me about any concept from your course.';

// Quick action buttons.
$string['action_explain'] = 'Explain this concept';
$string['action_hint'] = 'Give me a hint';
$string['action_example'] = 'Show me an example';
$string['action_quiz'] = 'Generate a quiz';
$string['action_check'] = 'Check my understanding';

// Strategy labels.
$string['strategy_activation'] = 'Activation';
$string['strategy_socratic'] = 'Socratic Questioning';
$string['strategy_scaffolded'] = 'Scaffolded Hints';
$string['strategy_error_correction'] = 'Error Correction';
$string['strategy_emotional'] = 'Emotional Support';
$string['strategy_metacognitive'] = 'Metacognitive Monitoring';
$string['strategy_consolidation'] = 'Consolidation';

// Error messages.
$string['error_no_api_key'] = 'Gemini API key is not configured. Please contact your administrator.';
$string['error_api_failed'] = 'I\'m having trouble connecting right now. Please try again in a moment.';
$string['error_api_overloaded'] = 'The AI service is temporarily overloaded. Please wait a few seconds and try again.';
$string['error_not_enrolled'] = 'You must be enrolled in a course to use EduBot.';
$string['error_plugin_disabled'] = 'EduBot is currently disabled.';
$string['error_invalid_sesskey'] = 'Invalid session key. Please refresh the page and try again.';

// Admin page strings.
$string['reindex_heading'] = 'Course Resource Indexing';
$string['reindex_desc'] = 'Select a course and click the button to re-index its resources for the RAG system.';
$string['reindex_button'] = 'Re-index course resources';
$string['reindex_success'] = 'Course resources have been successfully re-indexed.';
$string['reindex_select_course'] = 'Select a course';
$string['manage_indexing'] = 'Manage RAG Indexing';

// Privacy strings.
$string['privacy:metadata:local_edubot_conversations'] = 'Stores user chat conversations with EduBot.';
$string['privacy:metadata:local_edubot_messages'] = 'Stores individual chat messages.';
$string['privacy:metadata:local_edubot_student_profiles'] = 'Stores student learning profiles.';
$string['privacy:metadata:local_edubot_rag_chunks'] = 'Stores indexed course material chunks and embeddings for RAG retrieval.';

// LlamaParse Services settings.
$string['llamaparse_api_key'] = 'LlamaParse API Key';
$string['llamaparse_api_key_desc'] = 'API key from LlamaCloud for PDF text extraction. Get one at https://cloud.llamaindex.ai/';

// RAG indexing UI.
$string['indexed_courses'] = 'Indexed Courses';
$string['reindex_starting'] = 'Starting indexing...';
$string['reindex_processing'] = 'Processing: {$a}';
$string['reindex_complete'] = 'Indexing complete!';
$string['reindex_failed'] = 'Indexing failed.';


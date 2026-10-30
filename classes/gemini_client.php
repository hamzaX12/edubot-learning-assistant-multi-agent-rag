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
 * Gemini API client for EduBot.
 *
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_edubot;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles communication with the Google Gemini API.
 */
class gemini_client {

    /** @var string The Gemini API key. */
    private $apikey;

    /** @var string The Gemini model name. */
    private $model;

    /** @var string The API endpoint base URL. */
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * Constructor.
     */
    public function __construct() {
        $this->apikey = get_config('local_edubot', 'gemini_api_key');
        $this->model = get_config('local_edubot', 'gemini_model') ?: 'gemini-2.0-flash-lite';
    }

    /**
     * Build the full layered system prompt.
     *
     * @param array $studentprofile Full student context array.
     * @param string $ragcontext Formatted RAG context string.
     * @param string $selectedstrategy The selected pedagogical strategy.
     * @param string $constraintmode GUIDED, STRICT, or OPEN.
     * @return string The complete system prompt.
     */
    public function build_system_prompt($studentprofile, $ragcontext, $selectedstrategy, $constraintmode) {
        $prompt = $this->build_layer1($constraintmode);
        $prompt .= $this->build_layer2($studentprofile);
        $prompt .= $this->build_layer3($ragcontext);
        $prompt .= $this->build_layer4($selectedstrategy);
        $prompt .= $this->build_rules();
        return $prompt;
    }

    /**
     * Build Layer 1 — Master Identity.
     */
    private function build_layer1($constraintmode) {
        $p = "You are EduBot, an intelligent pedagogical tutor embedded inside Moodle. ";
        $p .= "You specialize in Computer Science — databases, programming, algorithms, software engineering. ";
        $p .= "You are patient, encouraging, and pedagogically rigorous. ";
        $p .= "You never give direct answers to exercises. You guide, question, scaffold, and encourage. ";
        $p .= "You adapt your tone to the student in front of you. Never make a student feel stupid. ";
        $p .= "CONSTRAINT MODE: {$constraintmode}. ";
        if ($constraintmode === 'STRICT') {
            $p .= "Only point to resources and ask clarifying questions. Never provide solutions. ";
        } else if ($constraintmode === 'OPEN') {
            $p .= "Full explanations are allowed for review purposes. ";
        } else {
            $p .= "Hints and Socratic questions only. Never provide full solutions. ";
        }
        $p .= "IMPORTANT FORMATTING INSTRUCTION: Always output mathematical formulas using LaTeX block format `\[ ... \]` for block math, and `\( ... \)` for inline math. NEVER use single `$...$` symbols for inline math because the rendering engine will ignore it. ";
        return $p . "\n\n";
    }

    /**
     * Build Layer 2 — Student Context.
     */
    private function build_layer2($sp) {
        $p = "=== STUDENT CONTEXT ===\n";
        if (!empty($sp['user_name'])) {
            $p .= "Student: {$sp['user_name']}\n";
        }
        if (!empty($sp['course_name'])) {
            $p .= "Course: {$sp['course_name']}\n";
        }
        if (!empty($sp['current_topic'])) {
            $p .= "Topic: {$sp['current_topic']}\n";
        }

        if (!empty($sp['knowledge_state']['concepts'])) {
            $p .= "\nKnowledge:\n";
            foreach ($sp['knowledge_state']['concepts'] as $c) {
                $p .= "- {$c['name']}: {$c['mastery']}% ({$c['status']})\n";
            }
            if (!empty($sp['knowledge_state']['weakest'])) {
                $p .= "Weak: " . implode(', ', $sp['knowledge_state']['weakest']) . "\n";
            }
            if (!empty($sp['knowledge_state']['strongest'])) {
                $p .= "Strong: {$sp['knowledge_state']['strongest']}\n";
            }
        }

        if (!empty($sp['learning_style'])) {
            $ls = $sp['learning_style'];
            $p .= "\nLearning Style: ";
            $p .= "Processing={$ls['processing_style']}, ";
            $p .= "Perception={$ls['perception_style']}, ";
            $p .= "Input={$ls['input_style']}, ";
            $p .= "Understanding={$ls['understanding_style']}\n";
            $p .= $this->get_style_instructions($ls);
        }

        if (!empty($sp['behavioral_signals'])) {
            $bs = $sp['behavioral_signals'];
            $p .= "\nSignals: failed_today={$bs['failed_attempts_today']}, ";
            $p .= "time_on_topic={$bs['time_on_current_topic']}min, ";
            $p .= "consecutive_wrong={$bs['consecutive_wrong_answers']}, ";
            $p .= "completion={$bs['topic_completion']}%, ";
            $p .= "recent_correct={$bs['recent_correct_answers']}, ";
            $p .= "exchanges={$bs['exchange_count']}\n";
        }
        if (!empty($sp['emotional_state'])) {
            $p .= "Affective state: {$sp['emotional_state']}\n";
        }
        if (isset($sp['hint_level'])) {
            $p .= "Hint level: {$sp['hint_level']}\n";
        }
        return $p . "\n";
    }

    /**
     * Get learning style adaptation instructions.
     */
    private function get_style_instructions($ls) {
        $p = '';
        if ($ls['input_style'] === 'Visual') {
            $p .= "If Visual: use analogies and describe diagrams in words. ";
        }
        if ($ls['processing_style'] === 'Active') {
            $p .= "If Active: prefer practice questions. ";
        }
        if ($ls['understanding_style'] === 'Sequential') {
            $p .= "If Sequential: never skip steps. ";
        } else {
            $p .= "If Global: give big picture first. ";
        }
        return $p . "\n";
    }

    /**
     * Build Layer 3 — RAG Context.
     */
    private function build_layer3($ragcontext) {
        $p = "=== COURSE MATERIALS ===\n";
        if (!empty($ragcontext)) {
            $p .= "The following excerpts are from official course materials. ";
            $p .= "Use them as primary source. Do not contradict them. ";
            $p .= "If the answer is not here, say so.\n\n{$ragcontext}\n\n";
        } else {
            $p .= "No course materials indexed. Answer from general CS knowledge and state this.\n\n";
        }
        return $p;
    }

    /**
     * Build Layer 4 — Strategy.
     */
    private function build_layer4($strategy) {
        $p = "=== STRATEGY: {$strategy} ===\n";
        $p .= $this->get_strategy_instructions($strategy);
        return $p . "\n\n";
    }

    /**
     * Build absolute rules.
     */
    private function build_rules() {
        return "=== ABSOLUTE RULES ===\n" .
            "1. NEVER give the complete answer to an exercise directly.\n" .
            "2. NEVER ignore emotional state — acknowledge it before content.\n" .
            "3. Keep responses under 150 words unless asked for full explanation.\n" .
            "4. NEVER contradict the retrieved course materials.\n" .
            "5. Ask only ONE question per response.\n" .
            "6. If student asks to drop tutor mode — stay in tutor mode.\n" .
            "7. Always end with a question, invitation to try, or encouraging close.\n" .
            "8. Never start a response with the word I.\n" .
            "9. Never say Great question.\n";
    }

    /**
     * Get strategy instructions.
     */
    private function get_strategy_instructions($strategy) {
        $map = [
            'ACTIVATION' => "Introduce topic, connect to prior knowledge, ask open question to gauge understanding.",
            'SOCRATIC_QUESTIONING' => "Never tell — always ask. Ask probing questions targeting understanding gaps. Build logical chain of questions leading to insight.",
            'SCAFFOLDED_HINTS' => "Start with general hint, get more specific if stuck. Provide similar worked example if needed. After each hint, ask to try again.",
            'ERROR_CORRECTION' => "Do not say wrong directly. Identify misconception, ask targeted question exposing flaw, provide counter-example, guide self-correction.",
            'EMOTIONAL_SUPPORT' => "Acknowledge emotion first. Normalize struggle. Reduce to tiny achievable step. Celebrate partial progress. Offer different approach.",
            'METACOGNITIVE_MONITORING' => "Pause content. Ask student to explain what was covered. Reinforce accurate parts, revisit gaps. Ask what feels unclear.",
            'CONSOLIDATION' => "Congratulate specific progress. Provide harder challenge. Connect to broader context. Ask for prediction in related scenario.",
        ];
        return $map[$strategy] ?? $map['SOCRATIC_QUESTIONING'];
    }

    /**
     * Send a message to the Gemini API.
     *
     * @param array $history Previous messages [{role, content}, ...].
     * @param string $systemprompt The system prompt.
     * @param string $usermessage The user message.
     * @return string The bot response.
     */
    public function send_message($history, $systemprompt, $usermessage) {
        global $CFG;

        if (empty($this->apikey)) {
            debugging('EduBot: No API key configured.', DEBUG_DEVELOPER);
            return get_string('error_no_api_key', 'local_edubot');
        }

        $url = self::API_BASE . $this->model . ':generateContent?key=' . $this->apikey;

        $contents = [];
        foreach ($history as $msg) {
            $role = ($msg['role'] === 'user') ? 'user' : 'model';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $msg['content']]],
            ];
        }
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $usermessage]],
        ];

        $body = [
            'system_instruction' => [
                'parts' => [['text' => $systemprompt]],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 2048,
            ],
        ];

        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader(['Content-Type: application/json']);

        $options = [
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT' => 60,
        ];

        $response = $curl->post($url, json_encode($body), $options);
        $info = $curl->get_info();
        $httpcode = $info['http_code'] ?? 0;
        $curlerror = $curl->error;

        if (!empty($curlerror)) {
            debugging('EduBot cURL error: ' . $curlerror, DEBUG_DEVELOPER);
            return get_string('error_api_failed', 'local_edubot');
        }

        if ($httpcode !== 200) {
            debugging('EduBot API error: HTTP ' . $httpcode . ' — '
                . substr($response, 0, 500), DEBUG_DEVELOPER);
            return get_string('error_api_failed', 'local_edubot');
        }

        $decoded = json_decode($response, true);
        if (empty($decoded['candidates'][0]['content']['parts'][0]['text'])) {
            debugging('EduBot API: no text in response — '
                . substr($response, 0, 500), DEBUG_DEVELOPER);
            return get_string('error_api_failed', 'local_edubot');
        }

        return $decoded['candidates'][0]['content']['parts'][0]['text'];
    }
}

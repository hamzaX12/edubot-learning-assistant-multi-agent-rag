<?php
namespace local_edubot\agents;

defined('MOODLE_INTERNAL') || die();

class diagnostic_agent {
    public function run($userid, $courseid, $conversationid, $message) {
        // 1. Profiler
        $profiler = new \local_edubot\student_profiler();
        $profile = $profiler->get_full_context($userid, $courseid, $conversationid);

        // 2. Emotion
        $detector = new \local_edubot\emotion_detector();
        $behavioral = $profile['behavioral_signals'];
        $msganalysis = $detector->analyze_message($message, $behavioral['exchange_count']);
        $affective_state = $detector->combine_signals($msganalysis, $behavioral);

        // 3. Hint Level
        $selector = new \local_edubot\strategy_selector();
        $selector->load_hint_level($conversationid);
        $hint_level = $selector->get_hint_level($conversationid);

        // Calculate overall score from concepts
        $concepts = $profile['knowledge_state']['concepts'] ?? [];
        $total_mastery = 0;
        $concept_count = 0;
        foreach ($concepts as $c) {
            if ($c['status'] !== 'NOT_SEEN') {
                $total_mastery += $c['mastery'];
                $concept_count++;
            }
        }
        $overall_score = $concept_count > 0 ? round($total_mastery / $concept_count) : 0;

        $failed_today = $behavioral['failed_attempts_today'] ?? 0;
        $time_on_topic = $behavioral['time_on_current_topic'] ?? 0;
        
        $is_at_risk = false;
        if ($failed_today >= 3 || $overall_score < 40 || $time_on_topic > 45) {
            $is_at_risk = true;
        }

        $student_state = [
            'userid' => $userid,
            'courseid' => $courseid,
            'student_name' => $profile['user_name'] ?? '',
            'program' => '',
            'course_name' => $profile['course_name'] ?? '',
            'current_topic' => $profile['current_topic'] ?? '',
            'next_topic' => $profile['next_topic'] ?? '',
            'overall_score' => $overall_score,
            'knowledge_concepts' => $concepts,
            'weakest_concepts' => $profile['knowledge_state']['weakest'] ?? [],
            'strongest_concept' => $profile['knowledge_state']['strongest'] ?? '',
            'learning_style' => $profile['learning_style'] ?? [],
            'affective_state' => $affective_state,
            'behavioral_signals' => $behavioral,
            'is_at_risk' => $is_at_risk,
            'hint_level' => $hint_level,
            'exchange_count' => $behavioral['exchange_count'] ?? 0,
            'topic_completion' => $behavioral['topic_completion'] ?? 0,
            'recent_correct_answers' => $behavioral['recent_correct_answers'] ?? 0,
        ];

        return $student_state;
    }
}

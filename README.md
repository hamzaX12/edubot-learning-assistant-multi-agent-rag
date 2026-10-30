# EduBot — Intelligent Pedagogical Chatbot for Moodle

**EduBot** is a Moodle local plugin (`local_edubot`) that embeds an AI-powered tutoring assistant directly into course pages. It uses the **Google Gemini API** for language generation and semantic embeddings, following a multi-agent architecture driven by pedagogical best practices.

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [File Structure](#file-structure)
4. [Core Classes](#core-classes)
   - [gemini_client.php](#gemini_clientphp)
   - [rag_engine.php](#rag_enginephp)
   - [student_profiler.php](#student_profilerphp)
   - [strategy_selector.php](#strategy_selectorphp)
   - [emotion_detector.php](#emotion_detectorphp)
5. [Agent Pipeline](#agent-pipeline)
   - [agent_orchestrator.php](#agent_orchestratorphp)
   - [diagnostic_agent.php](#diagnostic_agentphp)
   - [content_agent.php](#content_agentphp)
   - [pedagogical_agent.php](#pedagogical_agentphp)
   - [reflection_agent.php](#reflection_agentphp)
   - [debrief_orchestrator.php](#debrief_orchestratorphp)
   - [indexing_agent.php](#indexing_agentphp)
6. [AJAX Endpoints](#ajax-endpoints)
7. [Database Schema](#database-schema)
8. [Plugin Hooks & Events](#plugin-hooks--events)
9. [Settings](#settings)
10. [Installation](#installation)

---

## Overview

EduBot injects a floating chat widget into every Moodle course page. When a student sends a message, a multi-step agent pipeline:

1. **Profiles** the student (knowledge, learning style, behavior, emotion).
2. **Retrieves** relevant course material using RAG (Retrieval-Augmented Generation).
3. **Selects** a pedagogical strategy (Socratic, Scaffolded Hints, Emotional Support, etc.).
4. **Generates** a tailored response via the Gemini API.
5. **Reflects** on the response quality before delivering it.

The bot never gives direct answers to exercises — it guides, questions, scaffolds, and encourages.

---

## Architecture

```
Student Message
      |
      v
+---------------------+
|  agent_orchestrator  |  <- Entry point for every chat message
+----------+----------+
           |
    +------v------+
    | diagnostic_ |  <- Builds full student state
    |    agent    |     (profile + emotion + signals)
    +------+------+
           |
    +------v------+
    |  content_   |  <- RAG retrieval + button action parsing
    |    agent    |
    +------+------+
           |
    +------v------+
    | pedagogical |  <- Strategy selection + Gemini call
    |    agent    |
    +------+------+
           |
    +------v------+
    | reflection_ |  <- Quality check (retry up to 2x)
    |    agent    |
    +------+------+
           |
      Bot Response
```

---

## File Structure

```
local/edubot/
+-- README.md                        <- This file
+-- version.php                      <- Plugin version & dependencies
+-- lib.php                          <- Moodle hook functions + chat widget HTML
+-- settings.php                     <- Admin settings page
+-- upload_material.php              <- Teacher UI for uploading PDFs + auto-queues RAG indexing
+-- extract_txt_pdf.php              <- Library: LlamaParse PDF extraction (required by rag_engine)
|
+-- ajax/                            <- AJAX endpoint handlers
|   +-- send_message.php             <- Main chat message handler
|   +-- get_history.php              <- Load conversation history
|   +-- new_conversation.php         <- Start a new conversation
|   +-- delete_conversation.php      <- Delete a conversation
|   +-- get_course_greeting.php      <- Generate welcome message
|   +-- generate_quiz.php            <- Generate an in-chat quiz
|   +-- get_index_progress.php       <- Poll RAG indexing progress
|   +-- reindex_course.php           <- Trigger manual course reindex
|
+-- amd/                             <- AMD JavaScript modules
|
+-- assets/                          <- Static assets (CSS, images)
|
+-- classes/
|   +-- gemini_client.php            <- Gemini API wrapper + system prompt builder
|   +-- rag_engine.php               <- RAG pipeline (embed, store, retrieve)
|   +-- student_profiler.php         <- Builds student profiles from Moodle data
|   +-- strategy_selector.php        <- Maps student state -> pedagogical strategy
|   +-- emotion_detector.php         <- Detects emotional state from text/behavior
|   +-- quiz_debrief_helper.php      <- Post-quiz debrief utilities
|   +-- hook_callbacks.php           <- Moodle hook implementations
|   +-- event_observers.php          <- Moodle event listeners
|   |
|   +-- agents/
|   |   +-- agent_orchestrator.php   <- Main pipeline coordinator
|   |   +-- diagnostic_agent.php     <- Gathers student state
|   |   +-- content_agent.php        <- RAG retrieval + action parsing
|   |   +-- pedagogical_agent.php    <- Strategy selection + LLM call
|   |   +-- reflection_agent.php     <- Response quality review
|   |   +-- debrief_orchestrator.php <- Post-quiz debrief pipeline
|   |   +-- indexing_agent.php       <- Triggers course RAG indexing
|   |
|   +-- form/
|   |   +-- ...                      <- Moodle form definitions
|   |
|   +-- task/
|       +-- background_indexing_task.php  <- Moodle scheduled task for indexing
|
+-- db/
|   +-- install.xml                  <- Database table definitions
|   +-- upgrade.php                  <- Database upgrade steps
|   +-- access.php                   <- Capabilities definition
|   +-- events.php                   <- Event observer registrations
|   +-- hooks.php                    <- Hook callback registrations
|   +-- caches.php                   <- Cache store definitions
|
+-- lang/
    +-- en/
        +-- local_edubot.php         <- English language strings
```

---

## Core Classes

### `gemini_client.php`

**Namespace:** `local_edubot`

The central Gemini API wrapper. Responsible for building the **layered system prompt** and sending requests to the Gemini `generateContent` endpoint.

#### System Prompt Layers

| Layer | Purpose |
|-------|---------|
| **Layer 1 — Master Identity** | Defines EduBot's persona, subject domain (CS), and constraint mode (`GUIDED` / `STRICT` / `OPEN`). |
| **Layer 2 — Student Context** | Injects the student's name, course, topic, knowledge mastery, learning style, behavioral signals, and emotional state. |
| **Layer 3 — RAG Context** | Injects retrieved course material excerpts. The LLM is instructed to prioritize these and not contradict them. |
| **Layer 4 — Strategy** | Injects the active pedagogical strategy instruction (e.g., Socratic Questioning, Scaffolded Hints). |
| **Absolute Rules** | Hard rules: never give direct answers, keep responses under 150 words, acknowledge emotions first, always end with a question. |

#### Key Methods

| Method | Description |
|--------|-------------|
| `build_system_prompt($profile, $ragContext, $strategy, $constraintMode)` | Assembles all 4 layers + rules into a single system prompt string. |
| `send_message($history, $systemPrompt, $userMessage)` | POSTs to Gemini API with conversation history and returns the text response. |

#### Constraint Modes

| Mode | Behavior |
|------|---------|
| `GUIDED` | Hints and Socratic questions only. No full solutions. |
| `STRICT` | Resources and clarifying questions only. |
| `OPEN` | Full explanations allowed (review mode). |

---

### `rag_engine.php`

**Namespace:** `local_edubot`

Implements a full **Retrieval-Augmented Generation** pipeline for course materials.

#### Pipeline Steps

```
PDF Files -> Text Extraction -> Chunking -> Embedding -> MySQL Storage
                                                            |
User Query -> Query Embedding -> Cosine Similarity -> Top-K Chunks -> Prompt
```

#### Constants

| Constant | Value | Description |
|----------|-------|-------------|
| `EMBEDDING_API` | Gemini `gemini-embedding-001` | Embedding model endpoint |
| `CHUNK_SIZE` | 500 chars | Target chunk size |
| `CHUNK_OVERLAP` | 100 chars | Overlap between chunks |
| `MIN_CHUNK_SIZE` | 100 chars | Minimum chunk (merged if smaller) |
| `SIMILARITY_THRESHOLD` | 0.5 | Minimum cosine similarity to include a chunk |

#### Key Methods

| Method | Description |
|--------|-------------|
| `index_course($courseId)` | Extracts text from all course PDFs, chunks them, embeds them, and stores in DB. |
| `retrieve($query, $courseId, $topK)` | Embeds the query and retrieves the top-K most similar chunks via cosine similarity. Falls back to keyword search if no embeddings found. |
| `format_context($chunks)` | Formats retrieved chunks as a numbered list for prompt injection. |

#### Fallback Strategy

If semantic embeddings are unavailable (e.g., no indexed material), the engine falls back to a **keyword-based search** that filters stop words and matches by term frequency.

---

### `student_profiler.php`

**Namespace:** `local_edubot`

Reads Moodle activity data to build a **comprehensive learner profile** for each student-course pair.

#### Profile Components

| Component | Source | Description |
|-----------|--------|-------------|
| **Knowledge State** | `quiz_grades` | Per-quiz mastery scores (0-100%), status: `NOT_SEEN`, `WEAK`, `DEVELOPING`, `STRONG`. Identifies weakest 2 concepts and strongest. |
| **Learning Style** | `logstore_standard_log` | Felder-Silverman model: Visual/Verbal, Active/Reflective, Sequential/Global, Sensing/Intuitive — inferred from resource access patterns. |
| **Behavioral Signals** | `quiz_attempts`, `logstore_standard_log`, `local_edubot_messages` | Failed attempts today, time on topic, help requests, consecutive wrong answers, topic completion %, recent correct answers, exchange count. |

#### Key Methods

| Method | Returns |
|--------|---------|
| `get_full_context($userId, $courseId, $conversationId)` | Combined profile: user info + knowledge + learning style + behavioral signals. |
| `get_knowledge_state($userId, $courseId)` | Quiz-based concept mastery map. |
| `get_learning_style($userId, $courseId)` | Felder-Silverman style profile (saved to DB). |
| `get_behavioral_signals($userId, $courseId, $conversationId)` | Real-time behavioral metrics. |

---

### `strategy_selector.php`

**Namespace:** `local_edubot`

Maps student state to one of 7 pedagogical strategies.

#### Strategies

| Strategy | Trigger Condition |
|----------|------------------|
| `ACTIVATION` | First 1-2 exchanges in a conversation. |
| `EMOTIONAL_SUPPORT` | Frustrated / Anxious / Discouraged state, or >= 3 failed attempts today. |
| `ERROR_CORRECTION` | Wrong answer detected, hint level = 0. |
| `SCAFFOLDED_HINTS` | Wrong answer, hint level >= 1. Also triggered by the `hint` button. |
| `METACOGNITIVE_MONITORING` | Every 5th exchange, or `check` button. |
| `CONSOLIDATION` | Topic completion >= 80% and >= 3 recent correct answers, or `quiz` button. |
| `SOCRATIC_QUESTIONING` | Default for all other cases. |

---

### `emotion_detector.php`

**Namespace:** `local_edubot`

Detects the student's **affective state** from message text and behavioral signals.

#### Detected States

`FRUSTRATED` · `ANXIOUS` · `DISCOURAGED` · `CONFUSED` · `BORED` · `EXCITED` · `NEUTRAL`

Detection uses keyword matching on the student's message combined with behavioral signal thresholds (e.g., multiple failed attempts -> `FRUSTRATED`).

---

## Agent Pipeline

### `agent_orchestrator.php`

**Namespace:** `local_edubot\agents`

The **entry point** for every chat interaction. Coordinates all agents and persists messages to the database.

```
handle($userId, $courseId, $message, $conversationId, $buttonAction)
  |
  +- Load conversation + message history
  +- DiagnosticAgent::run()      -> student_state
  +- ContentAgent::run()         -> content (RAG + action)
  +- Loop (max 2 retries):
  |   +- PedagogicalAgent::run() -> pedagogical_output
  |   +- ReflectionAgent::run()  -> approved? -> break : retry
  |
  +- Save user message to DB
  +- Save bot response to DB
  +- Update conversation title/topic
```

Returns a JSON object: `{ success, message, strategy, emotion }`.

---

### `diagnostic_agent.php`

**Namespace:** `local_edubot\agents`

Builds a full **student state snapshot** by calling `student_profiler` and `emotion_detector`.

**Output includes:**
- Student profile (knowledge, style, signals)
- Affective state
- Hint level
- Topic completion and recent correct answers

---

### `content_agent.php`

**Namespace:** `local_edubot\agents`

Handles **RAG retrieval** and parses **button actions** into prompt instructions.

| Button | Action Instruction Added to Prompt |
|--------|------------------------------------|
| `hint` | "Provide a progressive hint" |
| `explain` | "Provide a full step-by-step explanation" |
| `quiz` | "Generate a short practice quiz" |
| `check` | "Ask student to explain back what they learned" |
| `example` | "Provide a concrete worked example" |

---

### `pedagogical_agent.php`

**Namespace:** `local_edubot\agents`

Selects the pedagogical strategy, builds the full system prompt via `gemini_client`, and calls the Gemini API.

**Strategy priority order:**
1. Button action override
2. Emotional state / failed attempts
3. Exchange count (activation for new conversations)
4. Message analysis (wrong answer detection)
5. Periodic metacognitive check (every 5 exchanges)
6. High completion + success -> consolidation
7. Default: Socratic questioning

---

### `reflection_agent.php`

**Namespace:** `local_edubot\agents`

Acts as a **quality gate** on the pedagogical agent's output. Uses a second Gemini call to evaluate whether the response:
- Is pedagogically sound
- Matches the selected strategy
- Does not directly answer an exercise
- Acknowledges emotional state if present

If the response fails, it returns `is_approved = false` with specific `retry_feedback` that gets injected into the next attempt's system prompt. The orchestrator retries up to **2 times**.

---

### `debrief_orchestrator.php`

**Namespace:** `local_edubot\agents`

Handles the **post-quiz debrief** flow. Triggered after a student completes a quiz attempt. Analyzes quiz results, identifies weak areas, and generates a personalized debrief conversation using the Gemini API.

---

### `indexing_agent.php`

**Namespace:** `local_edubot\agents`

Triggers RAG indexing for a course by calling `rag_engine::index_course()`. Used both by the manual reindex AJAX endpoint and the background scheduled task.

---

## AJAX Endpoints

All endpoints are in the `ajax/` directory and require a valid Moodle session + sesskey.

| File | Method | Description |
|------|--------|-------------|
| `send_message.php` | POST | Main chat endpoint. Calls `agent_orchestrator::handle()`. |
| `get_history.php` | GET | Returns all messages in a conversation. |
| `new_conversation.php` | POST | Creates a new conversation record. |
| `delete_conversation.php` | POST | Deletes a conversation and its messages. |
| `get_course_greeting.php` | GET | Generates a personalized welcome message for the course. |
| `generate_quiz.php` | POST | Generates an in-chat quiz on the current topic. |
| `get_index_progress.php` | GET | Returns the current RAG indexing progress for a course. |
| `reindex_course.php` | POST | Triggers a manual re-index of course materials. |

---

## Database Schema

Defined in `db/install.xml`.

| Table | Description |
|-------|-------------|
| `local_edubot_conversations` | Chat conversation records (user, course, title, topic, timestamps). |
| `local_edubot_messages` | Individual messages (role: `user`/`assistant`, content, strategy used, emotion detected). |
| `local_edubot_student_profiles` | Persisted Felder-Silverman learning style profiles per user-course pair. |
| `local_edubot_chunks` | RAG text chunks (course material text segments with metadata). |
| `local_edubot_embeddings` | Vector embeddings stored as JSON float arrays, linked to chunks. |
| `local_edubot_index_progress` | Tracks RAG indexing progress per course (total chunks, indexed count, status). |

---

## Plugin Hooks & Events

### Hooks (`db/hooks.php`)

| Hook | Callback | Purpose |
|------|----------|---------|
| `before_standard_head_html_generation` | `hook_callbacks::inject_css` | Injects EduBot CSS into every page. |
| `before_footer_html_generation` | `hook_callbacks::inject_chatbot` | Injects the chat widget HTML + JS into course pages. |

### Events (`db/events.php`)

| Event | Observer | Purpose |
|-------|---------|---------|
| `\mod_quiz\event\attempt_submitted` | `event_observers::quiz_attempt_submitted` | Triggers post-quiz debrief when a student submits a quiz attempt. |

---

## Settings

Configured at **Site Administration -> Plugins -> Local plugins -> EduBot**.

| Setting | Key | Description |
|---------|-----|-------------|
| Gemini API Key | `gemini_api_key` | Google Gemini API key (required). |
| Gemini Model | `gemini_model` | Model name, default: `gemini-2.0-flash-lite`. |
| Constraint Mode | `constraint_mode` | `GUIDED` (default), `STRICT`, or `OPEN`. |
| Adobe PDF API Key | `adobe_api_key` | Adobe PDF Services API key for PDF text extraction. |
| Adobe Client Secret | `adobe_client_secret` | Adobe PDF Services client secret. |

---

## Installation

1. Copy the `edubot` folder to `moodle/local/edubot/`.
2. Log in as Moodle admin and navigate to **Site Administration -> Notifications** to run the database install.
3. Go to **Site Administration -> Plugins -> Local plugins -> EduBot** and enter your **Gemini API key**.
4. To enable course material retrieval, upload PDFs via the **Upload Material** page accessible from any course (teacher role required).
5. The indexing runs in the background via Moodle's scheduled task runner (`admin/cli/cron.php`), or can be triggered manually from the chat widget.

---

## Key Design Decisions

- **No direct answers:** The system prompt's absolute rules and the reflection agent both enforce that EduBot never gives direct solutions to exercises.
- **Retry loop:** The orchestrator retries the pedagogical agent up to 2 times if the reflection agent rejects the response, ensuring quality without infinite loops.
- **Graceful degradation:** If the Gemini API is unavailable or no embeddings exist, the system returns user-friendly error messages and falls back to keyword search respectively.
- **Felder-Silverman model:** Learning styles are inferred passively from Moodle activity logs — students do not fill in questionnaires.
- **LaTeX math rendering:** The system prompt enforces `\[ ... \]` for block math and `\( ... \)` for inline math to ensure correct rendering in the frontend.

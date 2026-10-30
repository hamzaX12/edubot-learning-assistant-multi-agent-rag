/**
 * EduBot chatbot — AMD module for Moodle.
 *
 * @module     local_edubot/edubot
 * @package    local_edubot
 * @copyright  2025 EduBot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery'], function($) {

    var WWWROOT = '';
    var USERID = 0;
    var COURSEID = 0;
    var SESSKEY = '';
    var currentConversationId = 0;
    var isLoading = false;
    var activeAction = '';

    /**
     * Initialize the EduBot chatbot.
     */
    function init(wwwroot, userid, courseid, sesskey) {
        WWWROOT = wwwroot;
        USERID = userid;
        COURSEID = courseid;
        SESSKEY = sesskey;

        bindEvents();
        loadConversations();
        initSidebarToggle();
    }

    /**
     * Bind all UI event handlers.
     */
    function bindEvents() {
        // Open panel.
        $('#edubot-trigger-btn').on('click', function() {
            openPanel();
        });

        // Close panel.
        $('#edubot-close-btn').on('click', function() {
            closePanel();
        });

        // Overlay click closes panel.
        $('#edubot-overlay').on('click', function() {
            closePanel();
        });

        // New conversation.
        $('#edubot-new-conv-btn').on('click', function() {
            newConversation();
        });

        // Send message.
        $('#edubot-send-btn').on('click', function() {
            sendMessage();
        });

        // Enter to send, Shift+Enter for new line.
        $('#edubot-input').on('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        // Auto-resize textarea.
        $('#edubot-input').on('input', function() {
            autoResize(this);
        });

        // Quick action buttons.
        $(document).on('click', '.edubot-action-btn', function() {
            var action = $(this).data('action');
            var text = $(this).text();

            // Toggle active state.
            if (activeAction === action) {
                $(this).removeClass('active');
                activeAction = '';
                $('#edubot-input').val('');
            } else {
                $('.edubot-action-btn').removeClass('active');
                $(this).addClass('active');
                activeAction = action;
                $('#edubot-input').val(text).focus();
            }
        });

        // Conversation item click.
        $(document).on('click', '.edubot-conv-item', function() {
            var convId = $(this).data('id');
            loadConversation(convId);
        });
    }

    /**
     * Initialize sidebar collapse/expand toggle with localStorage persistence.
     */
    function initSidebarToggle() {
        var $panel = $('#local-edubot-panel');
        var STORAGE_KEY = 'edubot_sidebar_open';
        var isMobile = function() { return window.innerWidth <= 768; };

        // Inject mobile backdrop element once.
        if ($('#edubot-mobile-sidebar-backdrop').length === 0) {
            $panel.find('.edubot-panel-body').prepend(
                '<div id="edubot-mobile-sidebar-backdrop" class="edubot-mobile-sidebar-backdrop"></div>'
            );
        }

        // Restore desktop state from localStorage (default: open).
        function restoreState() {
            if (isMobile()) {
                $panel.removeClass('sidebar-collapsed sidebar-mobile-open');
                return;
            }
            var saved = localStorage.getItem(STORAGE_KEY);
            if (saved === 'false') {
                $panel.addClass('sidebar-collapsed');
                $('#edubot-sidebar-toggle').attr('title', 'Open sidebar')
                                          .attr('aria-label', 'Open sidebar');
            } else {
                $panel.removeClass('sidebar-collapsed');
                $('#edubot-sidebar-toggle').attr('title', 'Close sidebar')
                                          .attr('aria-label', 'Close sidebar');
            }
        }

        function collapseSidebar() {
            $panel.addClass('sidebar-collapsed');
            localStorage.setItem(STORAGE_KEY, 'false');
            $('#edubot-sidebar-toggle').attr('title', 'Open sidebar')
                                       .attr('aria-label', 'Open sidebar');
        }

        function expandSidebar() {
            $panel.removeClass('sidebar-collapsed');
            localStorage.setItem(STORAGE_KEY, 'true');
            $('#edubot-sidebar-toggle').attr('title', 'Close sidebar')
                                       .attr('aria-label', 'Close sidebar');
        }

        function openMobileSidebar() {
            $panel.addClass('sidebar-mobile-open');
        }

        function closeMobileSidebar() {
            $panel.removeClass('sidebar-mobile-open');
        }

        // Handle window resize — switch between desktop/mobile modes.
        $(window).on('resize.edubotSidebar', function() {
            if (isMobile()) {
                $panel.removeClass('sidebar-collapsed sidebar-mobile-open');
            } else {
                $panel.removeClass('sidebar-mobile-open');
                restoreState();
            }
        });

        restoreState();
    }

    /**
     * Open the chatbot panel.
     */
    function openPanel() {
        $('#local-edubot-panel').addClass('open');
        $('#edubot-overlay').addClass('active');
        $('#edubot-trigger-btn').addClass('hidden');
        $('#edubot-input').focus();
    }

    /**
     * Close the chatbot panel.
     */
    function closePanel() {
        $('#local-edubot-panel').removeClass('open');
        $('#edubot-overlay').removeClass('active');
        $('#edubot-trigger-btn').removeClass('hidden');
    }

    /**
     * Auto-resize textarea.
     */
    function autoResize(el) {
        el.style.height = 'auto';
        var maxHeight = 96; // ~4 lines.
        el.style.height = Math.min(el.scrollHeight, maxHeight) + 'px';
    }

    /**
     * Load conversations list.
     */
    function loadConversations() {
        $.ajax({
            url: WWWROOT + '/local/edubot/ajax/get_history.php',
            method: 'GET',
            data: {
                sesskey: SESSKEY,
                courseid: COURSEID
            },
            dataType: 'json',
            success: function(data) {
                if (data.success && data.conversations) {
                    renderConversationList(data.conversations);
                }
            },
            error: function(xhr, status, error) {
                console.error('EduBot: Failed to load conversations', error);
            }
        });
    }

    /**
     * Render conversation list in sidebar.
     */
    function renderConversationList(conversations) {
        var $list = $('#edubot-conv-list');
        $list.empty();

        if (conversations.length === 0) {
            $list.html('<div class="edubot-empty-state">No conversations yet. Start a new one!</div>');
            return;
        }

        conversations.forEach(function(conv) {
            var title = conv.title || 'New conversation';
            if (title.length > 30) {
                title = title.substring(0, 30) + '...';
            }

            var date = new Date(conv.updated_at * 1000);
            var dateStr = date.toLocaleDateString() + ' ' +
                date.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});

            var activeClass = (conv.id === currentConversationId) ? ' active' : '';

            var html = '<div class="edubot-conv-item' + activeClass + '" data-id="' + conv.id + '">';
            html += '<div class="edubot-conv-title">' + escapeHtml(title) + '</div>';
            html += '<div class="edubot-conv-meta">';
            html += '<span class="edubot-conv-dot"></span>';
            html += '<span>' + escapeHtml(conv.course_name || '') + '</span>';
            html += '</div>';
            html += '<div class="edubot-conv-meta">' + dateStr + '</div>';
            html += '</div>';

            $list.append(html);
        });
    }

    /**
     * Start a new conversation.
     */
    function newConversation() {
        var courseid = COURSEID;

        $.ajax({
            url: WWWROOT + '/local/edubot/ajax/new_conversation.php',
            method: 'POST',
            data: {
                sesskey: SESSKEY,
                courseid: courseid
            },
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    currentConversationId = data.conversation_id;
                    clearChat();
                    showWelcome();
                    loadConversations();
                    updateChatHeader('New conversation', data.course_name || '');
                }
            },
            error: function(xhr, status, error) {
                console.error('EduBot: Failed to create conversation', error);
            }
        });
    }

    /**
     * Load a specific conversation.
     */
    function loadConversation(convId) {
        currentConversationId = convId;

        // Highlight in sidebar.
        $('.edubot-conv-item').removeClass('active');
        $('.edubot-conv-item[data-id="' + convId + '"]').addClass('active');

        $.ajax({
            url: WWWROOT + '/local/edubot/ajax/get_history.php',
            method: 'GET',
            data: {
                sesskey: SESSKEY,
                conversation_id: convId
            },
            dataType: 'json',
            success: function(data) {
                if (data.success) {
                    clearChat();
                    var conv = data.conversation;
                    updateChatHeader(conv.topic || conv.title || 'Conversation', '');

                    if (data.messages && data.messages.length > 0) {
                        data.messages.forEach(function(msg) {
                            appendMessage(msg.role, msg.content, msg.strategy_used);
                        });
                        scrollToBottom();
                    } else {
                        showWelcome();
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error('EduBot: Failed to load conversation', error);
            }
        });
    }

    /**
     * Send a message.
     */
    function sendMessage() {
        if (isLoading) {
            return;
        }

        var $input = $('#edubot-input');
        var message = $input.val().trim();
        if (!message) {
            return;
        }

        // Create conversation if none active.
        if (!currentConversationId) {
            var courseid = COURSEID;
            $.ajax({
                url: WWWROOT + '/local/edubot/ajax/new_conversation.php',
                method: 'POST',
                data: {sesskey: SESSKEY, courseid: courseid},
                dataType: 'json',
                async: false,
                success: function(data) {
                    if (data.success) {
                        currentConversationId = data.conversation_id;
                    }
                }
            });
        }

        if (!currentConversationId) {
            return;
        }

        // Clear welcome and show user message.
        removeWelcome();
        appendMessage('user', message);
        $input.val('');
        $input.css('height', 'auto');
        activeAction = '';
        $('.edubot-action-btn').removeClass('active');

        // Show typing indicator.
        showTyping();
        isLoading = true;
        $('#edubot-send-btn').prop('disabled', true);

        var postData = {
            sesskey: SESSKEY,
            message: message,
            conversation_id: currentConversationId,
            courseid: COURSEID
        };

        if (activeAction) {
            postData.button_action = activeAction;
        }

        $.ajax({
            url: WWWROOT + '/local/edubot/ajax/send_message.php',
            method: 'POST',
            data: postData,
            dataType: 'json',
            success: function(data) {
                hideTyping();
                isLoading = false;
                $('#edubot-send-btn').prop('disabled', false);

                if (data.success) {
                    appendMessage('assistant', data.message, data.strategy);
                    scrollToBottom();
                    loadConversations(); // Refresh sidebar.
                } else {
                    appendMessage('assistant', data.error || 'Something went wrong. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                hideTyping();
                isLoading = false;
                $('#edubot-send-btn').prop('disabled', false);
                console.error('EduBot: Send message failed', error);
                appendMessage('assistant', 'Connection error. Please try again.');
            }
        });
    }

    /**
     * Append a message to the chat area.
     */
    function appendMessage(role, content, strategy) {
        var $messages = $('#edubot-messages');

        var html = '<div class="edubot-msg ' + role + '">';

        if (role === 'assistant' && strategy) {
            html += '<div class="edubot-msg-strategy">' + escapeHtml(strategy) + '</div>';
        }

        if (role === 'assistant') {
            html += '<div class="edubot-msg-label">EduBot</div>';
        }

        html += '<div class="edubot-msg-bubble">' + formatMessage(content) + '</div>';
        html += '</div>';

        $messages.append(html);
        scrollToBottom();
    }

    /**
     * Format message content (basic markdown-like formatting).
     */
    function formatMessage(text) {
        var escaped = escapeHtml(text);
        // Bold.
        escaped = escaped.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        // Inline code.
        escaped = escaped.replace(/`(.*?)`/g, '<code>$1</code>');
        // Line breaks.
        escaped = escaped.replace(/\n/g, '<br>');
        return escaped;
    }

    /**
     * Show typing indicator.
     */
    function showTyping() {
        var html = '<div class="edubot-typing" id="edubot-typing-indicator">';
        html += '<div class="edubot-typing-dot"></div>';
        html += '<div class="edubot-typing-dot"></div>';
        html += '<div class="edubot-typing-dot"></div>';
        html += '</div>';
        $('#edubot-messages').append(html);
        scrollToBottom();
    }

    /**
     * Hide typing indicator.
     */
    function hideTyping() {
        $('#edubot-typing-indicator').remove();
    }

    /**
     * Show welcome message.
     */
    function showWelcome() {
        var html = '<div class="edubot-welcome">';
        html += '<div class="edubot-welcome-icon">🤖</div>';
        html += '<p>Hello! I\'m EduBot, your intelligent tutor. How can I help you today?</p>';
        html += '</div>';
        $('#edubot-messages').html(html);
    }

    /**
     * Remove welcome message.
     */
    function removeWelcome() {
        $('.edubot-welcome').remove();
    }

    /**
     * Clear chat messages.
     */
    function clearChat() {
        $('#edubot-messages').empty();
    }

    /**
     * Update chat header.
     */
    function updateChatHeader(topic, courseName) {
        var text = topic || 'Conversation';
        if (courseName) {
            text += ' — ' + courseName;
        }
        $('#edubot-chat-topic').text(text);
    }

    /**
     * Scroll messages to bottom.
     */
    function scrollToBottom() {
        var $messages = $('#edubot-messages');
        $messages.scrollTop($messages[0].scrollHeight);
    }

    /**
     * Get course ID from page body classes.
     */
    function getPageCourseId() {
        var bodyClasses = $('body').attr('class') || '';
        var match = bodyClasses.match(/course-(\d+)/);
        return match ? parseInt(match[1]) : 0;
    }

    /**
     * Escape HTML to prevent XSS.
     */
    function escapeHtml(text) {
        if (!text) {
            return '';
        }
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    return {
        init: init
    };
});

<?php
if (!defined('ABSPATH')) {
    exit;
}

$openai_api_key = '';

if (empty($openai_api_key)) {
    error_log("Topic Tree: OpenAI API key missing. Define TOPIC_TREE_OPENAI_API_KEY in wp-config.php.");
}


/**
 * Count the number of words in a string.
 *
 * @param string $text The text to count words in.
 * @return int The number of words.
 */

//ANCHOR - Counts the number of words in a given text string, filtering out empty entries.
 function topic_tree_count_words($text) {
    if (empty($text)) {
        return 0;
    }
    // Split the text into words using spaces and filter out empty entries
    $words = array_filter(explode(' ', trim($text)));
    return count($words);
}

//ANCHOR - Summarizes a chunk of text using OpenAI's GPT-4 API, returning a concise 3-sentence summary.
function topic_tree_summarize_chunk($text) {
    global $openai_api_key;
    if (empty($openai_api_key)) {
        error_log("Topic Tree: API key is unassigned in topic_tree_summarize_chunk");
        return 'Error: API key not configured';
    }
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => "Bearer $openai_api_key",
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode([
            'model' => 'gpt-4',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a technical writer tasked with creating concise, accurate, and insightful summaries. Provide a 3-sentence summary that captures the main idea, key details, and purpose of the content.'],
                ['role' => 'user', 'content' => "Summarize this content in 3 sentences, focusing on the core topic, key points, and intended audience or purpose:\n\n$text"]
            ],
            'temperature' => 0.6,
            'max_tokens' => 600
        ]),
        'timeout' => 300 // Set to 5 minutes (300 seconds)
    ]);
    if (is_wp_error($response)) {
        error_log("Topic Tree: API request failed in summarize_chunk: " . $response->get_error_message());
        return 'Error: API failed';
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($body['choices'][0]['message']['content'])) {
        error_log("Topic Tree: Bad response in summarize_chunk");
        return 'Error: Bad response';
    }
    return trim($body['choices'][0]['message']['content']);
}

//ANCHOR - Summarizes text using GPT-4, splitting into chunks if necessary, and caches the result.
function topic_tree_summarize_text($text, $force_refresh = false) {
    global $openai_api_key;
    if (empty($openai_api_key)) {
        error_log("Topic Tree: API key is unassigned in topic_tree_summarize_text");
        return 'Error: API key not configured';
    }
    $cache_key = 'tt_summary_' . md5($text);
    $tokens_cache_key = $cache_key . '_tokens';
    if (!$force_refresh && ($cached = get_transient($cache_key)) !== false) {
        return $cached;
    }
    $max_chars = 20000;
    $summary = '';
    $total_tokens_data = ['input' => 0, 'output' => 0, 'total' => 0];

    if (strlen($text) > $max_chars) {
        $chunks = str_split($text, $max_chars);
        foreach ($chunks as $chunk) {
            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => "Bearer $openai_api_key",
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'model' => 'gpt-4',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a technical writer tasked with creating concise, accurate, and insightful summaries. Provide a 3-sentence summary that captures the main idea, key details, and purpose of the content.'],
                        ['role' => 'user', 'content' => "Summarize this content in 3 sentences, focusing on the core topic, key points, and intended audience or purpose:\n\n$chunk"]
                    ],
                    'temperature' => 0.6,
                    'max_tokens' => 600
                ]),
                'timeout' => 300
            ]);
            if (is_wp_error($response)) {
                error_log("Topic Tree: API request failed in summarize_text chunk: " . $response->get_error_message());
                $summary .= 'Error: API failed ';
                continue;
            }
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!isset($body['choices'][0]['message']['content'])) {
                error_log("Topic Tree: Bad response in summarize_text chunk");
                $summary .= 'Error: Bad response ';
                continue;
            }
            $chunk_summary = trim($body['choices'][0]['message']['content']);
            $summary .= $chunk_summary . ' ';
            $total_tokens_data['input'] += $body['usage']['prompt_tokens'] ?? 0;
            $total_tokens_data['output'] += $body['usage']['completion_tokens'] ?? 0;
            $total_tokens_data['total'] += $body['usage']['total_tokens'] ?? 0;
        }
        $summary = trim($summary);
    } else {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => "Bearer $openai_api_key",
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'model' => 'gpt-4',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a technical writer tasked with creating concise, accurate, and insightful summaries. Provide a 3-sentence summary that captures the main idea, key details, and purpose of the content.'],
                    ['role' => 'user', 'content' => "Summarize this content in 3 sentences, focusing on the core topic, key points, and intended audience or purpose:\n\n$text"]
                ],
                'temperature' => 0.6,
                'max_tokens' => 600
            ]),
            'timeout' => 300
        ]);
        if (is_wp_error($response)) {
            error_log("Topic Tree: API request failed in summarize_text: " . $response->get_error_message());
            return 'Error: API failed';
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['choices'][0]['message']['content'])) {
            error_log("Topic Tree: Bad response in summarize_text");
            return 'Error: Bad response';
        }
        $summary = trim($body['choices'][0]['message']['content']);
        $total_tokens_data = [
            'input' => $body['usage']['prompt_tokens'] ?? 0,
            'output' => $body['usage']['completion_tokens'] ?? 0,
            'total' => $body['usage']['total_tokens'] ?? 0
        ];
    }

    error_log("Topic Tree: Tokens for $cache_key - Input: {$total_tokens_data['input']}, Output: {$total_tokens_data['output']}, Total: {$total_tokens_data['total']}");
    set_transient($cache_key, $summary, WEEK_IN_SECONDS);
    set_transient($tokens_cache_key, $total_tokens_data, WEEK_IN_SECONDS);
    return $summary;
}

//ANCHOR - Generates an overall website summary from posts and pages, limiting to 100 words.
function topic_tree_generate_overall_summary($posts, $pages, $force_refresh = false) {
    global $openai_api_key;
    if (empty($openai_api_key)) {
        error_log("Topic Tree: API key is unassigned in topic_tree_generate_overall_summary");
        return 'Error: API key not configured';
    }
    $all_content = implode(' ', array_map(function ($p) {
        return ($p->post_title ?: '') . ' ' . ($p->post_content ?: '');
    }, array_merge($posts, $pages)));
    $cache_key = 'tt_overall_summary_' . md5($all_content);
    $tokens_cache_key = $cache_key . '_tokens';
    if (!$force_refresh && ($cached = get_transient($cache_key)) !== false) {
        return $cached;
    }
    $max_chars = 20000;
    $summary = '';
    $total_tokens_data = ['input' => 0, 'output' => 0, 'total' => 0];

    if (strlen($all_content) > $max_chars) {
        $chunks = str_split($all_content, $max_chars);
        foreach ($chunks as $chunk) {
            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => "Bearer $openai_api_key",
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'model' => 'gpt-4',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a technical writer tasked with creating concise, accurate, and insightful summaries.'],
                        ['role' => 'user', 'content' => "Provide a 3-sentence summary of this entire website content in 100 words or less, focusing on the core topics, key points, and overall purpose:\n\n$chunk"]
                    ],
                    'temperature' => 0.6,
                    'max_tokens' => 150 // Reduced to target ~100 words (1 word ≈ 1.3 tokens)
                ]),
                'timeout' => 300 // Set to 5 minutes (300 seconds)
            ]);
            if (is_wp_error($response)) {
                error_log("Topic Tree: API request failed in overall_summary chunk: " . $response->get_error_message());
                $chunk_summary = 'Error: API failed';
            } else {
                $body = json_decode(wp_remote_retrieve_body($response), true);
                $chunk_summary = isset($body['choices'][0]['message']['content']) ? trim($body['choices'][0]['message']['content']) : 'Error: Bad response';
                $total_tokens_data['input'] += $body['usage']['prompt_tokens'] ?? 0;
                $total_tokens_data['output'] += $body['usage']['completion_tokens'] ?? 0;
                $total_tokens_data['total'] += $body['usage']['total_tokens'] ?? 0;
            }
            $summary .= $chunk_summary . ' ';
        }
        $summary = trim($summary);
    } else {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => "Bearer $openai_api_key",
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'model' => 'gpt-4',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a technical writer tasked with creating concise, accurate, and insightful summaries.'],
                    ['role' => 'user', 'content' => "Provide a 3-sentence summary of this entire website content in 100 words or less, focusing on the core topics, key points, and overall purpose:\n\n$all_content"]
                ],
                'temperature' => 0.6,
                'max_tokens' => 150 // Reduced to target ~100 words (1 word ≈ 1.3 tokens)
            ]),
            'timeout' => 300 // Set to 5 minutes (300 seconds)
        ]);
        if (is_wp_error($response)) {
            error_log("Topic Tree: API request failed in overall_summary: " . $response->get_error_message());
            $summary = 'Error: API failed';
        } else {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $summary = isset($body['choices'][0]['message']['content']) ? trim($body['choices'][0]['message']['content']) : 'Error: Bad response';
            $total_tokens_data['input'] = $body['usage']['prompt_tokens'] ?? 0;
            $total_tokens_data['output'] = $body['usage']['completion_tokens'] ?? 0;
            $total_tokens_data['total'] = $body['usage']['total_tokens'] ?? 0;
        }
    }

    // Check if the summary exceeds 100 words and shorten if necessary
    $word_count = topic_tree_count_words($summary);
    $attempts = 0;
    $max_attempts = 3;

    while ($word_count > 100 && $attempts < $max_attempts) {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => "Bearer $openai_api_key",
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'model' => 'gpt-4',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a technical writer tasked with creating concise, accurate, and insightful summaries.'],
                    ['role' => 'user', 'content' => "Summarize this text in 100 words or less, keeping it to 3 sentences:\n\n$summary"]
                ],
                'temperature' => 0.6,
                'max_tokens' => 150
            ]),
            'timeout' => 300
        ]);
        if (is_wp_error($response)) {
            error_log("Topic Tree: API request failed in overall_summary shortening: " . $response->get_error_message());
            break;
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['choices'][0]['message']['content'])) {
            error_log("Topic Tree: Bad response in overall_summary shortening");
            break;
        }
        $summary = trim($body['choices'][0]['message']['content']);
        $total_tokens_data['input'] += $body['usage']['prompt_tokens'] ?? 0;
        $total_tokens_data['output'] += $body['usage']['completion_tokens'] ?? 0;
        $total_tokens_data['total'] += $body['usage']['total_tokens'] ?? 0;
        $word_count = topic_tree_count_words($summary);
        $attempts++;
    }

    // Fallback: truncate to 100 words if still too long
    if ($word_count > 100) {
        $words = array_slice(explode(' ', trim($summary)), 0, 100);
        $summary = implode(' ', $words) . '...';
        // Adjust output tokens for truncated summary
        $total_tokens_data['output'] = ceil(strlen($summary) / 4); // Rough estimate: 1 token ≈ 4 characters
        $total_tokens_data['total'] = $total_tokens_data['input'] + $total_tokens_data['output'];
    }

    set_transient($cache_key, $summary, WEEK_IN_SECONDS);
    set_transient($tokens_cache_key, $total_tokens_data, WEEK_IN_SECONDS);
    return $summary;
}

//ANCHOR - Groups summaries into topic categories using GPT-4 and returns JSON-encoded result.
function topic_tree_extract_and_group_topics($summaries, $section, $force_refresh = false) {
    global $openai_api_key;

    if (!is_array($summaries) || empty($summaries)) {
        return json_encode([]);
    }

    $cache_key = 'tt_grouped_' . md5(json_encode($summaries) . $section);
    $cached = get_transient($cache_key);
    if ($cached !== false && !$force_refresh) {
        return $cached;
    }

    if (empty($openai_api_key)) {
        return json_encode(['error' => 'API key not configured']);
    }

    // Create a lookup array to preserve all fields, including tokens
    $summaries_lookup = [];
    foreach ($summaries as $s) {
        $summaries_lookup[$s['id']] = $s;
    }

    // Prepare data for the API, including only the fields needed for grouping
    $summaries_data = array_map(function($s) {
        return [
            'id' => $s['id'] ?? 0,
            'title' => $s['title'] ?? 'Untitled',
            'summary' => $s['summary'] ?? 'No summary',
            'url' => $s['url'] ?? '#',
            'date' => $s['date'] ?? 'Unknown Date',
            'type' => $s['type'] ?? 'unknown'
        ];
    }, $summaries);
    $summaries_text = json_encode($summaries_data);

    $system_prompt = 'You are a content analyst. Return your response as a valid JSON object.';
    $instructions = "Group these " . count($summaries) . " items (a mix of posts and pages) into detailed, specific topic categories based on content similarity, aiming for 5-7 distinct groups unless overlap is unavoidable (e.g., 'Machine Learning', 'Web Security', 'Frontend Development'). " .
        "Each category can contain both posts and pages. Ensure every item is included without omission. Avoid overly broad labels like 'Technology' unless no finer distinction applies. " .
        "Format the response as a JSON object where keys are category names and values are arrays of objects with 'id', 'title', 'summary', 'url', 'date', and 'type' properties, preserving all provided fields.";

    if (strlen($summaries_text) > 10000) {
        $batches = str_split($summaries_text, 9000);
        $grouped = [];
        foreach ($batches as $batch_index => $batch) {
            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => "Bearer $openai_api_key",
                    'Content-Type' => 'application/json'
                ],
                'body' => json_encode([
                    'model' => 'gpt-4',
                    'messages' => [
                        ['role' => 'system', 'content' => $system_prompt],
                        ['role' => 'user', 'content' => "$instructions\n\nBatch " . ($batch_index + 1) . " of " . count($batches) . ":\n$batch"]
                    ],
                    'temperature' => 0.8,
                    'max_tokens' => 4500
                ]),
                'timeout' => 300 // Set to 5 minutes (300 seconds)
            ]);

            if (is_wp_error($response)) {
                error_log("Topic Tree: Batch grouping API request failed: " . $response->get_error_message());
                continue;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $content = $body['choices'][0]['message']['content'] ?? '';
            $cleaned_json = topic_tree_clean_json_response($content);
            $batch_grouped = json_decode($cleaned_json, true) ?? [];
            $grouped = array_merge_recursive($grouped, $batch_grouped);
        }

        // Merge back the preserved fields (tokens, stale, etc.)
        foreach ($grouped as $category => &$items) {
            foreach ($items as &$item) {
                $id = $item['id'];
                if (isset($summaries_lookup[$id])) {
                    $original = $summaries_lookup[$id];
                    $item['tokens'] = $original['tokens'] ?? ['input' => 0, 'output' => 0, 'total' => 0];
                    $item['stale'] = $original['stale'] ?? false;
                    $item['created_years'] = $original['created_years'] ?? 0;
                    $item['last_edited_years'] = $original['last_edited_years'] ?? 0;
                    $item['last_edited_months'] = $original['last_edited_months'] ?? 0;
                }
            }
            unset($item); // Unset reference to avoid issues
        }
        unset($items); // Unset reference to avoid issues

        $grouped_json = json_encode($grouped);
    } else {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => "Bearer $openai_api_key",
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'model' => 'gpt-4',
                'messages' => [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user', 'content' => "$instructions\n\n$summaries_text"]
                ],
                'temperature' => 0.8,
                'max_tokens' => 4500
            ]),
            'timeout' => 300 // Set to 5 minutes (300 seconds)
        ]);

        if (is_wp_error($response)) {
            error_log("Topic Tree: Grouping API request failed: " . $response->get_error_message());
            return json_encode(topic_tree_fallback_grouping($summaries));
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['choices'][0]['message']['content'])) {
            error_log("Topic Tree: Unexpected grouping API response: " . wp_remote_retrieve_body($response));
            return json_encode(topic_tree_fallback_grouping($summaries));
        }

        $content = $body['choices'][0]['message']['content'];
        $cleaned_json = topic_tree_clean_json_response($content);
        $grouped = json_decode($cleaned_json, true);

        // Merge back the preserved fields (tokens, stale, etc.)
        foreach ($grouped as $category => &$items) {
            foreach ($items as &$item) {
                $id = $item['id'];
                if (isset($summaries_lookup[$id])) {
                    $original = $summaries_lookup[$id];
                    $item['tokens'] = $original['tokens'] ?? ['input' => 0, 'output' => 0, 'total' => 0];
                    $item['stale'] = $original['stale'] ?? false;
                    $item['created_years'] = $original['created_years'] ?? 0;
                    $item['last_edited_years'] = $original['last_edited_years'] ?? 0;
                    $item['last_edited_months'] = $original['last_edited_months'] ?? 0;
                }
            }
            unset($item); // Unset reference to avoid issues
        }
        unset($items); // Unset reference to avoid issues

        $grouped_json = json_encode($grouped);
    }

    $validated_json = json_decode($grouped_json, true);
    if ($validated_json === null) {
        error_log("Topic Tree: Failed to parse grouping JSON: " . json_last_error_msg() . " - Raw: " . substr($cleaned_json, 0, 500));
        $grouped = topic_tree_fallback_grouping($summaries);
        $grouped_json = json_encode($grouped);
    }

    $prompt_tokens = $body['usage']['prompt_tokens'] ?? 0;
    $completion_tokens = $body['usage']['completion_tokens'] ?? 0;
    $total_tokens = $body['usage']['total_tokens'] ?? ($prompt_tokens + $completion_tokens);

    set_transient($cache_key, $grouped_json, WEEK_IN_SECONDS);
    set_transient($cache_key . '_tokens', [
        'input' => $prompt_tokens,
        'output' => $completion_tokens,
        'total' => $total_tokens
    ], WEEK_IN_SECONDS);
    return $grouped_json;
}

//ANCHOR - Cleans and extracts valid JSON from an API response string.
function topic_tree_clean_json_response($content) {
    if (preg_match('/```(?:json|javascript)?\s*([\s\S]*?)\s*```/s', $content, $matches)) {
        $content = $matches[1];
    }

    $first_brace = strpos($content, '{');
    $last_brace = strrpos($content, '}');
    if ($first_brace !== false && $last_brace !== false && $last_brace > $first_brace) {
        $content = substr($content, $first_brace, $last_brace - $first_brace + 1);
    }

    $content = preg_replace('/,\s*}/', '}', $content);
    $content = preg_replace('/,\s*\]/', ']', $content);
    return trim($content);
}

//ANCHOR - Provides a fallback grouping mechanism based on keyword detection if API fails.
function topic_tree_fallback_grouping($summaries) {
    $grouped = [];
    foreach ($summaries as $item) {
        $summary = strtolower($item['summary'] ?? '');
        $title = strtolower($item['title'] ?? '');
        $topic = 'Miscellaneous';

        if (strpos($summary, 'ai') !== false || strpos($title, 'artificial') !== false) {
            $topic = 'Artificial Intelligence';
        } elseif (strpos($summary, 'code') !== false || strpos($title, 'programming') !== false) {
            $topic = 'Software Development';
        } elseif (strpos($summary, 'security') !== false || strpos($title, 'cyber') !== false) {
            $topic = 'Cybersecurity';
        } elseif (strpos($summary, 'blog') !== false || strpos($title, 'writing') !== false) {
            $topic = 'Content Creation';
        } elseif (strpos($summary, 'health') !== false || strpos($title, 'health') !== false) {
            $topic = 'Health and Wellness';
        } elseif (strpos($summary, 'travel') !== false || strpos($title, 'travel') !== false) {
            $topic = 'Travel';
        } elseif (strpos($summary, 'web') !== false || strpos($title, 'web') !== false) {
            $topic = 'Web Development';
        }

        $grouped[$topic][] = [
            'id' => $item['id'] ?? 0,
            'title' => $item['title'] ?? 'Untitled',
            'summary' => $item['summary'] ?? 'No summary',
            'url' => $item['url'] ?? '#',
            'date' => $item['date'] ?? 'Unknown Date',
            'type' => $item['type'] ?? 'unknown',
            'tokens' => $item['tokens'] ?? ['input' => 0, 'output' => 0, 'total' => 0],
            'stale' => $item['stale'] ?? false,
            'created_years' => $item['created_years'] ?? 0,
            'last_edited_years' => $item['last_edited_years'] ?? 0,
            'last_edited_months' => $item['last_edited_months'] ?? 0
        ];
    }
    return $grouped;
}

//ANCHOR - Generates SEO suggestions for a given item using GPT-4 and caches
function topic_tree_generate_seo_suggestions($item, $force_refresh = false) {
    global $openai_api_key;

    $cache_key = 'tt_seo_' . md5(json_encode($item));
    $cached = get_transient($cache_key);

    if ($cached !== false && !$force_refresh) {
        return $cached;
    }

    if (empty($openai_api_key)) {
        return 'Error: API key not configured';
    }

    $content_text = sprintf("%s (%s): %s", 
        $item['title'] ?? 'Untitled', 
        $item['type'] ?? 'unknown', 
        $item['summary'] ?? 'No summary'
    );

    $system_prompt = 'You are an SEO expert. Provide concise, actionable SEO suggestions.';
    $instructions = "Analyze the following content and provide 3-5 specific SEO suggestions (e.g., target keywords, meta descriptions, internal linking) based on the title and summary. Return your response as a JSON array of strings, where each string is a complete suggestion (e.g., ['1. Target Keywords: Use keywords...', '2. Meta Description: Write a compelling...']).";

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => "Bearer $openai_api_key",
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode([
            'model' => 'gpt-4',
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => "$instructions\n\n$content_text"]
            ],
            'temperature' => 0.5,
            'max_tokens' => 500
        ]),
        'timeout' => 300
    ]);

    if (is_wp_error($response)) {
        error_log("Topic Tree: SEO API request failed: " . $response->get_error_message());
        return ['Error: Failed to fetch SEO suggestions'];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($body['choices'][0]['message']['content'])) {
        error_log("Topic Tree: Unexpected SEO API response: " . wp_remote_retrieve_body($response));
        return ['Error: Invalid API response'];
    }

    $suggestions_content = trim($body['choices'][0]['message']['content']);
    $suggestions = json_decode($suggestions_content, true);

    // Fallback to plain text parsing if JSON decoding fails
    if (!is_array($suggestions)) {
        $lines = array_filter(explode("\n", $suggestions_content));
        $suggestions = array_map('trim', $lines);
    }

    set_transient($cache_key, $suggestions, WEEK_IN_SECONDS);
    return $suggestions;
}

//ANCHOR Generates hybrid insights for a group of items, combining stats and AI summarization.
function topic_tree_hybrid_group_insights($group_items, $total_items, $force_refresh = false) {
    $cache_key = 'tt_hybrid_insights_' . md5(json_encode($group_items));
    if ($cached = get_transient($cache_key) && !$force_refresh) {
        return $cached;
    }

    $titles = implode(' ', array_column($group_items, 'title'));
    $summaries = implode(' ', array_column($group_items, 'summary'));
    $all_text = "$titles $summaries";
    $words = array_count_values(str_word_count(strtolower($all_text), 1));
    $stop_words = ['the', 'and', 'to', 'of', 'in', 'a', 'is'];
    $words = array_diff_key($words, array_flip($stop_words));
    arsort($words);
    $top_words = implode(', ', array_slice(array_keys($words), 0, 4));

    $count = count($group_items);
    $percent = round(($count / $total_items) * 100, 1);
    $types = implode(' and ', array_unique(array_column($group_items, 'type')));
    $dates = array_map(fn($item) => strtotime($item['date']), $group_items);
    $avg_year = date('Y', array_sum($dates) / count($dates));
    $is_recent = max($dates) > strtotime('-1 year') ? 'recent' : 'established';

    $draft = "This group of $count $types items ($percent% of site content) focuses on topics like $top_words, based on titles and summaries. It reflects $is_recent content with an average publish year of $avg_year.";

    $prompt = "Refine this into a concise summary (1-2 sentences) and a 3-line explanation of why this group is important to the website for a WordPress developer. Focus solely on its significance to the site, avoiding specific improvement suggestions: \"$draft\"";
    $insights = topic_tree_summarize_text($prompt, $force_refresh);
    set_transient($cache_key, $insights, WEEK_IN_SECONDS);
    return $insights;
}

function topic_tree_summarize_content($force_refresh = false) {
    $all_summaries = [];

    // Comprehensive stop word list for keyword extraction
    $stop_words = [
        'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
        'about', 'above', 'after', 'again', 'against', 'all', 'am', 'an', 'and', 'any', 'are', 'aren\'t', 'as', 'at',
        'be', 'because', 'been', 'before', 'being', 'below', 'between', 'both', 'but', 'by', 'can', 'cannot', 'could', 'couldn\'t',
        'did', 'didn\'t', 'do', 'does', 'doesn\'t', 'doing', 'don\'t', 'down', 'during', 'each', 'few', 'for', 'from', 'further',
        'had', 'hadn\'t', 'has', 'hasn\'t', 'have', 'haven\'t', 'having', 'he', 'he\'d', 'he\'ll', 'he\'s', 'her', 'here', 'here\'s',
        'hers', 'herself', 'him', 'himself', 'his', 'how', 'how\'s', 'i\'d', 'i\'ll', 'i\'m', 'i\'ve', 'if', 'in', 'into', 'is',
        'isn\'t', 'it', 'it\'s', 'its', 'itself', 'let\'s', 'me', 'more', 'most', 'mustn\'t', 'my', 'myself', 'no', 'nor', 'not', 'of',
        'off', 'on', 'once', 'only', 'or', 'other', 'ought', 'our', 'ours', 'ourselves', 'out', 'over', 'own', 'same', 'shan\'t', 'she',
        'she\'d', 'she\'ll', 'she\'s', 'should', 'shouldn\'t', 'so', 'some', 'such', 'than', 'that', 'that\'s', 'the', 'their', 'theirs',
        'them', 'themselves', 'then', 'there', 'there\'s', 'these', 'they', 'they\'d', 'they\'ll', 'they\'re', 'they\'ve', 'this', 'those',
        'through', 'to', 'too', 'under', 'until', 'up', 'very', 'was', 'wasn\'t', 'we', 'we\'d', 'we\'ll', 'we\'re', 'we\'ve', 'were',
        'weren\'t', 'what', 'what\'s', 'when', 'when\'s', 'where', 'where\'s', 'which', 'while', 'who', 'who\'s', 'whom', 'why', 'why\'s',
        'with', 'won\'t', 'would', 'wouldn\'t', 'you', 'you\'d', 'you\'ll', 'you\'re', 'you\'ve', 'your', 'yours', 'yourself', 'yourselves'
    ];

    // Debug: Check if posts are being fetched
    $posts = get_posts(['numberposts' => -1, 'post_status' => 'publish']);
    error_log("Topic Tree Debug: Number of posts fetched - " . count($posts));
    if (empty($posts)) {
        error_log("Topic Tree Debug: No posts found. Check post status or permissions.");
    }

    foreach ($posts as $post) {
        $content = $post->post_content;
        $summary = topic_tree_summarize_text($content, $force_refresh);

        // Keyword extraction
        $content_clean = strip_tags($content);
        $content_clean = preg_replace('/[^\w\s]/', ' ', $content_clean);
        $content_clean = strtolower($content_clean);
        $content_clean = preg_replace('/\s+/', ' ', trim($content_clean));
        $words = explode(' ', $content_clean);
        $words = array_filter($words);
        $filtered_words = array_diff($words, $stop_words);
        $total_words_for_density = count($filtered_words);

        $word_count = array_count_values($words);
        foreach ($stop_words as $stop_word) {
            unset($word_count[$stop_word]);
        }
        $word_count = array_filter($word_count, function($count) {
            return $count >= 2;
        });

        $bigrams = [];
        for ($i = 0; $i < count($words) - 1; $i++) {
            $bigram = $words[$i] . ' ' . $words[$i + 1];
            if (in_array($words[$i], $stop_words) || in_array($words[$i + 1], $stop_words)) {
                continue;
            }
            $bigrams[] = $bigram;
        }
        $bigram_count = array_count_values($bigrams);
        $bigram_count = array_filter($bigram_count, function($count) {
            return $count >= 2;
        });

        $keywords = array_merge($word_count, $bigram_count);
        arsort($keywords);
        $keywords = array_slice($keywords, 0, 5);

        $keyword_data = [];
        foreach ($keywords as $keyword => $count) {
            $density = $total_words_for_density > 0 ? ($count / $total_words_for_density) * 100 : 0;
            $density = round($density, 1);
            $keyword_data[$keyword] = [
                'count' => $count,
                'density' => $density
            ];
        }

        $summary_cache_key = 'tt_summary_' . md5($content);
        $tokens_data = get_transient($summary_cache_key . '_tokens') ?: ['input' => 0, 'output' => 0, 'total' => 0];
        // Ensure token values are numeric
        $tokens_data['input'] = isset($tokens_data['input']) ? (int)$tokens_data['input'] : 0;
        $tokens_data['output'] = isset($tokens_data['output']) ? (int)$tokens_data['output'] : 0;
        $tokens_data['total'] = isset($tokens_data['total']) ? (int)$tokens_data['total'] : 0;

        // Calculate if the item is stale (older than 2 years based on creation date)
        $created_date = strtotime($post->post_date);
        $last_edited_date = strtotime($post->post_modified);
        $is_stale = (time() - $created_date) > ((4/12) * YEAR_IN_SECONDS);

        // Calculate age for hover message
        $created_age_seconds = time() - $created_date;
        $created_years = floor($created_age_seconds / YEAR_IN_SECONDS);
        $last_edited_age_seconds = time() - $last_edited_date;
        $last_edited_years = floor($last_edited_age_seconds / YEAR_IN_SECONDS);
        $last_edited_months = floor(($last_edited_age_seconds % YEAR_IN_SECONDS) / (30 * 24 * 60 * 60)); // Approximate months

        $all_summaries[] = [
            'id' => $post->ID,
            'title' => $post->post_title ?: 'Untitled',
            'summary' => is_string($summary) ? $summary : 'Error: Summary failed',
            'keywords' => $keyword_data,
            'total_words' => topic_tree_count_words(strip_tags($content)),
            'url' => get_permalink($post->ID) ?: '#',
            'date' => get_the_date('F j, Y', $post->ID) ?: 'Unknown Date',
            'type' => 'post',
            'tokens' => $tokens_data,
            'stale' => $is_stale,
            'created_years' => $created_years,
            'last_edited_years' => $last_edited_years,
            'last_edited_months' => $last_edited_months
        ];
        error_log("Topic Tree: Post {$post->ID} tokens - Input: {$tokens_data['input']}, Output: {$tokens_data['output']}, Total: {$tokens_data['total']}");
    }

    // Debug: Check if pages are being fetched
    $pages = get_pages();
    error_log("Topic Tree Debug: Number of pages fetched - " . count($pages));
    if (empty($pages)) {
        error_log("Topic Tree Debug: No pages found. Check page status or permissions.");
    }

    foreach ($pages as $page) {
        $content = $page->post_content;
        $summary = topic_tree_summarize_text($content, $force_refresh);

        // Keyword extraction
        $content_clean = strip_tags($content);
        $content_clean = preg_replace('/[^\w\s]/', ' ', $content_clean);
        $content_clean = strtolower($content_clean);
        $content_clean = preg_replace('/\s+/', ' ', trim($content_clean));
        $words = explode(' ', $content_clean);
        $words = array_filter($words);
        $filtered_words = array_diff($words, $stop_words);
        $total_words_for_density = count($filtered_words);

        $word_count = array_count_values($words);
        foreach ($stop_words as $stop_word) {
            unset($word_count[$stop_word]);
        }
        $word_count = array_filter($word_count, function($count) {
            return $count >= 2;
        });

        $bigrams = [];
        for ($i = 0; $i < count($words) - 1; $i++) {
            $bigram = $words[$i] . ' ' . $words[$i + 1];
            if (in_array($words[$i], $stop_words) || in_array($words[$i + 1], $stop_words)) {
                continue;
            }
            $bigrams[] = $bigram;
        }
        $bigram_count = array_count_values($bigrams);
        $bigram_count = array_filter($bigram_count, function($count) {
            return $count >= 2;
        });

        $keywords = array_merge($word_count, $bigram_count);
        arsort($keywords);
        $keywords = array_slice($keywords, 0, 5);

        $keyword_data = [];
        foreach ($keywords as $keyword => $count) {
            $density = $total_words_for_density > 0 ? ($count / $total_words_for_density) * 100 : 0;
            $density = round($density, 1);
            $keyword_data[$keyword] = [
                'count' => $count,
                'density' => $density
            ];
        }

        $summary_cache_key = 'tt_summary_' . md5($content);
        $tokens_data = get_transient($summary_cache_key . '_tokens') ?: ['input' => 0, 'output' => 0, 'total' => 0];
        // Ensure token values are numeric
        $tokens_data['input'] = isset($tokens_data['input']) ? (int)$tokens_data['input'] : 0;
        $tokens_data['output'] = isset($tokens_data['output']) ? (int)$tokens_data['output'] : 0;
        $tokens_data['total'] = isset($tokens_data['total']) ? (int)$tokens_data['total'] : 0;

        // Calculate if the item is stale (older than 2 years based on creation date)
        $created_date = strtotime($page->post_date);
        $last_edited_date = strtotime($page->post_modified);
        $is_stale = (time() - $created_date) > (2 * YEAR_IN_SECONDS);

        // Calculate age for hover message
        $created_age_seconds = time() - $created_date;
        $created_years = floor($created_age_seconds / YEAR_IN_SECONDS);
        $last_edited_age_seconds = time() - $last_edited_date;
        $last_edited_years = floor($last_edited_age_seconds / YEAR_IN_SECONDS);
        $last_edited_months = floor(($last_edited_age_seconds % YEAR_IN_SECONDS) / (30 * 24 * 60 * 60)); // Approximate months

        $all_summaries[] = [
            'id' => $page->ID,
            'title' => $page->post_title ?: 'Untitled',
            'summary' => is_string($summary) ? $summary : 'Error: Summary failed',
            'keywords' => $keyword_data,
            'total_words' => topic_tree_count_words(strip_tags($content)),
            'url' => get_permalink($page->ID) ?: '#',
            'date' => get_the_date('F j, Y', $page->ID) ?: 'Unknown Date',
            'type' => 'page',
            'tokens' => $tokens_data,
            'stale' => $is_stale,
            'created_years' => $created_years,
            'last_edited_years' => $last_edited_years,
            'last_edited_months' => $last_edited_months
        ];
        error_log("Topic Tree: Page {$page->ID} tokens - Input: {$tokens_data['input']}, Output: {$tokens_data['output']}, Total: {$tokens_data['total']}");
    }

    // Debug: Check the final summaries array
    error_log("Topic Tree Debug: Total summaries generated - " . count($all_summaries));

    return $all_summaries;
}
?>